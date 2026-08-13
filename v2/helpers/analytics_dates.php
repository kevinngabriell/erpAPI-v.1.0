<?php

function validateAnalyticsWindowParams($params) {
    foreach (['from', 'to'] as $field) {
        if (isset($params[$field]) && trim($params[$field]) !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $params[$field])) {
            jsonResponse(400, "$field must be YYYY-MM-DD format");
            return;
        }
    }
}

function resolveAnalyticsRange($params, $default_from, $default_to) {
    $from = isset($params['from']) && trim($params['from']) !== '' ? $params['from'] : $default_from;
    $to   = isset($params['to'])   && trim($params['to'])   !== '' ? $params['to']   : $default_to;
    return [$from, $to];
}

// monthly series → trailing 12 months ending this month, unless from/to override
function resolveAnalyticsMonthlyRange($params) {
    return resolveAnalyticsRange($params, date('Y-m-01', strtotime('-11 months')), date('Y-m-d'));
}

// *_ytd summary fields → calendar year to date, unless from/to override
function resolveAnalyticsYtdRange($params) {
    return resolveAnalyticsRange($params, date('Y-01-01'), date('Y-m-d'));
}

// everything else → current month, unless from/to override
function resolveAnalyticsDefaultRange($params) {
    return resolveAnalyticsRange($params, date('Y-m-01'), date('Y-m-d'));
}

function buildAnalyticsMonthBuckets($from, $to) {
    $months    = [];
    $cursor_ym = date('Y-m', strtotime($from));
    $end_ym    = date('Y-m', strtotime($to));
    while ($cursor_ym <= $end_ym) {
        $months[]  = $cursor_ym;
        $cursor_ym = date('Y-m', strtotime("$cursor_ym-01 +1 month"));
    }
    return $months;
}
