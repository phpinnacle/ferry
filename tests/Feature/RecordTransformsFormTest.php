<?php

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Common\Forms\TabbedBuilder;
use PHPinnacle\Common\Forms\TabbedBuilder\Block as TabbedBlock;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Forms\RecordTransforms;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fakes\Transform as FakeTransform;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Jason\Schema;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';
require_once __DIR__ . '/../Fakes/Transform.php';

uses(TestCase::class);

it('keeps input schemas and parameter state independent between record builders', function () {
    $first = Schema::fromArray([
        'type' => 'object',
        'properties' => ['first_key' => ['type' => 'string']],
        'x-primary-key' => ['first_key'],
    ]);
    $second = Schema::fromArray([
        'type' => 'object',
        'properties' => ['second_key' => ['type' => 'integer']],
        'x-primary-key' => ['second_key'],
    ]);
    $form = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components([
            RecordTransforms::make('first')->inputSchema($first),
            RecordTransforms::make('second')->inputSchema($second),
        ]);
    $form->fill();
    expect($form->getComponent('first')->getInputSchema())->toBe($first);
    expect($form->getComponent('second')->getInputSchema())->toBe($second);

    foreach (['first', 'second'] as $name) {
        $action = $form->getComponentByStatePath($name)->getAction('add')(['block' => 'insert_uuid']);
        $action->callBefore();
        $action->call();
    }

    expect($form->getState())->toBe([
        'first' => [['type' => 'insert_uuid', 'data' => ['fields' => ["$['first_key']"], 'output' => 'id']]],
        'second' => [['type' => 'insert_uuid', 'data' => ['fields' => ["$['second_key']"], 'output' => 'id']]],
    ]);
});

it('uses the current primary key after a preceding UUID transform', function () {
    app()->setLocale('ru');
    $builder = RecordTransforms::make('transforms')->inputSchema(Schema::fromArray([
        'type' => 'object',
        'properties' => [
            'tenant_id' => ['type' => 'integer', 'title' => 'Tenant'],
            'row_id' => [
                'type' => 'string',
                'title' => 'Row',
                'x-ferry-trans' => 'phpinnacle-ferry::resources.replica.system_fields._idrref',
            ],
        ],
        'x-primary-key' => ['tenant_id', 'row_id'],
    ]));
    $form = FilamentSchema::make(SchemaHost::make())->statePath('data')->components([$builder]);
    $form->fill([
        'transforms' => [[
            'type' => 'insert_uuid',
            'data' => ['fields' => ["$['tenant_id']", "$['row_id']"], 'output' => 'uuid'],
        ]],
    ]);

    $action = $builder->getAction('add')(['block' => 'insert_uuid']);
    $action->callBefore();
    $action->call();

    $items = array_keys($builder->getRawState());
    $sourceProperties = $builder
        ->getChildSchema($items[0])
        ->getComponentByStatePath('fields')
        ->getJsonSchema()
        ->properties();
    expect(array_keys($sourceProperties))->toBe(['tenant_id', 'row_id']);
    expect($sourceProperties['tenant_id']->keywords()['title'])->toBe('Tenant');
    expect($sourceProperties['row_id']->keywords()['title'])->toBe('Идентификатор');
    $properties = $builder->getChildSchema($items[1])->getComponentByStatePath('fields')->getJsonSchema()->properties();
    expect(array_keys($properties))->toBe(['uuid']);
    expect($form->getState()['transforms'][1])->toBe([
        'type' => 'insert_uuid',
        'data' => ['fields' => ["$['uuid']"], 'output' => 'id'],
    ]);
    expect($builder->getInputSchema()->annotation('x-primary-key'))->toBe(['id']);
});

it('omits the UUID transform when the input schema has no primary key', function () {
    $builder = RecordTransforms::make('transforms')->inputSchema(Schema::fromArray([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
    ]));
    FilamentSchema::make(SchemaHost::make())->components([$builder])->fill();

    expect(array_map(fn ($block) => $block->getName(), $builder->getBlockPickerBlocks()))
        ->not
        ->toContain('insert_uuid');
});

it('offers every registered record transform as a native tab and persists custom settings', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, ['sources' => ['name' => ['type' => 'string']]]);
    app(TransformRegistry::class)->register(new FakeTransform(
        'custom_record',
        output: fn (Schema $schema, array $params) => (
            blank($params['value'] ?? null)
                ? null
                : Schema::fromArray([
                    ...$schema->toArray(),
                    'properties' => [...$schema->toArray()['properties'], 'tag' => ['type' => 'string']],
                ])
        ),
        block: TabbedBlock::make('custom_record')
            ->label('Custom record')
            ->schema([
                TextInput::make('value')->required()->live(),
            ]),
        connector: fn (array $params) => ['type' => 'example.RecordTransform', 'value' => $params['value']],
        scope: TransformScope::Record,
    ));

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
        'name' => 'Record transforms',
        'code' => 'records',
        'mapping' => [['kind' => 'source', 'source' => '_idrref', 'target' => 'id', 'options' => []]],
    ]);
    $builder = $form->getComponentByStatePath('transforms');
    expect($builder)->toBeInstanceOf(TabbedBuilder::class);
    expect(array_map(fn ($block) => $block->getName(), $builder->getBlockPickerBlocks()))
        ->toBe(['insert_uuid', 'slug', 'custom_record']);
    $action = $builder->getAction('add')(['block' => 'custom_record']);
    $action->callBefore();
    $action->call();
    $item = array_key_first($builder->getRawState());

    $value = $builder->getChildSchema($item)->getComponentByStatePath('value');
    $value->state('configured');
    $value->callAfterStateUpdated();

    $mapping = $form->getComponentByStatePath('mapping');
    expect($mapping->getSourceSchema()->toArray()['properties'])->toHaveKey('tag');
    $rows = $mapping->getState();
    $rows[$mapping->getSourceRowIndex('tag')]['target'] = 'tag';
    $mapping->state($rows);
    $state = $form->getState();
    expect($state['transforms'])->toBe([['type' => 'custom_record', 'data' => ['value' => 'configured']]]);
    $replica = $form->getRecord();
    $replica->update($state);
    $replica->refresh();
    expect($replica->toArray()['transforms'])->toBe($state['transforms']);
    expect(app(TransformFactory::class)->pipeline($replica, $object))->toMatchArray([
        'transforms' => 'replica_0,rename_values',
        'transforms.replica_0.type' => 'example.RecordTransform',
        'transforms.replica_0.value' => 'configured',
    ]);
});

it('updates mapping when a record output is renamed or deleted while retaining other rows', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['sources' => ['name' => ['type' => 'string']]]);

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
        'transforms' => [['type' => 'slug', 'data' => ['input' => "$['name']", 'output' => 'slug']]],
        'mapping' => [
            ['kind' => 'source', 'source' => '_idrref', 'target' => 'id', 'options' => []],
            ['kind' => 'source', 'source' => 'slug', 'target' => 'slug', 'options' => []],
            ['kind' => 'static', 'target' => 'active', 'static' => true, 'options' => []],
        ],
    ]);
    $builder = $form->getComponentByStatePath('transforms');
    $item = array_key_first($builder->getRawState());
    $output = $builder->getChildSchema($item)->getComponentByStatePath('output');

    $output->state('renamed_slug');
    $output->callAfterStateUpdated();

    $mapping = $form->getComponentByStatePath('mapping');
    expect($mapping->getSourceSchema()->toArray()['properties'])->toHaveKey('renamed_slug')->not->toHaveKey('slug');
    expect(array_column($mapping->getRawState(), 'source'))->toContain('renamed_slug')->not->toContain('slug');
    expect(collect($mapping->getState())->firstWhere('source', '_idrref')['target'])->toBe('id');
    expect(collect($mapping->getState())->firstWhere('target', 'active')['static'])->toBeTrue();
    $action = $builder->getAction('delete')(['item' => $item]);
    $action->callBefore();
    $action->call();
    expect($builder->getRawState())->toBe([]);
    $mapping = $form->getComponentByStatePath('mapping');
    expect($mapping->getSourceSchema()->toArray()['properties'])->not->toHaveKey('renamed_slug');
    expect(array_column($mapping->getRawState(), 'source'))->not->toContain('renamed_slug');
    expect(collect($mapping->getState())->firstWhere('target', 'active')['static'])->toBeTrue();
});

it('uses the preceding record schema for dependent tabs and rejects invalid order', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['sources' => ['name' => ['type' => 'string']]]);

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
        'name' => 'Dependent transforms',
        'code' => 'dependent',
        'transforms' => [
            ['type' => 'slug', 'data' => ['input' => "$['name']", 'output' => 'slug']],
            ['type' => 'slug', 'data' => ['input' => "$['slug']", 'output' => 'second_slug']],
        ],
        'mapping' => [['kind' => 'source', 'source' => '_idrref', 'target' => 'id', 'options' => []]],
    ]);
    $builder = $form->getComponentByStatePath('transforms');
    $items = array_keys($builder->getRawState());
    expect($builder->getChildSchema($items[1])->getComponentByStatePath('input')->getJsonSchema()->properties())
        ->toHaveKey('slug')
        ->not->toHaveKey('second_slug');
    expect(array_column($form->getState()['transforms'], 'type'))->toBe(['slug', 'slug']);

    $action = $builder->getAction('reorder')(['items' => array_reverse($items)]);
    $action->callBefore();
    $action->call();

    expect($form->getState(...))->toThrow(ValidationException::class);
});

it('updates a dynamic table when only the record transform changes its column type', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, ['sources' => ['name' => ['type' => 'string']]]);
    app(TransformRegistry::class)->register(new FakeTransform(
        'record_type',
        output: fn (Schema $schema, array $params) => Schema::fromArray([
            ...$schema->toArray(),
            'properties' => [...$schema->toArray()['properties'], 'name' => ['type' => $params['type']]],
        ]),
        scope: TransformScope::Record,
    ));
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'schema' => $object->schema,
        'transforms' => [new TransformSetting('record_type', ['type' => 'string'])],
        'mapping' => [TestCase::keyMapping(), new FieldMapping('name', 'name')],
    ]);
    $databaseSchema = $replica->getConnection()->getSchemaBuilder();
    expect($databaseSchema->getColumnType($replica->destination, 'name'))->toBe('varchar');

    $replica->transforms = [new TransformSetting('record_type', ['type' => 'integer'])];
    $replica->save();

    expect($databaseSchema->getColumnType($replica->destination, 'name'))->toBe('integer');
});
