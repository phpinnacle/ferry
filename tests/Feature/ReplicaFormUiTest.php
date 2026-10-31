<?php

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPinnacle\Eidos\Enums\MappingMode;
use PHPinnacle\Eidos\Forms\FieldBinding;
use PHPinnacle\Eidos\Forms\FieldMapping;
use PHPinnacle\Ferry\Data\FieldMapping as SchemaMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fakes\Transform as FakeTransform;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Transformations\Trim;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Jason\SchemaBuilder;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../Fakes/Transform.php';

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/MappingState.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';
require_once __DIR__ . '/../Fixtures/ReplicaForm.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('allows compatible field transforms on source keys for both destination types', function (DestinationType $type) {
    $destination = new StaticDestination(
        'keyed',
        'Keyed',
        'keyed',
        ['id'],
        SchemaBuilder::make(Schema::object([
            'id' => Schema::string(),
            'name' => Schema::string(),
        ]))
            ->required(['id'])
            ->build(),
    );
    app(DestinationFactory::class)->register($destination);
    app(TransformRegistry::class)->register(Trim::make());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'primary_key' => ['record_id'],
        'sources' => ['record_id' => ['type' => 'string'], 'name' => ['type' => 'string']],
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => $type,
        'destination' => $type === DestinationType::Static ? 'keyed' : null,
        'mapping' => [],
    ]);
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill([
        ...$replica->toArray(),
        'mapping' => [
            ['kind' => 'source', 'source' => 'record_id', 'target' => 'id', 'options' => []],
            ['kind' => 'source', 'source' => 'name', 'target' => 'name', 'options' => []],
        ],
    ]);
    $mapping = $form->getComponentByStatePath('mapping');
    $keys = $mapping->getSourceRowIndexes();
    $field = $form->getComponentByStatePath('mapping.' . $keys['record_id'] . '.options.transforms');
    $ordinary = $form->getComponentByStatePath('mapping.' . $keys['name'] . '.options.transforms');
    expect(array_keys($field->getAvailableBlocks()))->toContain('cast', 'filter', 'trim');
    expect(array_keys($ordinary->getAvailableBlocks()))->toContain('cast', 'filter', 'trim');
    $field->state([['type' => 'trim', 'data' => []]]);
    $state = $form->getState();
    expect(collect($state['mapping'])->firstWhere('source', 'record_id')['options']['transforms'])
        ->toBe([['type' => 'trim', 'data' => []]]);
    $replica->update($state);
    $pipeline = app(\PHPinnacle\Ferry\Origins\Kafka\TransformFactory::class)->pipeline(
        $replica,
        $replica->sourceObject(),
        ['record_id' => 'id'],
    );
    expect($pipeline['transforms.field_' . $keys['record_id'] . '_0.type'])
        ->toBe('org.phpinnacle.toblerone.TrimTransform$Key');
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('connects compatible static fields regardless of source primary-key membership', function () {
    $destination = new StaticDestination(
        key: 'keyed',
        label: 'Keyed',
        table: 'keyed',
        primaryKey: ['id', 'tenant'],
        schema: SchemaBuilder::make(Schema::object([
            'id' => Schema::string(),
            'tenant' => Schema::string(),
            'name' => Schema::string(),
        ]))
            ->required(['id', 'tenant'])
            ->build(),
    );
    app(DestinationFactory::class)->register($destination);
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'primary_key' => ['record_id', 'tenant_id'],
        'fields' => [],
        'sources' => [
            'record_id' => ['type' => 'string'],
            'tenant_id' => ['type' => 'string'],
            'owner' => ['type' => 'string'],
        ],
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'keyed',
        'mapping' => [],
    ]);
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $data = $replica->toArray();
    $form->fill([
        ...$data,
        'mapping' => MappingState::mapping([
            'id' => MappingState::source('record_id'),
            'tenant' => MappingState::source('tenant_id'),
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('mapping');

    expect($mapping->getConnectionModes())
        ->toHaveKey('record_id.id', MappingMode::Whole)
        ->toHaveKey('tenant_id.tenant', MappingMode::Whole)
        ->toHaveKey('owner.name', MappingMode::Whole)
        ->toHaveKey('record_id.name', MappingMode::Whole)
        ->toHaveKey('tenant_id.name', MappingMode::Whole)
        ->toHaveKey('owner.id', MappingMode::Whole)
        ->toHaveKey('owner.tenant', MappingMode::Whole);
    expect($mapping->getStaticFormSchema('id'))
        ->toBeNull()
        ->and($mapping->getStaticFormSchema('tenant'))
        ->toBeNull()
        ->and($mapping->getStaticFormSchema('name'))
        ->not->toBeNull();
    $replica->fill($form->getState());
    expect($replica->columnMap())->toBe(['record_id' => 'id', 'tenant_id' => 'tenant']);

    $form->fill([
        ...$data,
        'mapping' => MappingState::mapping([
            'id' => MappingState::source('owner'),
            'tenant' => MappingState::source('tenant_id'),
        ]),
    ]);
    expect(collect($form->getState()['mapping'])->firstWhere('target', 'id')['source'])->toBe('owner');

    expect(fn () => TestCase::replicaFormState([
        'name' => 'Keyed',
        'mapping' => [
            ['kind' => 'static', 'target' => 'id', 'static' => 'constant', 'options' => []],
            ['kind' => 'source', 'source' => 'tenant_id', 'target' => 'tenant', 'options' => []],
        ],
    ], $replica))
        ->toThrow(ValidationException::class);
});

it('derives static keys from destination mappings without requiring a complete source key', function (
    array $primaryKey,
    array $bindings,
) {
    app(DestinationFactory::class)->register(new StaticDestination(
        'keyed',
        'Keyed',
        'keyed',
        ['id'],
        SchemaBuilder::make(Schema::object(['id' => Schema::string(), 'name' => Schema::string()]))
            ->required(['id'])
            ->build(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'primary_key' => $primaryKey,
        'sources' => ['record_id' => ['type' => 'string'], 'tenant_id' => ['type' => 'string']],
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'keyed',
        'mapping' => [],
    ]);
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill([...$replica->toArray(), 'mapping' => MappingState::mapping($bindings)]);
    expect(collect($form->getState()['mapping'])->firstWhere('target', 'id')['source'])->toBe('record_id');
})->with([
    'missing component' => [['record_id', 'tenant_id'], ['id' => ['kind' => 'source', 'source' => 'record_id']]],
    'ordinary target' => [
        ['record_id', 'tenant_id'],
        [
            'id' => ['kind' => 'source', 'source' => 'record_id'],
            'name' => ['kind' => 'source', 'source' => 'tenant_id'],
        ],
    ],
    'no source key' => [[], ['id' => ['kind' => 'source', 'source' => 'record_id']]],
]);

it('marks source keys required in both editors without replacing other required fields', function (DestinationType $type) {
    app(DestinationFactory::class)->register(new StaticDestination(
        'keyed',
        'Keyed',
        'keyed',
        ['id'],
        SchemaBuilder::make(Schema::object(['id' => Schema::string()]))
            ->required(['id'])
            ->build(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'primary_key' => ['record_id'],
        'schema' => Schema::fromArray([
            'type' => 'object',
            'properties' => ['record_id' => ['type' => 'string'], 'name' => ['type' => 'string']],
            'required' => ['name'],
        ]),
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => $type,
        'destination' => $type === DestinationType::Static ? 'keyed' : null,
        'mapping' => [],
    ]);
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill($replica->toArray());
    $mapping = $form->getComponentByStatePath('mapping');
    expect(array_column($mapping->getSources(), 'required', 'id'))->toBe(['record_id' => true, 'name' => true]);
    expect($object->schema->required())->toBe(['name']);
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('requires explicit composite key bindings and creates the dynamic table on the first complete save', function () {
    config(['database.connections.sqlite.host' => 'db.internal', 'database.connections.sqlite.port' => 5432]);
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'primary_key' => ['tenant_id', 'record_id'],
        'fields' => [],
        'sources' => [
            'tenant_id' => ['type' => 'integer', 'title' => 'Tenant'],
            'record_id' => ['type' => 'string', 'maxLength' => 20, 'title' => 'ID'],
            'display_name' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Name'],
        ],
    ]);
    $replica = TestCase::makeReplica(['upstream_id' => $upstream->id, 'mapping' => []]);
    $database = $replica->getConnection();
    expect($database->getSchemaBuilder()->hasTable($replica->destination))->toBeFalse();
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $data = $replica->toArray();
    $form->fill([
        ...$data,
        'mapping' => MappingState::binding([
            'tenant_id' => 'tenant',
            'display_name' => 'name',
            'id' => ['value' => 'constant'],
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('mapping');
    expect(array_column(array_filter($mapping->getSources(), fn (array $source) => $source['required']), 'id'))
        ->toBe(['tenant_id', 'record_id']);
    view()->share('errors', new \Illuminate\Support\ViewErrorBag);
    expect($mapping->toHtml())->toContain('eidos-mapping-required');
    expect($form->getState(...))
        ->toThrow(ValidationException::class, 'record_id');
    expect($database->getSchemaBuilder()->hasTable($replica->destination))->toBeFalse();

    $form->fill([
        ...$data,
        'mapping' => MappingState::binding([
            'record_id' => 'id',
            'tenant_id' => 'tenant',
            'display_name' => 'name',
        ]),
    ]);
    $replica->update($form->getState());
    $index = array_find(
        $database->getSchemaBuilder()->getIndexes($replica->destination),
        fn (array $index) => $index['primary'],
    );
    expect($index['columns'])->toBe(['tenant', 'id']);
    expect($database->getSchemaBuilder()->getColumnListing($replica->destination))->toBe(['id', 'tenant', 'name']);
    $database
        ->table($replica->destination)
        ->insert([
            ['tenant' => 1, 'id' => 'same-id', 'name' => 'One'],
            ['tenant' => 2, 'id' => 'same-id', 'name' => 'Two'],
        ]);
    expect(fn () => $database->table($replica->destination)->insert(['tenant' => 1, 'id' => 'same-id']))
        ->toThrow(\Illuminate\Database\QueryException::class);

    $destination = app(DestinationFactory::class)->resolve($replica);
    expect($destination->getPrimaryKey())->toBe(['tenant', 'id']);
    $config = app(\PHPinnacle\Ferry\Origins\Kafka\SinkConfigFactory::class)->make(
        $destination,
        $replica,
        $object,
        'customers',
    );
    expect($config)->toMatchArray([
        'pk.mode' => 'record_key',
        'pk.fields' => 'tenant,id',
        'transforms.rename_keys.renames' => 'tenant_id:tenant,record_id:id',
        'delete.enabled' => 'true',
    ]);
});

it('accepts UUID transform outputs for reference destinations', function () {
    app(TransformRegistry::class)->register(
        new FakeTransform(
            'as_uuid',
            accepts: fn (Schema $input) => $input->singleType() === 'string',
            output: fn () => Schema::fromArray(['type' => 'string', 'format' => 'uuid']),
        ),
    );
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'owners',
        label: 'Owners',
        table: 'owners',
        primaryKey: ['id'],
        schema: SchemaBuilder::make(Schema::object([
            'id' => Schema::string(),
            'owner' => SchemaBuilder::make()
                ->type('string')
                ->format('uuid')
                ->with('x-ferry-reference', true)
                ->title('Owner')
                ->build(),
        ]))
            ->required(['id', 'owner'])
            ->build(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        '_owner' => Schema::fromArray([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]),
        '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
    ]]);

    $form = TestCase::replicaForm([
        'name' => 'Owners',
        'code' => 'owners',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'owners',
        'mapping' => MappingState::mapping([
            'id' => MappingState::source('_idrref'),
            'owner' => MappingState::source('_owner'),
        ], [
            '_description' => ['transforms' => [['type' => 'as_uuid', 'data' => []]]],
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('mapping');
    expect($mapping->getSourceSchema()->toArray()['properties']['_owner'])->toMatchArray([
        'type' => 'string',
        'format' => 'uuid',
        'x-ferry-reference' => true,
    ]);
    expect($mapping->getConnectionModes())
        ->toHaveKey('_owner.owner', MappingMode::Whole)
        ->toHaveKey('_description.owner', MappingMode::Whole);
    $state = $form->getState();
    $owner = collect($state['mapping'])->firstWhere('source', '_owner');
    expect($mapping->getOutputSchema()->toArray()['properties']['_owner'])->toMatchArray([
        'type' => 'string',
        'format' => 'uuid',
        'x-ferry-reference' => true,
    ]);
    expect(
        app(ReplicaSchemaResolver::class)
            ->mappingSchema(SchemaMapping::fromArray($owner), $form->getRecord()->schema)
            ?->singleType(),
    )
        ->toBe('string');

    $form->fill([
        'name' => 'Owners',
        'code' => 'owners',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'owners',
        'mapping' => MappingState::mapping([
            'id' => MappingState::source('_idrref'),
            'owner' => MappingState::source('_description'),
        ], [
            '_description' => ['transforms' => [['type' => 'as_uuid', 'data' => []]]],
        ]),
    ]);
    $transformed = collect($form->getState()['mapping'])->firstWhere('source', '_description');
    expect($transformed['target'])->toBe('owner');
    expect($mapping->getOutputSchema()->toArray()['properties']['_description'])->toBe([
        'type' => 'string',
        'format' => 'uuid',
    ]);
});

it('refreshes transform inputs when a source schema changes without renaming fields', function (DestinationType $type) {
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'sources' => [
            'first' => ['type' => 'string', 'maxLength' => 10],
            'second' => ['type' => 'integer'],
        ],
    ]);
    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => $type->value,
        'destination' => $type === DestinationType::Static ? 'customers' : null,
    ]);
    $mapping = $form->getComponentByStatePath('mapping');
    $indexes = $mapping->getSourceRowIndexes();
    $first = $form->getComponentByStatePath('mapping.' . $indexes['first'] . '.options.transforms');
    $second = $form->getComponentByStatePath('mapping.' . $indexes['second'] . '.options.transforms');
    expect($first->getInputSchema()->effectiveMaxLength())->toBe(10);
    expect($second->getInputSchema()->singleType())->toBe('integer');
    $source = Schema::fromArray([
        '$defs' => [
            'fields' => [
                'properties' => [
                    'first' => ['type' => 'boolean'],
                    'second' => ['type' => 'string', 'maxLength' => 12],
                ],
            ],
        ],
        'allOf' => [['$ref' => '#/$defs/fields']],
    ]);
    if ($mapping instanceof FieldMapping) {
        $mapping->jsonSchema(source: $source);
    } else {
        $mapping->jsonSchema($source);
    }

    expect($first->getInputSchema()->singleType())->toBe('boolean');
    expect($second->getInputSchema()->singleType())->toBe('string');
    expect($second->getInputSchema()->effectiveMaxLength())->toBe(12);
    $first->state([['type' => 'cast', 'data' => ['type' => 'string']]]);
    expect($first->getInputSchema()->singleType())->toBe('string');
    expect($second->getInputSchema()->effectiveMaxLength())->toBe(12);
    expect($mapping->getSourceSettings()['first']['transforms'])
        ->toBe([['type' => 'cast', 'data' => ['type' => 'string']]]);
    expect($mapping->getSourceSettings()['second']['transforms'])->toBe([]);
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('updates derived mapping sources through Livewire while retaining existing bindings', function (DestinationType $type) {
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['sources' => ['name' => ['type' => 'string']]]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, [
        'state' => [
            'upstream_id' => $upstream->id,
            'type' => $type->value,
            'destination' => $type === DestinationType::Static ? 'customers' : null,
            'mapping' => [['kind' => 'source', 'source' => 'name', 'target' => 'name', 'options' => []]],
        ],
    ]);
    $page->callAction(TestAction::make('add')->schemaComponent('transforms', 'form'), arguments: [
        'block' => 'slug',
    ])->assertHasNoActionErrors();
    $item = array_key_first($page->get('data.transforms'));
    $page->set('data.transforms.' . $item . '.data.input', "$['name']")->call('$refresh');
    $mapping = $page->instance()->form->getComponentByStatePath('mapping');
    expect($mapping->getSourceSchema()->properties())->toHaveKey('slug');
    expect(collect($page->get('data.mapping'))->firstWhere('source', 'name')['target'])->toBe('name');

    $page->callAction(TestAction::make('delete')->schemaComponent('transforms', 'form'), arguments: [
        'item' => $item,
    ])->assertHasNoActionErrors();
    $page->call('$refresh');
    $mapping = $page->instance()->form->getComponentByStatePath('mapping');
    expect($mapping->getSourceSchema()->properties())->not->toHaveKey('slug');
    expect(collect($page->get('data.mapping'))->firstWhere('source', 'name')['target'])->toBe('name');
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('does not apply a submitted transform action to a disabled editor', function () {
    app(TransformRegistry::class)->register(Trim::make());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['sources' => ['name' => ['type' => 'string']]]);
    \PHPinnacle\Ferry\Forms\FieldTransforms::configureUsing(
        fn (\PHPinnacle\Ferry\Forms\FieldTransforms $field) => $field->disabled(),
        function () use ($upstream) {
            $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, ['state' => [
                'upstream_id' => $upstream->id,
            ]]);
            $mapping = $page->instance()->form->getComponentByStatePath('mapping');
            $path = 'mapping.' . $mapping->getSourceRowIndex('name') . '.options.transforms';

            $page->callAction(TestAction::make('fieldTransforms')
                ->schemaComponent($path, 'form')
                ->arguments(['block' => 'trim']));

            $page->assertSet('data.' . $path, []);
        },
    );
});

it('keeps timestamp settings and output types after Livewire updates and editing repeated instances', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['epoch' => Schema::fromArray(['type' => 'integer'])]]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, ['state' => [
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
    ]]);
    $field = $page->instance()->form->getComponentByStatePath('mapping');
    $path = 'mapping.' . $field->getSourceRowIndex('epoch') . '.options.transforms';
    $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
    $page->callAction($action->arguments(['block' => 'timestamp_converter']), ['data' => [
        'target_type' => 'Timestamp',
        'unix_precision' => 'microseconds',
    ]])->assertHasNoActionErrors();
    $page->call('$refresh');
    expect($page->instance()->form->getComponentByStatePath($path)->getInputSchema()->format())->toBe('date-time');
    $page->callAction($action->arguments(['block' => 'timestamp_converter']), ['data' => [
        'target_type' => 'string',
        'format' => 'yyyy-MM-dd',
        'unix_precision' => 'milliseconds',
    ]])->assertHasNoActionErrors();
    $page->callAction($action->arguments(['block' => 'timestamp_converter', 'index' => 1]), ['data' => [
        'target_type' => 'string',
        'format' => 'HH:mm:ss',
        'unix_precision' => 'milliseconds',
    ]])->assertHasNoActionErrors();
    $page->call('$refresh');
    $editor = $page->instance()->form->getComponentByStatePath($path);
    expect($editor->getContainer()->getState()['transforms'])->toBe([
        ['type' => 'timestamp_converter', 'data' => ['target_type' => 'Timestamp', 'unix_precision' => 'microseconds']],
        [
            'type' => 'timestamp_converter',
            'data' => ['target_type' => 'string', 'format' => 'HH:mm:ss', 'unix_precision' => 'milliseconds'],
        ],
    ]);
    expect($editor->getInputSchema()->toArray())->toBe([
        'type' => 'string',
    ]);
});

it('updates only static cards and the changed transform editor while preserving neighboring state', function () {
    app(TransformRegistry::class)->register(Trim::make());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $properties = [];
    for ($index = 0; $index < 3; $index++) {
        $properties['_fld' . $index] = ['type' => 'string', 'maxLength' => 100, 'title' => 'Field ' . $index];
    }

    TestCase::makeItem($upstream, ['sources' => $properties]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, ['state' => [
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
    ]]);
    $sourceKey = $page->instance()->form->getComponentByStatePath('mapping')->getSourceRowIndex('_fld2');
    $page->set('data.mapping.' . $sourceKey . '.target', 'product_code');

    $page->callAction(TestAction::make('addConstant')->schemaComponent('mapping', 'form'));
    expect($page->effects)->not->toHaveKey('html');
    expect(implode('', $page->effects['partials']))
        ->toContain('data-binding-constant=')
        ->not->toContain('data-binding-source=');
    $field = $page->instance()->form->getComponentByStatePath('mapping');
    $constant = array_key_first($field->getConstants());
    $constantPath = $field->getConstants()[$constant]->getStatePath();
    $page->set($constantPath . '.static', 'value');
    $draft = $page->get('data.mapping');

    $path = 'mapping.' . $field->getSourceRowIndexes()['_fld0'] . '.options.transforms';
    $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
    $page->callAction($action->arguments(['block' => 'trim']));
    $page->assertSet('data.' . $path, [['type' => 'trim', 'data' => []]]);
    expect($page->effects)->not->toHaveKey('html');
    expect(array_keys($page->effects['partials']))->toBe(['schema-component::form.' . $path]);

    $page->callAction($action->arguments(['block' => 'trim', 'index' => 0, 'remove' => true]));
    $page->assertSet('data.' . $path, []);
    expect(array_keys($page->effects['partials']))->toBe(['schema-component::form.' . $path]);
    expect($page->get('data.mapping'))->toBe($draft);

    $page->callAction(TestAction::make('removeConstant')
        ->schemaComponent('mapping', 'form')
        ->arguments([
            'constant' => $constant,
        ]));
    expect($page->effects)->not->toHaveKey('html');
    expect(implode('', $page->effects['partials']))->not->toContain('data-binding-constant=', 'data-binding-source=');
    $page->assertSet('data.mapping.' . $sourceKey . '.target', 'product_code');
});

it('refreshes static mapping compatibility when transform actions change the source type', function () {
    app(TransformRegistry::class)->register(Trim::make());
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
        '_code' => Schema::fromArray(['type' => 'string', 'maxLength' => 9, 'minLength' => 9]),
    ]]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, ['state' => [
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'customers',
    ]]);
    $field = $page->instance()->form->getComponentByStatePath('mapping');
    expect($field->getConnectionModes()['_description'])
        ->toHaveKeys(['name', 'tax_number'])
        ->not->toHaveKey('is_active');
    $path = 'mapping.' . $field->getSourceRowIndexes()['_description'] . '.options.transforms';
    $sourceIndex = $field->getSourceRowIndex('_description');
    $page->set('data.mapping.' . $sourceIndex . '.target', 'name');
    $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
    $page->callAction($action->arguments(['block' => 'cast']), ['data' => ['type' => 'boolean']]);
    expect($page->instance()->form->getComponentByStatePath('mapping')->getConnectionModes()['_description'])
        ->toBe(['is_active' => MappingMode::Whole]);
    expect($page->effects)->not->toHaveKey('html');
    expect($page->effects['partials']['schema-component::form.mapping'])
        ->toContain('data-mapping-source-settings="_description"', '>boolean</span>', 'updateConfiguration(');
    $page->callAction($action->arguments(['block' => 'cast', 'index' => 0, 'remove' => true]));
    expect($page->instance()->form->getComponentByStatePath('mapping')->getConnectionModes()['_description'])
        ->toHaveKeys(['name', 'tax_number'])
        ->not->toHaveKey('is_active');
    expect($page->effects['partials'])->toHaveKey('schema-component::form.mapping');
    $page->assertSet('data.mapping.' . $sourceIndex . '.target', 'name');

    expect($page->instance()->form->getComponentByStatePath('mapping')->getStaticFormSchema('is_active'))
        ->not->toBeNull();
});

it('supplies saved source descriptions and constraints to both mapping editors', function (
    string $destination,
    ?string $comment,
) {
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_idrref' => Schema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_code' => Schema::fromArray(['type' => 'string', 'maxLength' => 8, 'minLength' => 8]),
            '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
        ],
        'sources' => [
            '_fld17' => [
                'type' => 'number',
                'x-ferry-precision' => 20,
                'x-ferry-scale' => 2,
                'minimum' => 0,
                'multipleOf' => 0.01,
                'exclusiveMaximum' => 1_000_000_000_000_000_000,
                'title' => 'Price',
                ...($comment === null ? [] : ['description' => $comment]),
            ],
            '_fld18' => [
                'type' => 'integer',
                'x-ferry-precision' => 10,
                'x-ferry-scale' => 0,
                'exclusiveMinimum' => -10_000_000_000,
                'exclusiveMaximum' => 10_000_000_000,
                'title' => 'Payment days',
                ...($comment === null ? [] : ['description' => $comment]),
            ],
        ],
    ]);

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => $destination === 'dynamic' ? 'dynamic' : 'static',
        'destination' => $destination === 'dynamic' ? null : $destination,
        'mapping' => [],
    ]);
    $mapping = $form->getComponentByStatePath('mapping');
    $sources = array_column($mapping->getSources(), null, 'id');
    expect($sources['_code']['presentation']['constraints'])
        ->toBe([['kind' => 'length', 'value' => '8', 'scope' => []]])
        ->and($sources['_description']['presentation']['constraints'])
        ->toBe([['kind' => 'length', 'value' => '≤ 100', 'scope' => []]])
        ->and($sources['_fld17']['description'])
        ->toBe($comment)
        ->and($sources['_fld18']['description'])
        ->toBe($comment)
        ->and($sources['_code']['description'])
        ->toBeNull()
        ->and($sources['_fld17']['presentation']['constraints'])
        ->toBe([
            ['kind' => 'range', 'value' => '≥ 0 · < 1000000000000000000', 'scope' => []],
            ['kind' => 'multiple', 'value' => '× 0.01', 'scope' => []],
        ]);
    $key = $mapping->getSourceRowIndexes()['_code'];
    expect(
        $form
            ->getComponentByStatePath('mapping.' . $key . '.options.transforms')
            ->getInputSchema()
            ->toArray(),
    )
        ->toMatchArray(['type' => 'string', 'minLength' => 8, 'maxLength' => 8]);
})->with([DestinationType::Dynamic->value, 'customers'])
    ->with([null, "Imported from 1C.\nKeep <original> text."]);

/** @return Collection<string, Select> */
$selects = function (array $state) {
    $livewire = SchemaHost::make();
    $livewire->data = $state;

    return collect(
        ReplicaForm::creation(
            FilamentSchema::make($livewire)
                ->statePath('data')
                ->operation('create'),
        )->getFlatComponents(),
    )
        ->filter(fn ($component) => $component instanceof Select)
        ->keyBy(fn (Select $field) => $field->getName());
};

it('defaults to the first available upstream and object with a dynamic destination', function () {
    TestCase::makeUpstream(['code' => 'inactive', 'name' => 'A inactive', 'is_active' => false]);
    TestCase::makeUpstream(['code' => 'preparing', 'name' => 'A preparing']);
    TestCase::makeUpstream(['code' => 'later', 'name' => 'Z connection'], ['status' => StructureStatus::Ready]);
    $upstream = TestCase::makeUpstream(['name' => 'B connection'], [
        'status' => StructureStatus::Ready,
        'generation' => 1,
    ]);
    TestCase::makeItem($upstream, ['title' => 'Z object']);
    $first = TestCase::makeItem($upstream, [
        'external_id' => 'first',
        'name' => '_reference1',
        'title' => 'A object',
    ]);
    app(DestinationFactory::class)->register(customers_destination());

    $livewire = SchemaHost::make();

    $form = ReplicaForm::creation(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill();

    expect($livewire->data)->toMatchArray([
        'upstream_id' => $upstream->id,
        'source' => $first->external_id,
        '_destination' => 'dynamic',
    ]);
    foreach (['upstream_id', 'source'] as $name) {
        expect($form->getComponent($name)->canSelectPlaceholder())->toBeFalse();
    }
});

it('leaves unavailable upstreams and objects unselected', function () {
    $livewire = SchemaHost::make();

    $form = ReplicaForm::creation(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill();

    expect($livewire->data)->toMatchArray([
        'upstream_id' => null,
        'source' => null,
        '_destination' => 'dynamic',
    ]);
});

it('selects the first object without adding mapping state when changing upstreams during creation', function (bool $hasObjects) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $next = TestCase::makeUpstream(['code' => 'next'], ['status' => StructureStatus::Ready, 'generation' => 1]);
    if ($hasObjects) {
        TestCase::makeItem($next, [
            'external_id' => 'next-object',
            'sources' => ['next_only' => ['type' => 'boolean']],
        ]);
    }

    $livewire = SchemaHost::make();
    $form = ReplicaForm::creation(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        '_destination' => 'dynamic',
    ]);

    $form->getComponent('upstream_id')->state($next->id)->callAfterStateUpdated();

    expect($livewire->data['source'])->toBe($hasObjects ? 'next-object' : null);
    expect($livewire->data)->not->toHaveKeys(['schema', 'mapping', 'transforms']);
})->with([true, false]);

it('binds dynamic fields and connects static fields to the declared destination fields', function () {
    app(DestinationFactory::class)->register(customers_destination());

    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_idrref' => Schema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
        ],
        'sources' => [
            '_fld1' => [
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
                'title' => 'Title 1',
            ],
        ],
    ]);

    $form = fn (string $destination) => TestCase::replicaForm([
        'code' => $destination === '' ? 'dynamic_replica' : 'static_replica',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => $destination === '' ? DestinationType::Dynamic : DestinationType::Static,
        'destination' => $destination === '' ? null : $destination,
    ]);

    $dynamicForm = $form('');
    $staticForm = $form('customers');

    /** @var FieldBinding $binding */
    $binding = $dynamicForm->getComponentByStatePath('mapping');
    /** @var FieldMapping $mapping */
    $mapping = $staticForm->getComponentByStatePath('mapping');

    expect(array_column($binding->getSources(), 'label', 'id'))
        ->toBe([
            '_idrref' => __('phpinnacle-ferry::resources.replica.system_fields._idrref'),
            '_description' => __('phpinnacle-ferry::resources.replica.system_fields._description'),
            '_fld1' => 'Title 1',
        ])
        ->and(array_column($binding->getSources(), 'name', 'id'))
        ->toBe(['_idrref' => '_idrref', '_description' => '_description', '_fld1' => '_fld1'])
        ->and(array_column($mapping->getSources(), 'type', 'id'))
        ->toBe(['_idrref' => 'string', '_description' => 'string', '_fld1' => 'string'])
        ->and(array_column($mapping->getTargets(), 'required', 'id'))
        ->toBe(['customer_id' => true, 'name' => true, 'tax_number' => true, 'is_active' => false])
        ->and(array_column($mapping->getTargets(), 'type', 'id'))
        ->toBe(['customer_id' => 'string', 'name' => 'string', 'tax_number' => 'string', 'is_active' => 'boolean']);

    expect($mapping->getConnectionModes()['_fld1'])->toBe([
        'customer_id' => MappingMode::Whole,
        'name' => MappingMode::Whole,
        'tax_number' => MappingMode::Whole,
    ]);

    $schema = $staticForm;
    $schema->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'customers',
        'mapping' => [],
    ]);
    $mapping = $schema->getComponentByStatePath('mapping');
    $key = $mapping->getSourceRowIndexes()['_fld1'];
    $schema
        ->getComponentByStatePath('mapping.' . $key . '.options.transforms')
        ->state([['type' => 'cast', 'data' => ['type' => 'string']]]);
    expect($mapping->getConnectionModes()['_fld1'])
        ->toBe(['name' => MappingMode::Whole, 'tax_number' => MappingMode::Whole]);
});

it('validates the static mapping against the declared destination fields', function (array $mapping, bool $valid) {
    app(DestinationFactory::class)->register(customers_destination());

    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_idrref' => Schema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_code' => Schema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
        ],
        'sources' => [],
    ]);

    $livewire = SchemaHost::make();
    $livewire->data = [
        'name' => 'Customers',
        'code' => 'customers',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'customers',
        'mapping' => MappingState::mapping($mapping),
    ];

    $schema = TestCase::replicaForm($livewire->data, livewire: $livewire);

    if ($valid) {
        expect($schema->getState()['mapping'])
            ->toBe(MappingState::mapping($mapping + ['is_active' => MappingState::value(false)], [
                '_description' => ['transforms' => []],
                '_code' => ['transforms' => []],
                '_idrref' => ['transforms' => []],
            ]))
            ->and(
                $schema->getRecord()->type,
            )
            ->toBe(DestinationType::Static);

        return;
    }

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'valid mapping' => [
        [
            'customer_id' => MappingState::source('_idrref'),
            'name' => MappingState::source('_description'),
            'tax_number' => MappingState::source('_code'),
        ],
        true,
    ],
    'missing required field' => [
        [
            'customer_id' => MappingState::source('_idrref'),
            'name' => MappingState::source('_description'),
        ],
        false,
    ],
    'incompatible types' => [
        [
            'customer_id' => MappingState::source('_idrref'),
            'name' => MappingState::source('_description'),
            'tax_number' => MappingState::source('_code'),
            'is_active' => MappingState::source('_code'),
        ],
        false,
    ],
    'empty mapping' => [[], false],
]);

it('validates the bound column names of a dynamic replica', function (string $column, bool $valid) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $replica = TestCase::makeReplica(['upstream_id' => $upstream->id]);

    $livewire = SchemaHost::make();

    $schema = ReplicaForm::configure(
        FilamentSchema::make($livewire)
            ->statePath('data')
            ->record($replica)
            ->operation('edit'),
    );
    $schema->fill([
        'name' => $replica->name,
        'code' => $replica->code,
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
        'mapping' => MappingState::binding(['_idrref' => $column]),
    ]);

    if ($valid) {
        expect($schema->getState()['mapping'])
            ->toBe(MappingState::binding(['_idrref' => $column], ['_idrref' => ['transforms' => []]]));

        return;
    }

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'valid column' => ['external_id', true],
    'uppercase column' => ['External', false],
]);

it('binds native Filter parameters to an unbound source through Livewire', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['sources' => ['active' => ['type' => 'boolean']]]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, ['state' => [
        'upstream_id' => $upstream->id,
    ]]);
    $mapping = $page->instance()->form->getComponentByStatePath('mapping');
    $index = $mapping->getSourceRowIndex('active');
    $path = 'mapping.' . $index . '.options.transforms';
    $parameters = ['mode' => 'include', 'condition' => '@ == true', 'missing_or_null_behavior' => 'fail'];

    $page
        ->callAction(
            TestAction::make('fieldTransforms')->schemaComponent($path, 'form')->arguments(['block' => 'filter']),
            ['data' => $parameters],
        )
        ->assertHasNoActionErrors()
        ->call('$refresh');

    $page->assertSet('data.' . $path, [['type' => 'filter', 'data' => $parameters]]);
    $page->assertSet('data.mapping.' . $index . '.target', null);
});

it('orders sources by priority and alphabetical title in the database', function () use ($selects) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $objects = [
        [0, 'Customers'],
        [0, 'advances'],
        [1, 'Order'],
        [2, '(Obsolete) Old'],
        [0, 'Product 10'],
        [0, 'Product 2'],
    ];

    foreach ($objects as $index => [$priority, $title]) {
        StructureItems::make([
            'upstream_id' => $upstream->id,
            'external_id' => 'object-' . $index,
            'name' => '_reference' . $index,
            'code' => $index,
            'priority' => $priority,
            'label' => 'Label' . $index,
            'title' => $title,
            'fields' => [],
            'sources' => [],
            'values' => [],
            'position' => $index,
            'revision' => $upstream->structure->generation,
        ])->save();
    }

    $options = $selects(['upstream_id' => $upstream->id])->get('source')?->getOptions();

    expect(array_values($options ?? []))->toBe([
        'advances',
        'Customers',
        'Product 10',
        'Product 2',
        'Order',
        '(Obsolete) Old',
    ]);
});

it('loads at most twenty sources and searches beyond the initial options without losing selected labels', function () use (
    $selects,
) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    for ($index = 0; $index < 35; $index++) {
        TestCase::makeItem($upstream, [
            'external_id' => 'source-' . $index,
            'title' => sprintf('Source %02d', $index),
            'position' => $index,
        ]);
    }

    TestCase::makeItem($upstream, ['external_id' => 'draft', 'title' => 'Source draft', 'revision' => 2]);
    TestCase::makeItem($upstream, [
        'external_id' => 'child',
        'title' => 'Source child',
        'parent_id' => $upstream->structure->publishedItem('source-0')->id,
    ]);
    $otherUpstream = TestCase::makeUpstream(['code' => 'other'], ['generation' => 1]);
    TestCase::makeItem($otherUpstream, ['title' => 'Source foreign']);

    $fields = $selects(['upstream_id' => $upstream->id, 'source' => 'source-34']);
    $source = $fields->get('source');
    $retrieved = 0;
    Event::listen(
        'eloquent.retrieved: ' . StructureItem::class,
        function () use (&$retrieved) {
            $retrieved++;
        },
    );
    expect($source->getOptions())->toHaveCount(20)->not->toHaveKey('source-34');
    expect($retrieved)->toBe(20);
    expect($source->getSearchResults('SOURCE 3'))
        ->toBe([
            'source-30' => 'Source 30',
            'source-31' => 'Source 31',
            'source-32' => 'Source 32',
            'source-33' => 'Source 33',
            'source-34' => 'Source 34',
        ])
        ->and($source->getSearchResults('Source'))
        ->toHaveCount(20)
        ->and($source->getSearchResults('draft'))
        ->toBe([])
        ->and($source->getSearchResults('child'))
        ->toBe([])
        ->and($source->getSearchResults('foreign'))
        ->toBe([])
        ->and($source->getOptionLabel())
        ->toBe('Source 34');
});

it('lists dynamic first and keeps registered static destination keys distinct', function () use ($selects) {
    app(DestinationFactory::class)->register(customers_destination());
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'dynamic',
        label: 'Static destination named dynamic',
        table: 'other_customers',
        primaryKey: ['customer_id'],
        schema: customers_destination()->getSchema(),
    ));

    $fields = $selects([]);

    expect($fields->keys()->all())->toBe(['upstream_id', 'source', '_destination']);
    expect($fields->get('_destination')->isSearchable())->toBeTrue();
    expect($fields->get('_destination')->getOptions())->toBe([
        'dynamic' => DestinationType::Dynamic->getLabel(),
        'static:customers' => 'Customers',
        'static:dynamic' => 'Static destination named dynamic',
    ]);
});

it('validates the combined destination selection at the creation boundary', function (mixed $destination) {
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $livewire = SchemaHost::make();
    $form = ReplicaForm::creation(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'Customers',
        'code' => 'customers',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
    ]);
    $livewire->data['_destination'] = $destination;

    expect($form->getState(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('data._destination'),
        );
})->with([
    'missing' => [null],
    'unregistered' => ['static:missing'],
    'unknown' => ['missing'],
    'array' => [['static:customers']],
]);

it('dehydrates the selected destination into hidden replica attributes', function (
    string $selection,
    DestinationType $type,
    ?string $destination,
) {
    app(DestinationFactory::class)->register(customers_destination(), new StaticDestination(
        key: 'dynamic',
        label: 'Static destination named dynamic',
        table: 'other_customers',
        primaryKey: ['customer_id'],
        schema: customers_destination()->getSchema(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $form = ReplicaForm::creation(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'Customers',
        'code' => 'customers',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        '_destination' => $selection,
        'type' => 'tampered',
        'destination' => 'tampered',
    ]);

    expect($form->getComponent('type'))->toBeInstanceOf(\Filament\Forms\Components\Hidden::class);
    expect($form->getComponent('destination'))->toBeInstanceOf(\Filament\Forms\Components\Hidden::class);
    expect($form->getState())
        ->toMatchArray(['type' => $type, 'destination' => $destination])
        ->not->toHaveKey('_destination');
})->with([
    'dynamic' => ['dynamic', DestinationType::Dynamic, null],
    'static' => ['static:customers', DestinationType::Static, 'customers'],
    'static named dynamic' => ['static:dynamic', DestinationType::Static, 'dynamic'],
]);

it('keeps mapping state out of creation when switching destinations', function (string $from, string $to) {
    app(DestinationFactory::class)->register(customers_destination());
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'other_customers',
        label: 'Other customers',
        table: 'other_customers',
        primaryKey: ['customer_id'],
        schema: customers_destination()->getSchema(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_description' => Schema::fromArray([
        'type' => 'string',
        'maxLength' => 100,
    ])]]);
    $livewire = SchemaHost::make();
    $form = ReplicaForm::creation(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        '_destination' => $from === 'dynamic' ? 'dynamic' : 'static:' . $from,
    ]);

    $form->getComponent('source')->state('object-0')->callAfterStateUpdated();
    $selection = $to === 'dynamic' ? 'dynamic' : 'static:' . $to;
    $form->getComponent('_destination')->state($selection)->callAfterStateUpdated();

    expect($livewire->data['_destination'])->toBe($selection);
    expect($livewire->data)->not->toHaveKeys(['schema', 'mapping', 'transforms']);
})->with([
    'dynamic to static' => ['dynamic', 'customers'],
    'static to dynamic' => ['customers', 'dynamic'],
    'static to another static' => ['customers', 'other_customers'],
]);

it('switches creation selections without initializing mapping state across Livewire requests', function () {
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, [
        'creating' => true,
        'state' => [
            'upstream_id' => $upstream->id,
            'source' => 'object-0',
            '_destination' => 'dynamic',
        ],
    ]);
    expect($page->get('data'))->not->toHaveKeys(['schema', 'mapping', 'transforms']);

    $page
        ->set('data._destination', 'static:customers')
        ->set('data.source', 'object-0')
        ->call('$refresh');

    $page->assertSet('data._destination', 'static:customers');
    expect($page->get('data'))->not->toHaveKeys(['schema', 'mapping', 'transforms']);

    $page->set('data._destination', 'dynamic')->call('$refresh');

    $page->assertSet('data._destination', 'dynamic');
    expect($page->get('data'))->not->toHaveKeys(['schema', 'mapping', 'transforms']);
});

it('stores physical source names directly', function (DestinationType $type) {
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $property = ['_fld17' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Email']];
    $object = TestCase::makeItem($upstream, [
        'fields' => ['_code' => Schema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11])],
        'sources' => $property,
    ]);
    $transforms = [[
        'type' => 'filter',
        'data' => [
            'mode' => 'exclude',
            'condition' => "@ == 'blocked'",
            'missing_or_null_behavior' => 'include',
        ],
    ]];
    $livewire = SchemaHost::make();

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'source' => $object->external_id,
        'type' => $type->value,
        'destination' => $type === DestinationType::Static ? 'customers' : null,
        'name' => 'Physical names',
        'code' => 'physical_names',
        'mapping' => [
            [
                'kind' => 'source',
                'source' => '_idrref',
                'target' => $type === DestinationType::Dynamic ? 'external_id' : 'customer_id',
                'options' => [],
            ],
            ['kind' => 'source', 'source' => '_fld17', 'target' => 'name', 'options' => ['transforms' => $transforms]],
            ['kind' => 'source', 'source' => '_code', 'target' => 'tax_number', 'options' => ['transforms' => []]],
        ],
    ], livewire: $livewire);
    $state = $form->getState();
    expect(array_column($state['mapping'], 'source'))->toContain('_fld17');
    $replica = $form->getRecord();
    $replica->update($state);
    $stored = $replica->fresh()->toArray()['mapping'];

    $edit = ReplicaForm::configure(
        FilamentSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'),
    );
    $edit->fill($replica->fresh()->toArray());
    expect($edit->getState()['mapping'])
        ->toEqual($stored)
        ->and($replica->columnMap()['_fld17'])
        ->toBe('name');
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('shows each composite column as a separate source card and saves only its selected binding', function (string $destination) {
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'owners',
        label: 'Owners',
        table: 'owners',
        primaryKey: ['id'],
        schema: SchemaBuilder::make(Schema::object([
            'id' => Schema::string(),
            'owner' => SchemaBuilder::make()
                ->type('string')
                ->format('uuid')
                ->with('x-ferry-reference', true)
                ->title('Owner')
                ->build(),
        ]))
            ->required(['id', 'owner'])
            ->build(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'sources' => [
            '_fld17_type' => [
                'type' => 'string',
                'minLength' => 2,
                'maxLength' => 2,
                'title' => 'Owner · ' . __('phpinnacle-ferry::resources.replica.components.type'),
                'description' => 'Customer or supplier who owns the record.',
            ],
            '_fld17_rtref' => [
                'type' => 'string',
                'minLength' => 8,
                'maxLength' => 8,
                'title' => 'Owner · ' . __('phpinnacle-ferry::resources.replica.components.rtref'),
                'description' => 'Customer or supplier who owns the record.',
            ],
            '_fld17_rrref' => [
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
                'title' => 'Owner · ' . __('phpinnacle-ferry::resources.replica.components.rrref'),
                'description' => 'Customer or supplier who owns the record.',
            ],
        ],
    ]);

    $form = TestCase::replicaForm([
        'name' => 'Owners',
        'code' => 'owners',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => $destination === 'dynamic' ? 'dynamic' : 'static',
        'destination' => $destination === 'dynamic' ? null : $destination,
        'mapping' => $destination === DestinationType::Dynamic->value
            ? MappingState::binding(['_idrref' => 'external_id', '_fld17_rrref' => 'owner'])
            : MappingState::mapping([
                'id' => MappingState::source('_idrref'),
                'owner' => MappingState::source('_fld17_rrref'),
            ]),
    ]);
    $mapping = $form->getComponentByStatePath('mapping');
    $sources = array_column($mapping->getSources(), null, 'id');
    foreach (['_fld17_type', '_fld17_rtref', '_fld17_rrref'] as $source) {
        expect($sources[$source]['description'])->toBe('Customer or supplier who owns the record.');
    }

    expect(array_keys($sources))
        ->toContain('_fld17_type', '_fld17_rtref', '_fld17_rrref')
        ->not
        ->toContain('_fld17')
        ->and($sources['_fld17_type']['label'])
        ->toBe('Owner · ' . __('phpinnacle-ferry::resources.replica.components.type'));
    $data = $form->getState();
    $bound = array_values(array_filter(
        $data['mapping'],
        fn (array $row) => $row['target'] !== null && $row['source'] !== '_idrref',
    ));
    expect($bound)
        ->toHaveCount(1)
        ->and($bound[0]['source'])
        ->toBe('_fld17_rrref')
        ->and($bound[0]['target'])
        ->toBe('owner')
        ->and($form->getRecord()->schema->toArray()['properties']['_fld17_rrref'])
        ->toMatchArray([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]);
})->with([DestinationType::Dynamic->value, 'owners']);

it('uses destination JSON Schema titles, constraints and required mappings in the editor', function () {
    $schema = SchemaBuilder::make(Schema::object([
        'id' => SchemaBuilder::make()->type('string')->format('uuid')->title('Identifier')->build(),
        'name' => SchemaBuilder::make()
            ->type('string')
            ->title('Customer name')
            ->description('Official name')
            ->max(100)
            ->build(),
        'is_active' => SchemaBuilder::make()->type('boolean')->title('Active')->build(),
    ]))
        ->required(['id', 'name'])
        ->build();
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'schema_customers',
        label: 'Customers',
        table: 'customers',
        primaryKey: ['id'],
        schema: $schema,
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_description' => Schema::fromArray([
        'type' => 'string',
        'maxLength' => 100,
    ])]]);
    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'schema_customers',
        'mapping' => MappingState::mapping([
            'id' => MappingState::source('_idrref'),
            'name' => MappingState::source('_description'),
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('mapping');

    expect($mapping->getRequiredTargets())->toBe(['id', 'name']);
    expect(array_column($mapping->getTargets(), 'label', 'id'))->toBe([
        'id' => 'Identifier',
        'name' => 'Customer name',
        'is_active' => 'Active',
    ]);
    expect($mapping->getTargetSchema()->properties()['name']->effectiveMaxLength())->toBe(100);
    expect($mapping->getTargetSchema()->properties()['name']->annotation('description'))->toBe('Official name');
    expect(collect($form->getState()['mapping'])->firstWhere('source', '_idrref')['target'])->toBe('id');
});

it('omits empty optional forms and validates static values against destination bounds', function () {
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'bounded',
        label: 'Bounded',
        table: 'bounded',
        primaryKey: ['id'],
        schema: SchemaBuilder::make(Schema::object([
            'id' => Schema::string(),
            'name' => SchemaBuilder::make()->type('string')->max(3)->build(),
            'description' => SchemaBuilder::make()->type('string')->nullable()->build(),
            'active' => Schema::boolean(),
            'quantity' => Schema::integer(),
        ]))
            ->required(['id', 'name'])
            ->build(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'bounded',
        'name' => 'Bounded',
        'code' => 'bounded',
        'mapping' => MappingState::mapping(['id' => MappingState::source('_idrref')]),
    ]);
    $mapping = $form->getComponentByStatePath('mapping');
    $mapping->getStaticFormSchema('name')->getComponent('value')->state('abc');
    $mapping->getStaticFormSchema('quantity')->getComponent('value')->state(0);

    $data = $form->getState();

    expect(array_column(
        array_filter($data['mapping'], fn (array $row) => $row['kind'] === 'static'),
        'static',
        'target',
    ))
        ->toBe(['name' => 'abc', 'active' => false, 'quantity' => 0]);
    $mapping->getStaticFormSchema('name')->getComponent('value')->state('abcd');
    expect($form->getState(...))->toThrow(ValidationException::class);
});

it('maps UUID references into bounded strings while respecting target length and direction', function () {
    $destination = new StaticDestination(
        key: 'references',
        label: 'References',
        table: 'references',
        primaryKey: ['external_id'],
        schema: SchemaBuilder::make(Schema::object([
            'external_id' => SchemaBuilder::make()->type('string')->max(255)->build(),
            'exact_id' => SchemaBuilder::make()->type('string')->max(36)->nullable()->build(),
            'short_id' => SchemaBuilder::make()->type('string')->max(35)->nullable()->build(),
            'uuid_id' => SchemaBuilder::make()
                ->type('string')
                ->format('uuid')
                ->with('x-ferry-reference', true)
                ->nullable()
                ->build(),
        ]))
            ->required(['external_id'])
            ->build(),
    );
    app(DestinationFactory::class)->register($destination);
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, ['fields' => [
        '_partner' => Schema::fromArray([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]),
        '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
    ]]);

    $form = TestCase::replicaForm([
        'name' => 'References',
        'code' => 'references',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'references',
        'mapping' => MappingState::mapping([
            'external_id' => MappingState::source('_idrref'),
            'exact_id' => MappingState::source('_partner'),
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('mapping');

    expect($mapping->getConnectionModes())
        ->toHaveKey('_partner.external_id', MappingMode::Whole)
        ->toHaveKey('_idrref.external_id', MappingMode::Whole)
        ->toHaveKey(
            '_partner.exact_id',
            MappingMode::Whole,
        )
        ->not->toHaveKey('_partner.short_id')
        ->not->toHaveKey('_description.uuid_id');
    $replica = $form->getRecord();
    $replica->update($form->getState());
    expect($replica->mapping[0]->source)->toBe('_idrref');
    expect($replica->mapping[0]->target)->toBe('external_id');
});

it('copies the selected source schema at creation and uses the saved snapshot on edit', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['sources' => ['first' => ['type' => 'string']]]);
    $second = TestCase::makeItem($upstream, [
        'external_id' => 'second',
        'sources' => ['second_field' => ['type' => 'string', 'maxLength' => 40]],
    ]);
    $livewire = SchemaHost::make();
    $form = ReplicaForm::creation(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'Second source',
        'code' => 'second_source',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        '_destination' => 'dynamic',
    ]);
    $form->getComponent('source')->state('second')->callAfterStateUpdated();

    $replica = Replica::create($form->getState());
    expect($replica->schema->toArray())->toBe($second->schema->toArray());
    $snapshot = $replica->schema->toArray();
    $current = $snapshot;
    $current['properties']['second_field']['maxLength'] = 80;
    $second->update(['schema' => Schema::fromArray($current)]);
    foreach (['en', 'ru'] as $locale) {
        app()->setLocale($locale);
        $edit = ReplicaForm::configure(
            FilamentSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'),
        );
        $edit->fill($replica->toArray());
        $sources = array_column($edit->getComponentByStatePath('mapping')->getSources(), null, 'id');
        expect($livewire->data['schema'])
            ->toBe($snapshot)
            ->and(
                $edit
                    ->getComponentByStatePath('mapping')
                    ->getSourceSchema()
                    ->toArray()['properties']['second_field']['maxLength'],
            )
            ->toBe(40)
            ->and($sources['_idrref']['label'])
            ->toBe(__('phpinnacle-ferry::resources.replica.system_fields._idrref'));
    }
});
