<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('stores driver configuration encrypted and excludes it from serialization', function () {
    $upstream = TestCase::makeUpstream();

    $raw = DB::table('upstreams')->where('id', $upstream->id)->value('config');

    expect($raw)
        ->not->toContain('secret')->and($upstream->fresh()->config)->toBe($upstream->config)->and(
            $upstream->fresh()->config['password'],
        )->toBe('secret')->and($upstream->toArray())
        ->not->toHaveKey('config');
});

it('enforces a unique code', function () {
    TestCase::makeUpstream(['code' => 'duplicate']);

    expect(fn () => TestCase::makeUpstream(['code' => 'duplicate']))->toThrow(QueryException::class);
});

it('requires an explicitly selected origin when creating an upstream', function () {
    expect(new Upstream()->origin)->toBeNull();
    expect(fn () => TestCase::makeUpstream(['origin' => null]))->toThrow(QueryException::class);
});
