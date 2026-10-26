<?php

namespace PHPinnacle\Ferry;

use Filament\Contracts\Plugin;
use Filament\Panel;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Transformations\Cast;
use PHPinnacle\Ferry\Transformations\Filter;

class FerryPlugin implements Plugin
{
    public const string ID = 'phpinnacle/ferry';

    public function __construct(
        private readonly DestinationFactory $destinations,
        private readonly TransformRegistry $transforms,
    ) {
        $this->transforms->register(Cast::make(), Filter::make());
    }

    public static function get(): static
    {
        // @mago-expect lint:inline-variable-return
        /** @var static $plugin */
        $plugin = filament(self::ID);

        return $plugin;
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

    public function transforms(Transformation ...$transforms): static
    {
        $this->transforms->register(...$transforms);

        return $this;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            Resources\Upstreams\UpstreamResource::class,
            Resources\Replicas\ReplicaResource::class,
        ]);
    }
}
