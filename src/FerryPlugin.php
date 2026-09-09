<?php

namespace PHPinnacle\Ferry;

use Filament\Contracts\Plugin;
use Filament\Panel;
use PHPinnacle\Ferry\Contracts\StaticDestination;
use PHPinnacle\Ferry\Services\StaticDestinationRegistry;

class FerryPlugin implements Plugin
{
    public const string ID = 'phpinnacle/ferry';

    public function __construct(
        private readonly StaticDestinationRegistry $destinations,
    ) {}

    public static function get(): static
    {
        // @mago-expect lint:inline-variable-return
        /** @var static $plugin */
        $plugin = filament(static::make()->getId());

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

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            Resources\Connections\ConnectionResource::class,
            Resources\Syncs\SyncResource::class,
        ]);
    }
}
