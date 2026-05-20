<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Nightwatch Authentication Token
    |--------------------------------------------------------------------------
    |
    | This token is used to authenticate incoming Nightwatch agents.
    | It must match the NIGHTWATCH_TOKEN in your monitored Laravel applications.
    |
    */

    'token' => env('NIGHTWATCH_TOKEN', 'dev-token'),

    /*
    |--------------------------------------------------------------------------
    | TCP Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the TCP socket server that receives events from
    | Nightwatch agents running in your Laravel applications.
    |
    */

    'tcp' => [
        'enabled' => env('NIGHTWATCH_TCP_ENABLED', true),
        'host' => env('NIGHTWATCH_TCP_HOST', '127.0.0.1'),
        'port' => env('NIGHTWATCH_TCP_PORT', 2407),
        'timeout' => env('NIGHTWATCH_TCP_TIMEOUT', 30),
        'backlog' => env('NIGHTWATCH_TCP_BACKLOG', 128),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for HTTP-based event ingestion (alternative to TCP).
    |
    */

    'http' => [
        'enabled' => env('NIGHTWATCH_HTTP_ENABLED', true),
        'rate_limit' => env('NIGHTWATCH_HTTP_RATE_LIMIT', 60), // requests per minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Define how and where Nightwatch events should be stored.
    |
    */

    'storage' => [
        // Storage driver: database, elasticsearch
        'driver' => env('NIGHTWATCH_STORAGE_DRIVER', 'database'),

        // Data retention period in days (0 = keep forever)
        'retention_days' => env('NIGHTWATCH_RETENTION_DAYS', 30),

        // Rollup retention period in days
        'rollup_retention_days' => env('NIGHTWATCH_ROLLUP_RETENTION_DAYS', 180),

        // Auto-cleanup old data
        'auto_cleanup' => env('NIGHTWATCH_AUTO_CLEANUP', true),

        // Number of future monthly partitions to pre-create for raw events.
        'partition_precreate_months' => env('NIGHTWATCH_PARTITION_PRECREATE_MONTHS', 2),

        // Database table name for events
        'events_table' => 'events',

        // Database table name for aggregated metrics
        'metrics_table' => 'request_metrics',
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how events are processed after being received.
    |
    */

    'processing' => [
        // Process events in queue (recommended for production)
        'use_queue' => env('NIGHTWATCH_USE_QUEUE', false),

        // Queue connection to use
        'queue_connection' => env('NIGHTWATCH_QUEUE_CONNECTION', 'database'),

        // Queue name
        'queue_name' => env('NIGHTWATCH_QUEUE_NAME', 'nightwatch'),

        // Number of events to process in a single batch
        'batch_size' => env('NIGHTWATCH_BATCH_SIZE', 1000),

        // Maximum payload size in bytes (10MB default)
        'max_payload_size' => env('NIGHTWATCH_MAX_PAYLOAD_SIZE', 10 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Aggregation
    |--------------------------------------------------------------------------
    |
    | Configuration for aggregating raw events into metrics.
    |
    */

    'aggregation' => [
        // Enable automatic aggregation
        'enabled' => env('NIGHTWATCH_AGGREGATION_ENABLED', true),

        // Aggregation interval (hourly, daily)
        'interval' => env('NIGHTWATCH_AGGREGATION_INTERVAL', 'hourly'),

        // Run aggregation in queue
        'use_queue' => env('NIGHTWATCH_AGGREGATION_USE_QUEUE', true),

        // Metrics to aggregate
        'metrics' => [
            'requests' => true,
            'queries' => true,
            'commands' => true,
            'exceptions' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Filtering
    |--------------------------------------------------------------------------
    |
    | Filter which event types to store or ignore.
    |
    */

    'filtering' => [
        // Event types to ignore (won't be stored)
        'ignore_types' => env('NIGHTWATCH_IGNORE_TYPES', ''),

        // Only store these event types (empty = store all)
        'only_types' => env('NIGHTWATCH_ONLY_TYPES', ''),

        // Ignore cache events (can be very noisy)
        'ignore_cache_events' => env('NIGHTWATCH_IGNORE_CACHE_EVENTS', false),

        // Minimum severity for log events
        'log_level' => env('NIGHTWATCH_LOG_LEVEL', 'debug'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure alerts for important events.
    |
    */

    'alerts' => [
        // Enable alerting
        'enabled' => env('NIGHTWATCH_ALERTS_ENABLED', false),

        // Alert channels: mail, slack, discord
        'channels' => explode(',', env('NIGHTWATCH_ALERT_CHANNELS', 'mail')),

        // Alert on exceptions
        'on_exception' => env('NIGHTWATCH_ALERT_ON_EXCEPTION', true),

        // Alert on high error rate (%)
        'error_rate_threshold' => env('NIGHTWATCH_ERROR_RATE_THRESHOLD', 10),

        // Alert on slow requests (ms)
        'slow_request_threshold' => env('NIGHTWATCH_SLOW_REQUEST_THRESHOLD', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the web dashboard.
    |
    */

    'dashboard' => [
        // Enable dashboard
        'enabled' => env('NIGHTWATCH_DASHBOARD_ENABLED', true),

        // Dashboard route prefix
        'prefix' => env('NIGHTWATCH_DASHBOARD_PREFIX', 'nightwatch'),

        // Middleware to apply to dashboard routes
        'middleware' => ['web'],

        // Default time range for charts (hours)
        'default_time_range' => 24,

        // Refresh interval (seconds)
        'refresh_interval' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Protocol Configuration
    |--------------------------------------------------------------------------
    |
    | Nightwatch protocol settings.
    |
    */

    'protocol' => [
        // Protocol version
        'version' => 'v1',

        // Token hash algorithm
        'hash_algorithm' => 'xxh128',

        // Token hash length
        'hash_length' => 7,

        // Acknowledgment response
        'acknowledgment' => '2:OK',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure logging for the ingest server.
    |
    */

    'logging' => [
        // Log incoming connections
        'log_connections' => env('NIGHTWATCH_LOG_CONNECTIONS', true),

        // Log received events (can be very verbose)
        'log_events' => env('NIGHTWATCH_LOG_EVENTS', false),

        // Log processing errors
        'log_errors' => env('NIGHTWATCH_LOG_ERRORS', true),

        // Log channel
        'channel' => env('NIGHTWATCH_LOG_CHANNEL', 'stack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Configuration
    |--------------------------------------------------------------------------
    |
    | Performance optimization settings.
    |
    */

    'performance' => [
        // Use cache for frequently accessed data
        'use_cache' => env('NIGHTWATCH_USE_CACHE', true),

        // Cache TTL in seconds
        'cache_ttl' => env('NIGHTWATCH_CACHE_TTL', 300),

        // Concurrent TCP connections limit
        'max_connections' => env('NIGHTWATCH_MAX_CONNECTIONS', 100),

        // Connection pool size
        'pool_size' => env('NIGHTWATCH_POOL_SIZE', 10),
    ],
];
