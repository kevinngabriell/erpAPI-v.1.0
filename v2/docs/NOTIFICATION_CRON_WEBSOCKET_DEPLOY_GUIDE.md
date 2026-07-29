# Notification Module — Crontab & WebSocket Daemon Setup (Ubuntu VPS)

This document covers the two pieces of server infra the notification module
needs that **are not installed by the deploy pipeline** (`.github/workflows/deploy.yml`
only does `git reset --hard` + `composer install` — no service restarts, no
crontab management). Both are flagged as outstanding in
[`v2/docs/migrations/v23_notification_schema.md`](migrations/v23_notification_schema.md)'s
post-migration checklist:

1. **Crontab** — the daily WhatsApp digest (`v2/notification/digest.php`).
2. **WebSocket daemon** — the in-app real-time push server (`v2/notification/ws-server.php`),
   run as a `systemd` service and proxied through Nginx as `wss://`.

Do this once per host (dev VPS and prod VPS separately — paths differ).

---

## 0. Prerequisites

Confirm the PHP extensions Workerman needs for process management are present
before starting the daemon:

```bash
php -m | grep -E 'pcntl|posix'
```

Both should be bundled in Ubuntu's default `php8.3-cli` package. If either is
missing:

```bash
sudo apt-get install -y php8.3-cli
```

Confirm the app's dependencies are installed (`workerman/workerman` comes from
`composer.json`):

```bash
cd /home/ubuntu/apps/erpAPI-dev   # or erpAPI-v.1.0 on prod
composer install --no-dev --optimize-autoloader
```

Know your paths/ports for the host you're on:

| | Dev | Prod |
|---|---|---|
| App path | `/home/ubuntu/apps/erpAPI-dev` | `/home/ubuntu/apps/erpAPI-v.1.0` |
| Run as | `www-data` (deploy pipeline chowns the tree to this after every push) | `www-data` |
| `WS_PORT` | from `v2/.env` (default `9502`) | from `v2/.env` |
| `WS_PUBLISH_PORT` | from `v2/.env` (default `9503`, **loopback only, never expose**) | from `v2/.env` |

---

## 1. Crontab — daily digest

`v2/notification/digest.php` is already self-gating (per-company working-days
and public-holiday checks live inside the script — see
`isSendableDigestDay()`), so the cron line just needs to run it once a day at
08:00 WIB. Nothing else to configure.

Edit the `www-data` crontab (the deploy pipeline runs as this user, so running
the script as the same user avoids file-permission mismatches on the log/DB
connection):

```bash
sudo crontab -u www-data -e
```

Add (dev host path shown — swap for `erpAPI-v.1.0` on prod):

```cron
0 8 * * * php /home/ubuntu/apps/erpAPI-dev/v2/notification/digest.php >> /var/log/notification_digest.log 2>&1
```

Create the log file with the right owner so the first cron run doesn't fail
silently on a permission error:

```bash
sudo touch /var/log/notification_digest.log
sudo chown www-data:www-data /var/log/notification_digest.log
```

Verify the crontab is installed:

```bash
sudo crontab -u www-data -l
```

**Test it manually first** (safe to run any time — it only sends to
recipients resolved via `notification.*.approver` permissions, and skips
non-working days on its own):

```bash
sudo -u www-data php /home/ubuntu/apps/erpAPI-dev/v2/notification/digest.php
```

Expect JSON output listing one result object per active company
(`sent: true/false`, `recipients_notified`). Check
`/var/log/notification_digest.log` after the next 08:00 run to confirm cron
picked it up.

> Reminder from the migration checklist: `public_holiday` starts empty and
> `company_setting.notification.working_days` defaults to Mon–Fri in code —
> seed national holidays for the current year before trusting the
> holiday-skip logic in production.

---

## 2. WebSocket daemon — systemd service

`ws-server.php` is a long-running CLI daemon (Workerman), not a PHP-FPM
request target — it has to stay alive across reboots and crashes, which is
what `systemd`'s `Restart=always` gives you.

### 2.1 Create the unit file

```bash
sudo nano /etc/systemd/system/notification-ws.service
```

Dev host content (adjust `WorkingDirectory`/`ExecStart` path to
`erpAPI-v.1.0` for prod):

```ini
[Unit]
Description=Aluria Notification WebSocket Daemon
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/home/ubuntu/apps/erpAPI-dev
ExecStart=/usr/bin/php /home/ubuntu/apps/erpAPI-dev/v2/notification/ws-server.php start
Restart=always
RestartSec=5
StandardOutput=append:/var/log/notification-ws.log
StandardError=append:/var/log/notification-ws.log

[Install]
WantedBy=multi-user.target
```

Notes specific to this daemon:

- `Type=simple`, not `forking` — Workerman's `start` foreground mode is what
  systemd should track directly. Don't pass `-d` (daemonize) to `ExecStart`;
  let systemd own the process lifecycle instead of Workerman forking away
  from it.
- `User=www-data` matches the ownership the deploy pipeline sets on the repo
  (`sudo chown -R www-data:www-data ...`), so the daemon can read
  `vendor/autoload.php`, `v2/.env`, etc. without permission errors.
- `count = 1` is hardcoded in `ws-server.php` itself (see the comment at the
  top of that file) — exactly one process must hold the in-memory
  `ConnectionRegistry`, so do not template this into multiple instances or
  put it behind a process count > 1.

### 2.2 Enable and start it

```bash
sudo systemctl daemon-reload
sudo systemctl enable notification-ws
sudo systemctl start notification-ws
sudo systemctl status notification-ws
```

Check the log for the Workerman startup banner and confirm both listeners
came up (`websocket://0.0.0.0:9502` client-facing, plus the internal
`127.0.0.1:9503` publish socket bound in `onWorkerStart`):

```bash
tail -f /var/log/notification-ws.log
```

### 2.3 Common systemd operations

```bash
sudo systemctl restart notification-ws   # after a deploy that touches ws-server.php
sudo systemctl stop notification-ws
sudo journalctl -u notification-ws -n 100 --no-pager
```

> The deploy workflow (`.github/workflows/deploy.yml`) does **not** restart
> this service — a `git reset --hard` deploy doesn't touch the running
> daemon's code path. If you change `ws-server.php` or `v2/helpers/jwt.php`,
> restart the service manually after the deploy finishes.

### 2.4 Firewall

Only `WS_PORT` (9502) needs to be reachable from outside, and only via Nginx
(section 3) — don't open it directly if Nginx is terminating TLS. Never open
`WS_PUBLISH_PORT` (9503) to anything outside `127.0.0.1`; it has no auth and
is meant to be written to only by local PHP-FPM requests via
`pushWebSocketEvent()` in `v2/helpers/notification.php`.

```bash
sudo ufw status
# WS_PORT should only be reachable via Nginx's proxied path (443), not opened directly:
sudo ufw delete allow 9502/tcp 2>/dev/null || true
sudo ufw delete allow 9503/tcp 2>/dev/null || true
```

---

## 3. Nginx — `wss://` reverse proxy

Clients connect to `wss://<your-domain>/notification/stream`; Nginx proxies
that to the local Workerman listener on `WS_PORT`.

### 3.1 Add the location block

Add this inside the existing `server { ... }` block that already terminates
TLS for the API domain (do not create a second `server` block — this must
share the same certificate/domain as the rest of the API):

```nginx
location /notification/stream {
    proxy_pass http://127.0.0.1:9502;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

    # WebSocket connections are long-lived — don't let Nginx's default
    # proxy timeouts kill idle-but-authenticated clients.
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}
```

Replace `9502` with the actual `WS_PORT` value from that host's `v2/.env` if
it's been changed from the default.

### 3.2 Test and reload

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 3.3 Verify end-to-end

From a machine that can reach the domain (requires a valid JWT from this
app's login endpoint):

```bash
# Needs wscat: npm install -g wscat
wscat -c wss://<your-domain>/notification/stream
> {"type":"auth","token":"<jwt>"}
< {"event":"auth:ok","payload":{"user_id":"..."}}
```

If it hangs instead of connecting, check in order: `notification-ws` service
status (2.3), `nginx -t` output, then `sudo ufw status` (3, section 2.4) —
in that order, since a closed firewall port looks identical to a dead
daemon from the client's side.

---

## 4. Post-setup checklist

- [ ] `sudo crontab -u www-data -l` shows the digest line
- [ ] `/var/log/notification_digest.log` has an entry after the next 08:00 WIB run
- [ ] `systemctl status notification-ws` is `active (running)` and `enabled`
- [ ] `wscat` (or equivalent) connects through `wss://.../notification/stream` and gets `auth:ok`
- [ ] `sudo ufw status` confirms `9503` is not reachable from outside `127.0.0.1`
- [ ] A role has been granted `notification.sales_order.approver` /
      `notification.purchase_order.approver` — otherwise this whole pipeline
      runs with zero recipients (see the `v23` migration's open checklist item)
