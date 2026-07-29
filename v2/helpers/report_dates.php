<?php

function resolveReportDateRange($params) {
    $date_from = isset($params['date_from']) && trim($params['date_from']) !== ''
        ? $params['date_from']
        : date('Y-m-01');
    $date_to = isset($params['date_to']) && trim($params['date_to']) !== ''
        ? $params['date_to']
        : date('Y-m-d');

    return [$date_from, $date_to];
}
