<?php

return [
    'column_type' => [
        'string' => 'String',
        'reference' => 'Reference',
        'integer' => 'Integer',
        'decimal' => 'Decimal',
        'boolean' => 'Boolean',
        'datetime' => 'Date and Time',
    ],
    'driver' => [
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'MS SQL Server',
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
    'sync_status' => [
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
