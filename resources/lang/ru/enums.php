<?php

return [
    'column_type' => [
        'string' => 'Строка',
        'reference' => 'Ссылка',
        'integer' => 'Целое число',
        'decimal' => 'Десятичное число',
        'boolean' => 'Флаг',
        'datetime' => 'Дата и время',
    ],
    'driver' => [
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'MS SQL Server',
    ],
    'structure_status' => [
        'pending' => 'Не подготовлено',
        'preparing' => 'Подготовка',
        'ready' => 'Готово',
        'failed' => 'Ошибка',
    ],
    'sync_status' => [
        'pending' => 'Ожидает',
        'active' => 'Активна',
        'pause' => 'Пауза',
    ],
    'connector_status' => [
        'unknown' => 'Неизвестно',
        'running' => 'Работает',
        'paused' => 'На паузе',
        'failed' => 'Ошибка',
    ],
];
