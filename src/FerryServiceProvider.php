<?php

namespace PHPinnacle\Ferry;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Services\RosettaAdapter;
use PHPinnacle\Ferry\Services\StructureAdapterRegistry;
use PHPinnacle\Ferry\Services\TransformRegistry;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FerryServiceProvider extends PackageServiceProvider
{
    public const string PACKAGE = 'phpinnacle/ferry';

    public static string $name = 'phpinnacle-ferry';

    public function packageRegistered(): void
    {
        $this->app->singleton(TransformRegistry::class, function (Container $app) {
            $registry = new TransformRegistry;
            /** @var list<class-string<Transform>> $transforms */
            $transforms = Config::array('phpinnacle-ferry.kafka.transforms');
            foreach ($transforms as $transform) {
                $registry->register($app->get($transform));
            }

            return $registry;
        });

        $this->app->singleton(StructureAdapterRegistry::class, function (Container $app) {
            $registry = new StructureAdapterRegistry;
            $registry->register($app->make(RosettaAdapter::class));

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
