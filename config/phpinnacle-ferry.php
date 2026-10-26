<?php

return [
    'connection' => null,
    'queue' => null,
    'tenancy' => null,
    //    'tenancy' => [
    //        'model' => 'App\\Models\\Tenant',
    //        'default' => 'App\\Models\\Tenant::DEFAULT'
    //    ],
    'kafka' => [
        'transforms' => [
            \PHPinnacle\Ferry\Transformations\Cast::class,
            \PHPinnacle\Ferry\Transformations\Filter::class,
            \PHPinnacle\Ferry\Transformations\InsertUuid::class,
        ],
        'converters' => [
            'uuid' => 'org.alpaka.kafka.UuidConverter',
            'integer' => 'org.alpaka.kafka.IntegerConverter',
            'mchar' => 'org.alpaka.kafka.MCharConverter',
        ],
    ],
    'timeout' => 5,
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
            'headers' => [],
            'target_host' => null,
            'source' => [
                // 'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
                // 'key.converter.schemas.enable' => 'true',
                // 'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
                // 'value.converter.schemas.enable' => 'true',
            ],
            'sink' => [
                // 'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
                // 'key.converter.schemas.enable' => 'true',
                // 'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
                // 'value.converter.schemas.enable' => 'true',
            ],
            'signal' => [
                'topic' => env('FERRY_KAFKA_SIGNAL_TOPIC'),
                'bootstrap_servers' => env('FERRY_KAFKA_BOOTSTRAP_SERVERS'),
                'group_id' => env('FERRY_KAFKA_SIGNAL_GROUP_ID', 'ferry-signal'),
                'flush_timeout' => 5000,
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
    ],
];
