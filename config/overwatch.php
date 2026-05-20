<?php

$selfTestEnabled = env('OVERWATCH_SELF_TEST_ENABLED');

if ($selfTestEnabled === null) {
    $selfTestEnabled = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);
}

return [
    'tcp' => [
        'host' => env('OVERWATCH_TCP_HOST', '127.0.0.1'),
        'port' => (int) env('OVERWATCH_TCP_PORT', 2407),
        'backlog' => (int) env('OVERWATCH_TCP_BACKLOG', 128),
        'accept_timeout' => (float) env('OVERWATCH_TCP_ACCEPT_TIMEOUT', 1.0),
        'read_timeout' => (float) env('OVERWATCH_TCP_READ_TIMEOUT', 1.0),
        'max_frame_bytes' => (int) env('OVERWATCH_TCP_MAX_FRAME_BYTES', 10 * 1024 * 1024),
        'acknowledgment' => env('OVERWATCH_TCP_ACKNOWLEDGMENT', '2:OK'),
    ],

    'storage' => [
        'retention_days' => (int) env('OVERWATCH_RETENTION_DAYS', 30),
        'rollup_retention_days' => (int) env('OVERWATCH_ROLLUP_RETENTION_DAYS', 180),
        'partition_precreate_months' => (int) env('OVERWATCH_PARTITION_PRECREATE_MONTHS', 2),
    ],

    'logging' => [
        'channel' => env('OVERWATCH_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),
    ],

    'self_test' => [
        'enabled' => (bool) $selfTestEnabled,
        'route_prefix' => trim((string) env('OVERWATCH_SELF_TEST_ROUTE_PREFIX', '__nightwatch-test'), '/'),
        'project_slug' => env('OVERWATCH_SELF_TEST_PROJECT_SLUG', 'overwatch-self-test'),
        'project_name' => env('OVERWATCH_SELF_TEST_PROJECT_NAME', 'Overwatch Self Test'),
        'host' => env('OVERWATCH_SELF_TEST_HOST', '127.0.0.1'),
        'web_port' => (int) env('OVERWATCH_SELF_TEST_WEB_PORT', 28080),
        'secondary_web_port' => (int) env('OVERWATCH_SELF_TEST_SECONDARY_WEB_PORT', 28081),
        'startup_timeout' => (int) env('OVERWATCH_SELF_TEST_STARTUP_TIMEOUT', 20),
        'request_timeout' => (int) env('OVERWATCH_SELF_TEST_REQUEST_TIMEOUT', 10),
        'queue_prefix' => env('OVERWATCH_SELF_TEST_QUEUE_PREFIX', 'nightwatch-self-test'),
        'run_id' => env('OVERWATCH_SELF_TEST_RUN_ID'),
        'stub_base_url' => env('OVERWATCH_SELF_TEST_STUB_BASE_URL'),
    ],
];
