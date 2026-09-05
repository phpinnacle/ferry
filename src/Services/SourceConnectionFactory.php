<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\Config;
use PDO;

class SourceConnectionFactory
{
    public function __construct(
        private readonly ConnectionFactory $factory,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function make(array $credentials): DatabaseConnection
    {
        return $this->factory->make(array_filter(
            [
                'driver' => $credentials['driver'] ?? null,
                'host' => $credentials['host'] ?? null,
                'port' => ($credentials['port'] ?? null) !== null ? (int) $credentials['port'] : null,
                'database' => $credentials['database'] ?? null,
                'username' => $credentials['username'] ?? null,
                'password' => $credentials['password'] ?? null,
                'schema' => $credentials['schema'] ?? null,
                'sslmode' => $credentials['ssl_mode'] ?? null,
                'options' => [
                    PDO::ATTR_TIMEOUT => Config::integer('phpinnacle-ferry.timeout'),
                ],
            ],
            fn (mixed $value) => $value !== null,
        ));
    }
}
