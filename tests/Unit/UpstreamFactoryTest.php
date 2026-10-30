<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory as LaravelConnectionFactory;
use PHPinnacle\Ferry\Services\UpstreamFactory;
use Tests\TestCase;

uses(TestCase::class);

it('disconnects after a failed connection test', function () {
    $database = Mockery::mock(Connection::class);
    $database->shouldReceive('select')->with('select 1')->andThrow(new PDOException('Query failed'));
    $database->shouldReceive('disconnect')->once();
    $factory = Mockery::mock(LaravelConnectionFactory::class);
    $factory->shouldReceive('make')->andReturn($database);

    expect(new UpstreamFactory($factory)->test(['driver' => 'pgsql'])->success)->toBeFalse();
});

it('succeeds when the database can be reached', function () {
    $result = app(UpstreamFactory::class)->test([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    expect($result->success)->toBeTrue();
});

it('fails when the connection cannot be opened', function () {
    $factory = Mockery::mock(LaravelConnectionFactory::class);
    $factory
        ->shouldReceive('make')
        ->once()
        ->andThrow(new PDOException('Connection failed for secret-user with secret-password; SQL: select 1'));

    $result = new UpstreamFactory($factory)->test([
        'driver' => 'pgsql',
        'host' => 'database.example',
        'port' => 5432,
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

it('passes driver settings and explicit PDO options to the native factory', function () {
    $config = [
        'driver' => 'sqlsrv',
        'host' => 'database.example',
        'encrypt' => 'yes',
        'trust_server_certificate' => false,
        'options' => [PDO::ATTR_TIMEOUT => 9, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    ];
    $database = Mockery::mock(Connection::class);
    $factory = Mockery::mock(LaravelConnectionFactory::class);
    $factory->shouldReceive('make')->once()->with($config)->andReturn($database);

    expect(new UpstreamFactory($factory)->make($config))->toBe($database);
});
