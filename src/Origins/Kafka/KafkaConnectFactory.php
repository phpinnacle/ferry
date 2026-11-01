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

    /** @param array{base_uri: string, headers: array<string, string>, ...} $config */
    public function client(array $config): Client
    {
        return new Client(
            $config['base_uri'],
            $this->app->make(ClientInterface::class),
            $this->app->make(RequestFactoryInterface::class),
            $this->app->make(StreamFactoryInterface::class),
            headers: $config['headers'],
        );
    }

    /** @param array{bootstrap_servers: string, flush_timeout: int, ...} $config */
    public function signals(array $config): SignalProducer
    {
        return new RdKafkaSignalProducer(
            $config['bootstrap_servers'],
            $config['flush_timeout'],
        );
    }
}
