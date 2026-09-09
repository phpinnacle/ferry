<?php

use PHPinnacle\Ferry\Services\StaticDestinationRegistry;
use PHPinnacle\Ferry\Services\SyncDestinationResolver;
use PHPinnacle\Ferry\Tests\Fakes\FakeCustomersDestination;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/FakeCustomersDestination.php';

uses(TestCase::class);

it('finds a registered destination and reports an unknown one as absent', function () {
    $registry = new StaticDestinationRegistry;
    $registry->register(new FakeCustomersDestination);

    $resolver = new SyncDestinationResolver($registry);

    expect($resolver->find('customers'))
        ->toBeInstanceOf(FakeCustomersDestination::class)
        ->and($resolver->find('missing'))
        ->toBeNull()
        ->and($resolver->find(null))
        ->toBeNull();
});

it('still refuses an unknown static destination when one is required', function () {
    $resolver = new SyncDestinationResolver(new StaticDestinationRegistry);

    expect(fn () => $resolver->destination('missing'))
        ->toThrow(
            LogicException::class,
            __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', ['destination' => 'missing']),
        );
});
