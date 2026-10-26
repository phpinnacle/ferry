<?php

namespace PHPinnacle\Ferry\Origins\Kafka;

use Illuminate\Contracts\Foundation\Application;
use PHPinnacle\Ferry\Origins\Kafka\Contracts\SignalProducer;
use PHPinnacle\Franz\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class KafkaConnectFactory
{
    public function __construct(
        private readonly Application $app,
    ) {}

    /** @param array<string, mixed> $config */
    public function client(array $config): Client
    {
        /** @var string $baseUri */
        $baseUri = $config['base_uri'];

        /** @var array<string, string> $headers */
        $headers = $config['headers'];

        return new Client(
            $baseUri,
            $this->app->make(ClientInterface::class),
            $this->app->make(RequestFactoryInterface::class),
            $this->app->make(StreamFactoryInterface::class),
            headers: $headers,
        );
    }

    /** @param array<string, mixed> $config */
    public function signals(array $config): SignalProducer
    {
        /** @var array{bootstrap_servers: string, flush_timeout: int} $signal */
        $signal = $config['signal'];

        return new RdKafkaSignalProducer(
            $signal['bootstrap_servers'],
            $signal['flush_timeout'],
        );
    }
}
