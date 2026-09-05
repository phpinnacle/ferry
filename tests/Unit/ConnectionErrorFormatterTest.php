<?php

use PHPinnacle\Ferry\Support\ConnectionErrorFormatter;
use Tests\TestCase;

uses(TestCase::class);

$exception = fn (string $state, int $code, string $message) => new class($state, $code, $message) extends PDOException {
    public function __construct(string $state, int $code, string $message)
    {
        parent::__construct($message);

        $this->errorInfo = [$state, $code, $message];
    }
};

$message = fn (string $reason, string $state) => __(
    'phpinnacle-ferry::resources.connection.errors.' . $reason,
    ['state' => $state],
);

it('maps a precise sql state', function () use ($exception, $message): void {
    expect(ConnectionErrorFormatter::format($exception('3D000', code: 7, message: 'database not found')))
        ->toBe($message('unknown_database', '3D000'));
});

it('maps a driver code when the sql state is generic', function () use ($exception, $message): void {
    expect(ConnectionErrorFormatter::format($exception('HY000', code: 2002, message: 'Connection refused')))
        ->toBe($message('unreachable', 'HY000'));
});

it('refines an ambiguous connection state by the driver text', function () use ($exception, $message): void {
    $driverMessage = 'connection to server at "127.0.0.1", port 5432 failed: FATAL:  password authentication failed for user "secret-user"';

    expect(ConnectionErrorFormatter::format($exception('08006', 7, $driverMessage)))
        ->toBe($message('denied', '08006'));
});

it('falls back to an unreachable server for an unrecognised connection failure', function () use (
    $exception,
    $message,
): void {
    expect(ConnectionErrorFormatter::format($exception('08006', code: 7, message: 'unrecognised driver text')))
        ->toBe($message('unreachable', '08006'));
});

it('falls back to a generic message for unknown failures', function () use ($message): void {
    expect(ConnectionErrorFormatter::format(new RuntimeException('boom')))
        ->toBe($message('unknown', 'HY000'));
});

it('never exposes the underlying driver text', function () use ($exception): void {
    $driverMessage = 'FATAL:  password authentication failed for user "secret-user"';

    expect(ConnectionErrorFormatter::format($exception('08006', 7, $driverMessage)))
        ->not->toContain('secret-user')
        ->not->toContain('FATAL');
});
