<?php
require_once __DIR__ . '/config/session_setup.php';
header('Content-Type: application/json');

echo json_encode([
    'filtered' => [
        'total' => 5,
        'open' => 2,
        'pending' => 1,
        'closed' => 1,
        'overdue' => 1
    ],
    'global' => [
        'total' => 5,
        'open' => 2,
        'pending' => 1,
        'closed' => 1,
        'overdue' => 1
    ],
    'monthlyTrend' => [
        'labels' => ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05'],
        'data' => [1, 0, 1, 2, 1]
    ],
    'mountainData' => [
        'labels' => ['Hardware', 'Red', 'Software', 'Otro'],
        'datasets' => [[
            'name' => 'Tickets por Categoría',
            'data' => [2, 1, 1, 1],
            'backgroundColor' => ['#3b82f6', '#ef4444', '#10b981', '#f59e0b']
        ]]
    ]
]);
