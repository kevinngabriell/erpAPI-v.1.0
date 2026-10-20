<?php

// Resolves page/limit/offset for list endpoints. `limit=all` returns every matching row
// (used by FE list exports, which still pass their own filters); otherwise the page size
// is clamped to 1..$default_max (15 by default).
function resolveListPaging(array $params, int $default_max = 15): array {
    $page = max(1, (int)($params['page'] ?? 1));

    if (isset($params['limit']) && strtolower(trim((string)$params['limit'])) === 'all') {
        return ['page' => 1, 'limit' => null, 'offset' => 0, 'all' => true];
    }

    $limit = min($default_max, max(1, (int)($params['limit'] ?? $default_max)));
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit, 'all' => false];
}

function sqlLimitClause(array $paging): string {
    return $paging['all'] ? '' : " LIMIT {$paging['limit']} OFFSET {$paging['offset']}";
}

function buildPagination(array $paging, int $total): array {
    if ($paging['all']) {
        return ['total' => $total, 'page' => 1, 'limit' => $total, 'total_pages' => 1];
    }
    return [
        'total'       => $total,
        'page'        => $paging['page'],
        'limit'       => $paging['limit'],
        'total_pages' => (int)ceil($total / $paging['limit']),
    ];
}
