<?php

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use PHPinnacle\Ferry\FerryServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }

    protected function getPackageProviders($app): array
    {
        $packages = json_decode(
            file_get_contents(__DIR__ . '/../vendor/composer/installed.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['packages'];

        $providers = array_merge(...array_map(
            static fn (array $package) => $package['extra']['laravel']['providers'] ?? [],
            $packages,
        ));

        return array_values(array_unique([...$providers, FerryServiceProvider::class]));
    }
}
