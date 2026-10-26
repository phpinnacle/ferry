<?php

use PHPinnacle\Ferry\Contracts\StructureAdapter;
use PHPinnacle\Ferry\Services\StructureAdapterRegistry;

it('registers and resolves adapters by their declared keys', function () {
    $adapter = Mockery::mock(StructureAdapter::class);
    $adapter->shouldReceive('getKey')->andReturn('custom-key');
    $adapter->shouldReceive('getLabel')->andReturn('Custom adapter');
    $registry = new StructureAdapterRegistry;
    $registry->register($adapter);

    expect($registry->getOrFail('custom-key'))
        ->toBe($adapter)
        ->and($registry->options())
        ->toBe(['custom-key' => 'Custom adapter']);
});

it('reports a missing configured adapter instead of substituting Rosetta', function () {
    expect(fn () => new StructureAdapterRegistry()->getOrFail('missing'))
        ->toThrow(LogicException::class, 'Unknown structure adapter [missing].');
});
