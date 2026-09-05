<?php

namespace PHPinnacle\Ferry;

use Filament\Contracts\Plugin;
use Filament\Panel;

class FerryPlugin implements Plugin
{
    public const string ID = 'phpinnacle/ferry';

    public static function get(): static
    {
        // @mago-expect lint:inline-variable-return
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function boot(Panel $panel): void {}

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            Resources\Connections\ConnectionResource::class,
        ]);
    }
}
