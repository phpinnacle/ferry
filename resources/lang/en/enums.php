<?php

return [
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
