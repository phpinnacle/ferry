<?php

return [
    'kafka_type' => [
        'string' => 'String',
        'int8' => '8-bit integer',
        'int16' => '16-bit integer',
        'int32' => '32-bit integer',
        'int64' => '64-bit integer',
        'float32' => '32-bit floating-point number',
        'float64' => '64-bit floating-point number',
        'boolean' => 'Boolean',
    ],
    'driver' => [
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'MS SQL Server',
    ],
    'ssl_mode' => [
        'disable' => 'Disable SSL',
        'allow' => 'Allow SSL',
        'prefer' => 'Prefer SSL',
        'require' => 'Require SSL',
        'verify-ca' => 'Verify CA',
        'verify-full' => 'Verify CA and hostname',
    ],
    'structure_status' => [
        'pending' => 'Not Prepared',
        'preparing' => 'Preparing',
        'ready' => 'Ready',
        'failed' => 'Error',
    ],
    'destination_type' => [
        'dynamic' => 'Dynamic',
        'static' => 'Static',
    ],
    'replica_status' => [
        'pending' => 'Pending',
        'active' => 'Active',
        'pause' => 'Paused',
    ],
    'connector_status' => [
        'unknown' => 'Unknown',
        'running' => 'Running',
        'paused' => 'Paused',
        'failed' => 'Failed',
    ],
];
