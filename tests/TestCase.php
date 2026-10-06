<?php

namespace PHPinnacle\Ferry\Tests;

use Illuminate\Support\Facades\DB;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\ScalarField;
use Tests\TestCase as ApplicationTestCase;

abstract class TestCase extends ApplicationTestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $state
     */
    public static function makeConnection(array $attributes = [], array $state = []): Connection
    {
        $connection = Connection::create([
            'name' => 'Test connection',
            'code' => 'test-connection',
            'driver' => 'pgsql',
            'host' => 'localhost',
            'port' => 5432,
            'database' => 'db',
            'username' => 'user',
            'password' => 'secret',
            'is_active' => true,
            ...$attributes,
        ]);

        if ($state !== []) {
            $connection->forceFill($state)->save();
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $state
     */
    public static function makeSync(array $attributes = [], array $state = []): Sync
    {
        if (!array_key_exists('connection_id', $attributes)) {
            $attributes['connection_id'] = self::makeConnection()->id;
        }

        $sync = Sync::create([
            'name' => 'Test synchronization',
            'code' => 'test-sync',
            'source' => 'object-0',
            'schema' => [
                new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id)),
            ],
            ...$attributes,
        ]);

        if ($state !== []) {
            $sync->forceFill($state)->save();
        }

        return $sync;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'phpinnacle-ferry.connection' => null,
            'phpinnacle-ferry.kafka_connect.base_uri' => 'http://kafka-connect.test',
            'phpinnacle-ferry.kafka_connect.signal.topic' => 'ferry-signal',
            'phpinnacle-ferry.kafka_connect.signal.bootstrap_servers' => 'kafka.test:9092',
            'phpinnacle-ferry.kafka_connect.signal.group_id' => 'ferry-signal',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('pgsql');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $migration = require __DIR__ . '/../database/migrations/create_ferry_tables.php';
        $migration->up();
    }
}
