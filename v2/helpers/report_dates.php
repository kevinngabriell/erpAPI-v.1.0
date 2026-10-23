<?php

function resolveReportDateRange($params) {
    $date_from = isset($params['date_from']) && trim($params['date_from']) !== ''
        ? $params['date_from']
        : date('Y-m-01');
    $date_to = isset($params['date_to']) && trim($params['date_to']) !== ''
        ? $params['date_to']
        : date('Y-m-d');

    foreach (['date_from' => $date_from, 'date_to' => $date_to] as $field => $value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            jsonResponse(400, "$field must be YYYY-MM-DD format");
            return;
        }
    }

    if (strtotime($date_from) > strtotime($date_to)) {
        jsonResponse(400, 'date_from must be before or equal to date_to');
        return;
    }

    return [$date_from, $date_to];
}
