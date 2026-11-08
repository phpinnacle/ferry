<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Database\Connectors\ConnectionFactory as LaravelConnectionFactory;
use Illuminate\Support\Facades\Config;
use PDO;
use PHPinnacle\Ferry\Data\UpstreamTestResult;
use PHPinnacle\Ferry\Support\UpstreamErrorFormatter;
use Throwable;

class UpstreamFactory
{
    public function __construct(
        private readonly LaravelConnectionFactory $factory,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function make(array $config): DatabaseConnection
    {
        /** @var array<int, mixed> $options */
        $options = $config['options'] ?? [];

        return $this->factory->make([
            ...$config,
            'options' => $options + [PDO::ATTR_TIMEOUT => Config::integer('phpinnacle-ferry.timeout')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function test(array $data): UpstreamTestResult
    {
        try {
            $database = $this->make($data);

            try {
                $database->select('select 1');
            } finally {
                $database->disconnect();
            }

            return UpstreamTestResult::success(
                __('phpinnacle-ferry::resources.upstream.messages.test_success'),
            );
        } catch (Throwable $exception) {
            return UpstreamTestResult::failure(UpstreamErrorFormatter::format($exception));
        }
    }
}
