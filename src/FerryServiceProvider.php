<?php

namespace PHPinnacle\Ferry;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Contracts\SignalProducer;
use PHPinnacle\Ferry\Services\Connectors\RdKafkaSignalProducer;
use PHPinnacle\Franz\Client as ConnectClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FerryServiceProvider extends PackageServiceProvider
{
    public const string PACKAGE = 'phpinnacle/ferry';

    public static string $name = 'phpinnacle-ferry';

    public function packageRegistered(): void
    {
        $this->app->singleton(ConnectClient::class, function (Application $app) {
            $baseUri = Config::get('phpinnacle-ferry.kafka_connect.base_uri');

            if (!is_string($baseUri) || $baseUri === '') {
                throw new RuntimeException('The "phpinnacle-ferry.kafka_connect.base_uri" config value is not set.');
            }

            /** @var array<string, string> $headers */
            $headers = Config::array('phpinnacle-ferry.kafka_connect.headers');

            return new ConnectClient(
                $baseUri,
                $app->make(ClientInterface::class),
                $app->make(RequestFactoryInterface::class),
                $app->make(StreamFactoryInterface::class),
                headers: $headers,
            );
        });

        $this->app->singleton(SignalProducer::class, function () {
            $bootstrapServers = Config::get('phpinnacle-ferry.kafka_connect.signal.bootstrap_servers');

            if (!is_string($bootstrapServers) || $bootstrapServers === '') {
                throw new RuntimeException(
                    'The "phpinnacle-ferry.kafka_connect.signal.bootstrap_servers" config value is not set.',
                );
            }

            return new RdKafkaSignalProducer(
                $bootstrapServers,
                Config::integer('phpinnacle-ferry.kafka_connect.signal.flush_timeout'),
            );
        });
    }

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
