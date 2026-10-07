<?php

return [
    'connection' => null,
    'tenancy' => null,
    //    'tenancy' => [
    //        'model' => 'App\\Models\\Tenant',
    //        'default' => 'App\\Models\\Tenant::DEFAULT'
    //    ],
    'timeout' => 5,
    'structure' => [
        'chunk_size' => 100,
        'timeout' => 120,
        'backoff' => [10, 30],
        'stale_after' => 900,
    ],
    'sync' => [
        'table_prefix' => 'ferry_sync_',
    ],
    'kafka_connect' => [
        'base_uri' => env('FERRY_KAFKA_CONNECT_URL'),
        'headers' => [],
        'signal' => [
            'topic' => env('FERRY_KAFKA_SIGNAL_TOPIC'),
            'bootstrap_servers' => env('FERRY_KAFKA_BOOTSTRAP_SERVERS'),
            'group_id' => env('FERRY_KAFKA_SIGNAL_GROUP_ID', 'ferry-signal'),
            'flush_timeout' => 5000,
        ],
    ],
    'navigation' => [
        'connection' => [
            'icon' => 'phosphor-plugs-connected',
            'sort' => 90,
        ],
        'sync' => [
            'icon' => 'phosphor-arrows-left-right',
            'sort' => 91,
        ],
    ],
];
