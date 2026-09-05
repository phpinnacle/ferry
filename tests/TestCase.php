<?php

namespace PHPinnacle\Ferry\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Ferry\Models\Connection;
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

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'phpinnacle-ferry.connection' => null,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('pgsql');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createConnectionsTable();
        $this->createConnectionMetadataTable();
    }

    private function createConnectionMetadataTable(): void
    {
        Schema::create('connection_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('connection_id');
            $table->uuid('parent_id')->nullable()->index();
            $table->string('external_id');
            $table->string('reference_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('code');
            $table->string('kind', 32)->index();
            $table->string('label');
            $table->string('title');
            $table->json('system');
            $table->json('properties');
            $table->json('values');
            $table->unsignedInteger('position');
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(['connection_id', 'external_id', 'revision']);
            $table->index(['connection_id', 'revision']);
        });
    }

    private function createConnectionsTable(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('driver');
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('database');
            $table->string('username');
            $table->text('password');
            $table->string('schema')->nullable();
            $table->string('ssl_mode')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_passed')->nullable();
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('generation')->default(0);
            $table->uuid('run_id')->nullable();
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->json('storage_map')->nullable();
            $table->json('type_map')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();
        });
    }
}
