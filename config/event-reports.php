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
        'machine_sync_concurrency' => (int) env('EVENT_REPORT_MACHINE_SYNC_CONCURRENCY', 4),
        'full_machine_sync_concurrency' => (int) env('EVENT_REPORT_FULL_MACHINE_SYNC_CONCURRENCY', 10),
        'incremental_machine_sync_concurrency' => (int) env('EVENT_REPORT_INCREMENTAL_MACHINE_SYNC_CONCURRENCY', 4),
        'machine_worker_timeout_seconds' => (int) env('EVENT_REPORT_MACHINE_WORKER_TIMEOUT_SECONDS', 240),
        'connect_timeout_seconds' => (int) env('EVENT_REPORT_CONNECT_TIMEOUT_SECONDS', 5),
        'request_timeout_seconds' => (int) env('EVENT_REPORT_REQUEST_TIMEOUT_SECONDS', 30),
        'full_request_timeout_seconds' => (int) env('EVENT_REPORT_FULL_REQUEST_TIMEOUT_SECONDS', 30),
        'incremental_request_timeout_seconds' => (int) env('EVENT_REPORT_INCREMENTAL_REQUEST_TIMEOUT_SECONDS', 10),
        'request_retry_attempts' => (int) env('EVENT_REPORT_REQUEST_RETRY_ATTEMPTS', 1),
        'full_request_retry_attempts' => (int) env('EVENT_REPORT_FULL_REQUEST_RETRY_ATTEMPTS', 3),
        'incremental_request_retry_attempts' => (int) env('EVENT_REPORT_INCREMENTAL_REQUEST_RETRY_ATTEMPTS', 1),
        'incremental_overlap_minutes' => (int) env('EVENT_REPORT_INCREMENTAL_OVERLAP_MINUTES', 15),
        'incremental_full_refresh_hours' => (int) env('EVENT_REPORT_INCREMENTAL_FULL_REFRESH_HOURS', 24),
    ],
];
