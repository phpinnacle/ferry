<?php

use PHPinnacle\Ferry\Services\ConnectionTester;
use Tests\TestCase;

uses(TestCase::class);

it('succeeds when the database can be reached', function () {
    $result = app(ConnectionTester::class)->test([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    expect($result->success)->toBeTrue();
});

it('fails when the connection cannot be opened', function () {
    $result = app(ConnectionTester::class)->test([
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
