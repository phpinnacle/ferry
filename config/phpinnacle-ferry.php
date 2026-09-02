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
    'navigation' => [
        'connection' => [
            'icon' => 'phosphor-plugs-connected',
            'sort' => 90,
        ],
    ],
];
