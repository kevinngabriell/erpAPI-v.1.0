<?php

// Notification WebSocket daemon — CLI-only, not part of the HTTP router.
// Run with: php v2/notification/ws-server.php start
//
// New category of artifact for this repo: every other file here is a
// stateless per-request PHP-FPM dispatch target. This is a long-running
// process instead, meant to be kept alive by a systemd unit
// (Restart=always) — see v2/docs/migrations/v23_notification_schema.md's
// post-migration checklist for the unit + Nginx wss:// proxy setup, neither
// of which exists yet and isn't installed by this script.
//
// websocket://0.0.0.0:{WS_PORT} is the one and only Workerman Worker here —
// client-facing, requires the same JWT issued by v2/helpers/jwt.php as its
// first message. count=1, so exactly one process holds every live
// connection in the in-memory ConnectionRegistry below.
//
// The internal publish listener (127.0.0.1:{WS_PUBLISH_PORT}, used by
// v2/helpers/notification.php's pushWebSocketEvent() from ordinary PHP-FPM
// requests) is deliberately NOT a second Worker instance — Workerman forks a
// separate child process per Worker object even at count=1, and a second
// process cannot see this process's static ConnectionRegistry. Instead the
// publish socket is a plain stream_socket_server() bound manually inside
// onWorkerStart and driven by Worker::$globalEvent, so it runs as a second
// listener inside this same single process/memory space.

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/jwt.php';

use Workerman\Connection\TcpConnection;
use Workerman\Worker;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['status_code' => 403, 'status_message' => 'ws-server.php is a CLI-only daemon, not an HTTP endpoint', 'data' => []]);
    exit;
}

class ConnectionRegistry {
    /** @var array<string, array<int, TcpConnection>> user_id => [connection_id => TcpConnection] */
    public static array $byUser = [];

    public static function add(string $userId, TcpConnection $connection): void {
        self::$byUser[$userId][$connection->id] = $connection;
    }

    public static function remove(TcpConnection $connection): void {
        $userId = $connection->authUserId ?? null;
        if ($userId !== null) {
            unset(self::$byUser[$userId][$connection->id]);
            if (empty(self::$byUser[$userId])) {
                unset(self::$byUser[$userId]);
            }
        }
    }

    public static function send(string $userId, array $frame): void {
        foreach (self::$byUser[$userId] ?? [] as $connection) {
            $connection->send(json_encode($frame));
        }
    }
}

// pid/log files must live outside the repo — the repo dir's owner flips
// between deploys (`chown -R www-data`) and `git clean -fd` on every deploy
// would delete them anyway.
Worker::$pidFile = '/var/run/erp-notification-ws/ws-server.pid';
Worker::$logFile = '/var/log/erp-notification-ws/ws-server.log';

$wsWorker        = new Worker('websocket://0.0.0.0:' . WS_PORT);
$wsWorker->count = 1;
$wsWorker->name  = 'notification-ws';

$wsWorker->onMessage = function (TcpConnection $connection, $data) {
    $message = json_decode($data, true);

    if (!is_array($message)) {
        $connection->send(json_encode(['event' => 'error', 'payload' => ['message' => 'Malformed message']]));
        return;
    }

    if (($message['type'] ?? '') === 'auth') {
        try {
            $claims = JWT::decode((string)($message['token'] ?? ''));
        } catch (Exception $e) {
            $connection->send(json_encode(['event' => 'auth:error', 'payload' => ['message' => $e->getMessage()]]));
            $connection->close();
            return;
        }

        $userId = $claims['user_id'] ?? null;
        if (!$userId) {
            $connection->send(json_encode(['event' => 'auth:error', 'payload' => ['message' => 'Token missing user_id']]));
            $connection->close();
            return;
        }

        $connection->authUserId = $userId;
        ConnectionRegistry::add($userId, $connection);
        $connection->send(json_encode(['event' => 'auth:ok', 'payload' => ['user_id' => $userId]]));
        return;
    }

    $connection->send(json_encode(['event' => 'error', 'payload' => ['message' => 'Unknown message type — send {"type":"auth","token":"..."} first']]));
};

$wsWorker->onClose = function (TcpConnection $connection) {
    ConnectionRegistry::remove($connection);
};

$wsWorker->onWorkerStart = function () {
    $publishSocket = stream_socket_server('tcp://127.0.0.1:' . WS_PUBLISH_PORT, $errno, $errstr);
    if (!$publishSocket) {
        Worker::log("Failed to bind publish socket on " . WS_PUBLISH_PORT . ": $errstr");
        return;
    }
    stream_set_blocking($publishSocket, false);

    Worker::$globalEvent->onReadable($publishSocket, function ($publishSocket) {
        $client = @stream_socket_accept($publishSocket, 0);
        if (!$client) return;
        stream_set_blocking($client, false);

        Worker::$globalEvent->onReadable($client, function ($client) {
            $data = @fread($client, 65536);
            if ($data === '' || $data === false) {
                Worker::$globalEvent->offReadable($client);
                @fclose($client);
                return;
            }

            foreach (explode("\n", trim($data)) as $line) {
                if ($line === '') continue;
                $message = json_decode($line, true);
                if (is_array($message) && isset($message['user_id'], $message['event'])) {
                    ConnectionRegistry::send((string)$message['user_id'], [
                        'event'   => $message['event'],
                        'payload' => $message['payload'] ?? null,
                    ]);
                }
            }

            Worker::$globalEvent->offReadable($client);
            @fclose($client);
        });
    });
};

Worker::runAll();
