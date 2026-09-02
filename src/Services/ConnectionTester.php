<?php

namespace PHPinnacle\Ferry\Services;

use PHPinnacle\Ferry\Support\ConnectionErrorFormatter;
use Throwable;

class ConnectionTester
{
    public function __construct(
        private readonly SourceConnectionFactory $factory,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function test(array $data): ConnectionTestResult
    {
        try {
            $connection = $this->factory->make($data);

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
