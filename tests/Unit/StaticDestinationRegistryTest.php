<?php

use PHPinnacle\Ferry\Services\StaticDestinationRegistry;
use PHPinnacle\Ferry\Tests\Fakes\FakeCustomersDestination;

require_once __DIR__ . '/../Fakes/FakeCustomersDestination.php';

it('resolves a registered destination by its key', function () {
    $registry = new StaticDestinationRegistry;
    $destination = new FakeCustomersDestination;

    $registry->register($destination);

    expect($registry->get('customers'))->toBe($destination);
});

it('returns null for an unregistered key', function () {
    $registry = new StaticDestinationRegistry;

    expect($registry->get('missing'))->toBeNull();
});

it('lists all registered destinations keyed by their key', function () {
    $registry = new StaticDestinationRegistry;
    $destination = new FakeCustomersDestination;

    $registry->register($destination);

    expect($registry->all())->toBe(['customers' => $destination]);
});
