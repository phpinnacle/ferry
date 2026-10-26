<?php

use PHPinnacle\Ferry\Contracts\Origin;
use PHPinnacle\Ferry\Origins\KafkaOrigin;
use PHPinnacle\Ferry\Services\OriginFactory;
use Psr\Http\Client\ClientInterface;
use Tests\TestCase;

uses(TestCase::class);

it('creates named Kafka origins without initializing transport', function () {
    $this->app->bind(ClientInterface::class, fn () => throw new LogicException('Transport must stay lazy.'));
    config([
        'phpinnacle-ferry.origins' => [
            'primary' => [
                ...config('phpinnacle-ferry.origins.kafka'),
                'driver' => 'kafka',
                'base_uri' => null,
            ],
            'analytics' => [
                ...config('phpinnacle-ferry.origins.kafka'),
                'driver' => 'kafka',
                'base_uri' => null,
            ],
        ],
    ]);

    $factory = $this->app->make(OriginFactory::class);
    $origin = $factory->create('analytics');

    expect($origin)
        ->toBeInstanceOf(KafkaOrigin::class)
        ->and($origin->name())
        ->toBe('analytics')
        ->and($factory->create('analytics'))
        ->not
        ->toBe($origin)
        ->and($factory->create('primary'))
        ->toBeInstanceOf(Origin::class)
        ->and($factory->options())
        ->toBe([
            'primary' => 'primary',
            'analytics' => 'analytics',
        ]);
});

it('rejects an unknown origin name', function () {
    expect(fn () => app(OriginFactory::class)->create('missing'))
        ->toThrow(InvalidArgumentException::class, 'Unknown origin [missing].');
});

it('rejects an unsupported driver name', function () {
    config(['phpinnacle-ferry.origins.custom' => ['driver' => 'missing']]);

    expect(fn () => app(OriginFactory::class)->create('custom'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported origin driver [missing].');
});
