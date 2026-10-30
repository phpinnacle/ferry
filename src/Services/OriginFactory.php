<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use PHPinnacle\Ferry\Contracts\Origin;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Origins\KafkaOrigin;

class OriginFactory
{
    /** @param array<string, array{driver: 'kafka', ...}> $config */
    public function __construct(
        private readonly Container $container,
        private readonly array $config,
    ) {}

    public function create(string $name): Origin
    {
        $config = $this->config[$name] ?? throw new InvalidArgumentException(sprintf('Unknown origin [%s].', $name));

        return match ($config['driver']) {
            'kafka' => $this->container->make(KafkaOrigin::class, ['name' => $name, 'config' => $config]),
        };
    }

    public function for(Upstream|Replica $owner): Origin
    {
        return $this->create($owner instanceof Upstream ? $owner->origin : $owner->upstream->origin);
    }

    /** @return array<string, string> */
    public function options(): array
    {
        $names = array_keys($this->config);

        return array_combine($names, $names);
    }
}
