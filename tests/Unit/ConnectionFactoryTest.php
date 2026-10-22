<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory as LaravelConnectionFactory;
use PHPinnacle\Ferry\Services\ConnectionFactory;
use Tests\TestCase;

uses(TestCase::class);

it('disconnects after a failed connection test', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('select')->with('select 1')->andThrow(new PDOException('Query failed'));
    $connection->shouldReceive('disconnect')->once();
    $factory = Mockery::mock(LaravelConnectionFactory::class);
    $factory->shouldReceive('make')->andReturn($connection);

    expect(new ConnectionFactory($factory)->test(['driver' => 'pgsql'])->success)->toBeFalse();
});

it('succeeds when the database can be reached', function () {
    $result = app(ConnectionFactory::class)->test([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    expect($result->success)->toBeTrue();
});

it('fails when the connection cannot be opened', function () {
    $result = app(ConnectionFactory::class)->test([
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'unreachable',
        'username' => 'secret-user',
        'password' => 'secret-password',
    ]);

    expect($result->success)
        ->toBeFalse()
        ->and($result->message)
        ->not->toBeEmpty()->and($result->message)
        ->not->toContain('secret-user')->and($result->message)
        ->not->toContain('secret-password')->and($result->message)
        ->not->toContain('SQL:');
});
