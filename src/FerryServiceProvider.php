<?php

namespace PHPinnacle\Ferry;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FerryServiceProvider extends PackageServiceProvider
{
    public const string PACKAGE = 'phpinnacle/ferry';

    public static string $name = 'phpinnacle-ferry';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->discoversMigrations()
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }

    public function packageBooted(): void
    {
        FilamentAsset::register(
            assets: [
                AlpineComponent::make('field-mapping', __DIR__ . '/../resources/js/field-mapping.js'),
                Css::make('field-mapping', __DIR__ . '/../resources/css/field-mapping.css')
                    ->loadedOnRequest(),
            ],
            package: self::PACKAGE,
        );
    }
}
