<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Services\ConnectionTestResult;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function (): void {
    Queue::fake();
});

it('stores the password encrypted and never in plain text', function (): void {
    $connection = TestCase::makeConnection();

    $raw = DB::table('connections')->where('id', $connection->id)->value('password');

    expect($raw)
        ->not
        ->toBe('secret')
        ->and(Crypt::decryptString($raw))
        ->toBe('secret')
        ->and($connection->fresh()->password)
        ->toBe('secret');
});

it('enforces a unique code', function (): void {
    TestCase::makeConnection(['code' => 'duplicate']);

    expect(fn () => TestCase::makeConnection(['code' => 'duplicate']))->toThrow(QueryException::class);
});

it('toggles the active state', function (): void {
    $connection = TestCase::makeConnection(['is_active' => true]);

    $connection->toggleActive();

    expect($connection->fresh()->is_active)->toBeFalse();
});

it('is never in use until synchronizations exist', function (): void {
    expect(new Connection()->isInUse())->toBeFalse();
});

it('records the test result on the connection', function (): void {
    $connection = TestCase::makeConnection();

    $connection->recordTestResult(ConnectionTestResult::success('ok'));

    expect($connection->fresh()->last_test_passed)
        ->toBeTrue()
        ->and($connection->fresh()->last_tested_at)
        ->not->toBeNull();

    $connection->recordTestResult(ConnectionTestResult::failure('bad'));

    expect($connection->fresh()->last_test_passed)->toBeFalse();
});
