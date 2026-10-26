<?php

namespace PHPinnacle\Ferry;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Services\RosettaStructureAdapter;
use PHPinnacle\Ferry\Services\StructureAdapterRegistry;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FerryServiceProvider extends PackageServiceProvider
{
    public const string PACKAGE = 'phpinnacle/ferry';

    public static string $name = 'phpinnacle-ferry';

    public function packageRegistered(): void
    {
        $this->app->singleton(StructureAdapterRegistry::class, function (Container $app) {
            $registry = new StructureAdapterRegistry;
            $registry->register($app->make(RosettaStructureAdapter::class));

            return $registry;
        });

        $this->app->bind(OriginFactory::class, function () {
            /** @var array<string, array{driver: string, ...}> $config */
            $config = Config::array('phpinnacle-ferry.origins');

            return new OriginFactory($this->app, $config);
        });
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->discoversMigrations()
            ->hasConfigFile()
            ->hasTranslations()
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }
}
