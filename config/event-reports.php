<?php

return [
    'dashboard' => [
        'cache_ttl_seconds' => (int) env('EVENT_REPORT_DASHBOARD_CACHE_TTL_SECONDS', 300),
    ],

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
        // PERF-103: guards for the lastupdate-keyset document pagination
        // (see EventReportSyncService::buildDocumentCondition()/
        // dedupeDocumentPage()). max_pages bounds one machine's fetch to a
        // sane number of round trips per sync cycle; max_documents bounds
        // the same fetch by row volume. Both exist purely to fail loudly
        // instead of looping forever if the ZSAPI ever behaves
        // unexpectedly (e.g. lastupdate stops advancing).
        'document_pagination_max_pages' => (int) env('EVENT_REPORT_DOCUMENT_PAGINATION_MAX_PAGES', 2000),
        'document_pagination_max_documents' => (int) env('EVENT_REPORT_DOCUMENT_PAGINATION_MAX_DOCUMENTS', 500000),
        // PERF-102: a machine with this many CONSECUTIVE failed sync
        // attempts falls back to a full refetch on its next attempt
        // (instead of trusting a cursor that might now be stale for
        // reasons the failures never let it prove) — isolated to that one
        // machine, never the rest of the event.
        'max_consecutive_failures_before_full_refresh' => (int) env('EVENT_REPORT_MAX_CONSECUTIVE_FAILURES_BEFORE_FULL_REFRESH', 3),
        // PERF-102: spreads each machine's periodic full refresh across
        // this many minutes (deterministic per machine id) instead of every
        // machine becoming due for a full refresh at the exact same instant.
        'full_refresh_jitter_minutes' => (int) env('EVENT_REPORT_FULL_REFRESH_JITTER_MINUTES', 120),
    ],
];
