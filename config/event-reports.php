<?php

return [
    'automatic_sync' => [
        'enabled' => (bool) env('EVENT_REPORT_AUTO_SYNC_ENABLED', true),
        'interval_minutes' => (int) env('EVENT_REPORT_SYNC_INTERVAL_MINUTES', 15),
        'minimum_interval_minutes' => (int) env('EVENT_REPORT_SYNC_MINIMUM_INTERVAL_MINUTES', 10),
        'final_retry_grace_minutes' => (int) env('EVENT_REPORT_FINAL_RETRY_GRACE_MINUTES', 60),
        'final_retry_interval_minutes' => (int) env('EVENT_REPORT_FINAL_RETRY_INTERVAL_MINUTES', 10),
    ],

    'zonesoft' => [
        'complete_documents' => (bool) env('EVENT_REPORT_COMPLETE_DOCUMENTS', true),
        'machine_sync_concurrency' => (int) env('EVENT_REPORT_MACHINE_SYNC_CONCURRENCY', 8),
    ],
];
