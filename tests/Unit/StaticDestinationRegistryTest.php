<?php

use PHPinnacle\Ferry\Services\StaticDestinationRegistry;
use PHPinnacle\Ferry\Tests\Fakes\FakeCustomersDestination;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/FakeCustomersDestination.php';

uses(TestCase::class);

it('resolves a registered destination by its key', function () {
    $registry = new StaticDestinationRegistry;
    $destination = new FakeCustomersDestination;

    $registry->register($destination);

    expect($registry->get('customers'))->toBe($destination)->and($registry->getOrFail('customers'))->toBe($destination);
});

it('returns null for an unregistered key', function () {
    $registry = new StaticDestinationRegistry;

    expect($registry->get('missing'))->toBeNull()->and($registry->get(null))->toBeNull();
});

it('lists all registered destinations keyed by their key', function () {
    $registry = new StaticDestinationRegistry;
    $destination = new FakeCustomersDestination;

    $registry->register($destination);

    expect($registry->all())->toBe(['customers' => $destination]);
});

it('rejects an unregistered destination when one is required', function () {
    $registry = new StaticDestinationRegistry;

    expect(fn () => $registry->getOrFail('missing'))
        ->toThrow(
            LogicException::class,
            __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', ['destination' => 'missing']),
        );
});
