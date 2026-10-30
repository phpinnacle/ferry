<?php

namespace PHPinnacle\Ferry\Tests;

use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Tests\Fakes\Origin;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Jason\Schema;
use Tests\TestCase as ApplicationTestCase;

require_once __DIR__ . '/Fakes/Origin.php';
require_once __DIR__ . '/Fixtures/StructureItems.php';
require_once __DIR__ . '/Fixtures/SchemaHost.php';

abstract class TestCase extends ApplicationTestCase
{
    /**
     * @param array<string, mixed> $data
     */
    public static function replicaForm(
        array $data,
        ?Replica $record = null,
        ?SchemaHost $livewire = null,
    ): FilamentSchema {
        $record ??= self::makeReplica([...$data, 'mapping' => [], 'transforms' => []]);
        $form = ReplicaForm::configure(
            FilamentSchema::make($livewire ?? SchemaHost::make())
                ->statePath('data')
                ->record($record)
                ->operation('edit'),
        );
        $form->fill([...$record->attributesToArray(), ...$data]);

        return $form;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function replicaFormState(array $data, ?Replica $record = null): array
    {
        return self::replicaForm($data, $record)->getState();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function replicaCreationState(array $data): array
    {
        $form = ReplicaForm::creation(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
        $form->fill($data);

        return $form->getState();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $state
     */
    public static function makeUpstream(array $attributes = [], array $state = []): Upstream
    {
        $upstream = Upstream::create([
            'name' => 'Test upstream',
            'code' => 'test-upstream',
            'origin' => 'kafka',
            'adapter' => 'rosetta',
            'driver' => 'pgsql',
            'config' => [
                'host' => 'localhost',
                'port' => 5432,
                'database' => 'db',
                'username' => 'user',
                'password' => 'secret',
            ],
            'is_active' => true,
            ...$attributes,
        ]);

        if ($state !== []) {
            $upstream->structure->forceFill($state)->save();
        }

        return $upstream;
    }

    /** @param array<string, mixed> $attributes */
    public static function makeItem(Upstream $upstream, array $attributes = []): StructureItem
    {
        return tap(
            StructureItems::make([
                'upstream_id' => $upstream->id,
                'external_id' => 'object-0',
                'name' => '_reference0',
                'code' => 1,
                'kind' => 'reference',
                'label' => 'Object 0',
                'title' => 'Object 0',
                'sources' => [],
                'values' => [],
                'position' => 0,
                'revision' => $upstream->structure->generation,
                ...$attributes,
                'fields' => [
                    '_idrref' => self::keySchema(),
                    ...($attributes['fields'] ?? []),
                ],
            ]),
            static fn (StructureItem $item) => $item->save(),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $state
     */
    public static function makeReplica(array $attributes = [], array $state = []): Replica
    {
        if (!array_key_exists('upstream_id', $attributes)) {
            $upstream = self::makeUpstream();
            self::makeItem($upstream);
            $attributes['upstream_id'] = $upstream->id;
        }

        $replica = Replica::create([
            'name' => 'Test replica',
            'code' => 'test-replica',
            'source' => 'object-0',
            'mapping' => [self::keyMapping()],
            ...$attributes,
        ]);

        if ($state !== []) {
            $replica->forceFill($state)->save();
        }

        return $replica;
    }

    public static function keyMapping(): FieldMapping
    {
        return new FieldMapping('_idrref', 'external_id');
    }

    public static function keySchema(): Schema
    {
        return Schema::fromArray([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public static function makeConnector(Upstream|Replica $owner, array $attributes): Connector
    {
        $connector = ($owner->refresh()->connector ?? new Connector)->fill([
            'origin' => $owner instanceof Upstream ? $owner->origin : $owner->upstream->origin,
            'config' => [],
            ...$attributes,
        ]);
        $connector->upstream_id = $owner instanceof Upstream ? $owner->id : $owner->upstream_id;
        $connector->save();
        $owner->recordConnector($connector);

        return $connector;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'phpinnacle-ferry.connection' => null,
            'phpinnacle-ferry.origins.kafka.base_uri' => 'http://kafka-connect.test',
            'phpinnacle-ferry.origins.kafka.signal.topic' => 'ferry-signal',
            'phpinnacle-ferry.origins.kafka.signal.bootstrap_servers' => 'kafka.test:9092',
            'phpinnacle-ferry.origins.kafka.signal.group_id' => 'ferry-signal',
            'database.default' => 'sqlite',
            'queue.default' => 'database',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('pgsql');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->loadLaravelMigrations();

        $migration = require __DIR__ . '/../database/migrations/create_ferry_tables.php';
        $migration->up();

        $origin = new Origin;
        $this->app->bind(OriginFactory::class, function ($app) use ($origin) {
            $origins = Mockery::mock(OriginFactory::class, [$app, config('phpinnacle-ferry.origins')])->makePartial();
            $origins->shouldReceive('create')->andReturn($origin);

            return $origins;
        });
    }
}
