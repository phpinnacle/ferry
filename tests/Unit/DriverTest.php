<?php

use PHPinnacle\Ferry\Enums\Driver;
use Tests\TestCase;

uses(TestCase::class);

it('provides the default port for each driver', function () {
    expect(Driver::Pgsql->defaultPort())->toBe(5432)->and(Driver::Sqlsrv->defaultPort())->toBe(1433);
});

it('resolves a translated label for each driver', function () {
    expect(Driver::Pgsql->getLabel())->toBe('PostgreSQL')->and(Driver::Sqlsrv->getLabel())->toBe('MS SQL Server');
});
