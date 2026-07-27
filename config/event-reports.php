<?php

return [
    'automatic_sync' => [
        'enabled' => (bool) env('EVENT_REPORT_AUTO_SYNC_ENABLED', true),
        'interval_minutes' => (int) env('EVENT_REPORT_SYNC_INTERVAL_MINUTES', 15),
        'minimum_interval_minutes' => 10,
    ],
];
