<?php

return [
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
