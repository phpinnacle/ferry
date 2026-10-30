<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Structure;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

it('stores adapter-independent structures on a fresh installation and cascades owner deletion', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream(['adapter' => null]);
    $schema = JsonSchema::fromArray([
        'type' => 'object',
        'properties' => ['tenant_id' => ['type' => 'integer'], 'record_id' => ['type' => 'string']],
    ]);
    $item = StructureItem::create([
        'upstream_id' => $upstream->id,
        'external_id' => 'records',
        'name' => 'records',
        'primary_key' => ['tenant_id', 'record_id'],
        'schema' => $schema,
        'position' => 0,
    ])->fresh();
    TestCase::makeReplica(['upstream_id' => $upstream->id, 'source' => 'records', 'mapping' => []]);

    expect($upstream->fresh()->adapter)
        ->toBeNull()
        ->and($upstream->structure->status)
        ->toBe(StructureStatus::Pending)
        ->and($item->code)
        ->toBeNull()
        ->and($item->kind)
        ->toBeNull()
        ->and($item->priority)
        ->toBe(1)
        ->and($item->primary_key)
        ->toBe(['tenant_id', 'record_id'])
        ->and($item->schema->toArray())
        ->toBe($schema->toArray())
        ->and($item->structure->is($upstream->structure))
        ->toBeTrue()
        ->and(Schema::hasTable('upstream_metadata'))
        ->toBeFalse()
        ->and(Schema::hasColumn('upstreams', 'status'))
        ->toBeFalse();

    $upstream->getConnection()->table('upstreams')->where('id', $upstream->id)->delete();

    expect(Structure::query()->count())
        ->toBe(0)
        ->and(StructureItem::query()->count())
        ->toBe(0)
        ->and(Replica::query()->count())
        ->toBe(0);
});
