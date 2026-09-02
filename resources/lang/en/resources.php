<?php

return [
    'connection' => [
        'label' => 'Connections',
        'group' => 'Integrations',
        'actions' => [
            'create' => 'Create Connection',
            'update' => 'Edit',
            'delete' => 'Delete',
            'test_connection' => 'Test Connection',
            'structure' => [
                'pending' => 'Fetch Structure',
                'preparing' => 'Preparing Structure',
                'ready' => 'Refresh Structure',
                'failed' => 'Retry Preparation',
            ],
        ],
        'empty' => [
            'heading' => 'No connections',
            'description' => 'Create your first data source connection',
        ],
        'fields' => [
            'name' => 'Name',
            'code' => 'Code',
            'driver' => 'Driver',
            'host' => 'Host',
            'port' => 'Port',
            'database' => 'Database',
            'username' => 'Username',
            'password' => 'Password',
            'schema' => 'Schema',
            'ssl_mode' => 'SSL Mode',
            'is_active' => 'Active',
            'structure' => 'Structure',
            'status' => 'Status',
            'objects' => 'Objects',
            'progress' => 'Progress',
            'published_at' => 'Published',
            'last_error' => 'Last Error',
        ],
        'pages' => [
            'list' => 'Connections',
            'create' => 'Create Connection',
            'edit' => 'Edit Connection',
        ],
        'sections' => [
            'general' => 'General Information',
            'structure' => 'Source Structure',
        ],
        'modals' => [
            'structure' => [
                'heading' => 'Refresh the source structure?',
                'description' => 'The current snapshot stays available until the new one is fetched completely',
            ],
        ],
        'messages' => [
            'test_success' => 'Connection established',
            'structure_queued' => 'Structure preparation has been queued',
            'structure_active' => 'Structure preparation is already running',
        ],
        'errors' => [
            'stale' => 'Preparation was interrupted: the source did not respond in time',
            'unreachable' => 'The server is unreachable. Check the host, port and network availability (:state)',
            'denied' => 'Access denied. Check the username and password (:state)',
            'unknown_database' => 'Database not found (:state)',
            'unknown_schema' => 'Schema not found (:state)',
            'unknown' => 'Unable to connect to the data source (:state)',
        ],
    ],
];
