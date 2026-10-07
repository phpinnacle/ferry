<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Database\Connectors\ConnectionFactory as LaravelConnectionFactory;
use Illuminate\Support\Facades\Config;
use PDO;
use PHPinnacle\Ferry\Data\ConnectionTestResult;
use PHPinnacle\Ferry\Support\ConnectionErrorFormatter;
use Throwable;

class ConnectionFactory
{
    public function __construct(
        private readonly LaravelConnectionFactory $factory,
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
                'port' => $credentials['port'] ?? null,
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function test(array $data): ConnectionTestResult
    {
        try {
            $connection = $this->make($data);

            $connection->select('select 1');

            $connection->disconnect();

            return ConnectionTestResult::success(
                __('phpinnacle-ferry::resources.connection.messages.test_success'),
            );
        } catch (Throwable $exception) {
            return ConnectionTestResult::failure(ConnectionErrorFormatter::format($exception));
        }
    }
}
