<?php

return [
    'connection' => null,
    'queue' => null,
    'events' => [
        'bootstrap_servers' => env('FERRY_KAFKA_BOOTSTRAP_SERVERS'),
        'group_id' => env('FERRY_KAFKA_EVENTS_GROUP_ID', 'ferry-eloquent'),
        'shutdown_timeout' => 60, // Seconds to wait for each consumer before forcing shutdown.
    ],
    'scheduler' => [
        'refresh' => 1, // Seconds; non-positive values disable status refresh.
    ],
    'tenancy' => null,
    //    'tenancy' => [
    //        'model' => 'App\\Models\\Tenant',
    //        'default' => 'App\\Models\\Tenant::DEFAULT'
    //    ],
    'kafka' => [
        'transforms' => [
            \PHPinnacle\Ferry\Transformations\DebeziumTimestamps::class,
            \PHPinnacle\Ferry\Transformations\Unwrap::class,
            \PHPinnacle\Ferry\Transformations\Cast::class,
            \PHPinnacle\Ferry\Transformations\Filter::class,
            \PHPinnacle\Ferry\Transformations\RecordFilter::class,
            \PHPinnacle\Ferry\Transformations\InsertUuid::class,
            \PHPinnacle\Ferry\Transformations\Slug::class,
            \PHPinnacle\Ferry\Transformations\TimestampConverter::class,
            \PHPinnacle\Ferry\Transformations\BooleanToString::class,
            \PHPinnacle\Ferry\Transformations\StringMap::class,
            \PHPinnacle\Ferry\Transformations\EmptyStringToNull::class,
            \PHPinnacle\Ferry\Transformations\Toggle::class,
            \PHPinnacle\Ferry\Transformations\Json::class,
            \PHPinnacle\Ferry\Transformations\Money::class,
            \PHPinnacle\Ferry\Transformations\Radix::class,
        ],
    ],
    'timeout' => 5,
    'schema_registry' => [
        'base_uri' => env('FERRY_SCHEMA_REGISTRY_URL'),
        'headers' => [],
    ],
    'structure' => [
        'chunk_size' => 100,
        'timeout' => 120,
        'backoff' => [10, 30],
        'stale_after' => 900,
    ],
    'replica' => [
        'table_prefix' => 'ferry_replica_',
    ],
    'origins' => [
        'kafka' => [
            'driver' => 'kafka',
            'base_uri' => env('FERRY_KAFKA_CONNECT_URL'),
            'target_host' => null,
            'headers' => [],
            'source' => [
                'options' => [],
                'transforms' => [],
                'converters' => [
                    'uuid' => 'org.phpinnacle.toblerone.UuidConverter',
                    'integer' => 'org.phpinnacle.toblerone.IntegerConverter',
                    'mchar' => 'org.phpinnacle.toblerone.MCharConverter',
                ],
                'signal' => [
                    'topic' => env('FERRY_KAFKA_SIGNAL_TOPIC'),
                    'bootstrap_servers' => env('FERRY_KAFKA_BOOTSTRAP_SERVERS'),
                    'group_id' => env('FERRY_KAFKA_SIGNAL_GROUP_ID', 'ferry-signal'),
                    'flush_timeout' => 5000,
                ],
            ],
            'sink' => [
                'options' => [],
                'transforms' => [],
            ],
        ],
    ],
    'navigation' => [
        'upstream' => [
            'icon' => 'phosphor-plugs-connected',
            'sort' => 90,
        ],
        'replica' => [
            'icon' => 'phosphor-arrows-left-right',
            'sort' => 91,
        ],
        'schema_registry' => [
            'icon' => 'phosphor-tree-structure',
            'sort' => 92,
        ],
    ],
];
