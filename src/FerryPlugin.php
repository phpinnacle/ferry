<?php

namespace PHPinnacle\Ferry;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Services\DestinationFactory;

class FerryPlugin implements Plugin
{
    public const string ID = 'phpinnacle/ferry';

    private bool $hasSchemaRegistry = false;

    /** @var array<array-key, class-string<Model>> */
    private array $models = [];

    public function __construct(
        private readonly DestinationFactory $destinations,
    ) {}

    public static function get(): static
    {
        /** @var static */
        return filament(self::ID);
    }

    public static function make(): static
    {
        return app()->get(static::class);
    }

    public function boot(Panel $panel): void {}

    public function destinations(StaticDestination ...$destinations): static
    {
        $this->destinations->register(...$destinations);

        return $this;
    }

    /** @param class-string<Model> ...$models */
    public function models(string ...$models): static
    {
        $this->models = $models;

        return $this;
    }

    /** @return array<array-key, class-string<Model>> */
    public function getModels(): array
    {
        return $this->models;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function schemaRegistry(bool $condition = true): static
    {
        $this->hasSchemaRegistry = $condition;

        return $this;
    }

    public function hasSchemaRegistry(): bool
    {
        return $this->hasSchemaRegistry;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            Resources\Upstreams\UpstreamResource::class,
            Resources\Replicas\ReplicaResource::class,
        ]);

        if ($this->hasSchemaRegistry) {
            $panel->pages([
                Pages\SchemaRegistry::class,
                Pages\ViewSchemaRegistrySubject::class,
            ]);
        }
    }
}
