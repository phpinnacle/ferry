<?php

return [
    'kafka_type' => [
        'string' => 'Строка',
        'int8' => 'Целое число (8 бит)',
        'int16' => 'Целое число (16 бит)',
        'int32' => 'Целое число (32 бита)',
        'int64' => 'Целое число (64 бита)',
        'float32' => 'Число с плавающей точкой (32 бита)',
        'float64' => 'Число с плавающей точкой (64 бита)',
        'boolean' => 'Логическое значение',
    ],
    'driver' => [
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'MS SQL Server',
    ],
    'ssl_mode' => [
        'disable' => 'Отключить SSL',
        'allow' => 'Разрешить SSL',
        'prefer' => 'Предпочитать SSL',
        'require' => 'Требовать SSL',
        'verify-ca' => 'Проверять центр сертификации',
        'verify-full' => 'Проверять центр сертификации и имя хоста',
    ],
    'structure_status' => [
        'pending' => 'Не подготовлено',
        'preparing' => 'Подготовка',
        'ready' => 'Готово',
        'failed' => 'Ошибка',
    ],
    'destination_type' => [
        'dynamic' => 'Динамический',
        'static' => 'Статический',
    ],
    'replica_status' => [
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
