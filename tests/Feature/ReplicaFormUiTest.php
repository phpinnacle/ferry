<?php

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPinnacle\Eidos\Forms\FieldBinding;
use PHPinnacle\Eidos\Forms\FieldMapping;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping as SchemaMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Transformation;
use PHPinnacle\Ferry\Transformations\Trim;
use PHPinnacle\Jason\Schema;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/MappingState.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';
require_once __DIR__ . '/../Fixtures/ReplicaForm.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('prohibits all source primary-key transforms while allowing their removal in both mapping editors', function (
    DestinationType $type,
    string $transform,
    array $parameters,
) {
    $destination = new StaticDestination(
        'keyed',
        'Keyed',
        'keyed',
        ['id'],
        Schema::object([
            'id' => Schema::string(),
            'name' => Schema::string(),
        ])->required(['id']),
    );
    app(DestinationFactory::class)->register($destination);
    app(TransformRegistry::class)->register(Trim::make());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'primary_key' => ['record_id'],
        'sources' => ['record_id' => ['type' => 'string'], 'name' => ['type' => 'string']],
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => $type,
        'destination' => $type === DestinationType::Static ? 'keyed' : null,
        'schema' => [],
    ]);
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill([
        ...ReplicaForm::fill($replica->toArray()),
        'schema' => [
            ['kind' => 'source', 'source' => 'record_id', 'target' => 'id'],
            ['kind' => 'source', 'source' => 'name', 'target' => 'name'],
        ],
    ]);
    $mapping = $form->getComponentByStatePath('schema');
    $keys = $mapping->getSourceStateKeys();
    $field = $form->getComponentByStatePath('schema.sources.' . $keys['record_id'] . '.transforms');
    $ordinary = $form->getComponentByStatePath('schema.sources.' . $keys['name'] . '.transforms');
    expect($field->getAvailableBlocks())->toBe([]);
    expect($field->getAction('fieldTransforms')([])->toHtml())
        ->not
        ->toContain('inline-builder-add-button', 'inline-builder-placeholder');
    expect($field->isDeletable())->toBeTrue();
    expect(array_keys($ordinary->getAvailableTransforms(app(TransformRegistry::class))))
        ->toContain('cast', 'filter', 'trim');

    $settings = [['type' => $transform, 'data' => $parameters]];
    $field->state($settings);
    expect($form->getState(...))->toThrow(ValidationException::class);
    $invalid = new SchemaMapping('record_id', 'id', $object->field('record_id'), transforms: [
        new \PHPinnacle\Ferry\Data\TransformSetting($transform, $parameters),
    ]);
    expect(fn () => ReplicaForm::forUpdate(
        [
            'name' => 'Keyed',
            'schema' => [$invalid->jsonSerialize()],
        ],
        $replica,
        app(DestinationFactory::class),
    ))
        ->toThrow(ValidationException::class);

    expect($field->getSettings())->toBe($settings);
    $action = $field->getAction('fieldTransforms');
    expect($action([])->toHtml())
        ->not
        ->toContain('inline-builder-add-button', 'inline-builder-placeholder')
        ->toContain(__('phpinnacle-common::inline-builder.remove', [
            'block' => app(TransformRegistry::class)->getOrFail($transform)->getLabel(),
        ]));
    expect(fn () => $action(['block' => 'filter'])->call())->toThrow(ValidationException::class);
    $action(['block' => $transform, 'index' => 0, 'remove' => true])->call();
    expect($field->getSettings())->toBe([]);
    $data = ReplicaForm::forUpdate($form->getState(), $replica, app(DestinationFactory::class));
    $replica->schema = $data['schema'];
    expect($replica->hasValidSchema($object, $type === DestinationType::Static ? $destination : null))->toBeTrue();

    $replica->schema = [$invalid];
    expect($replica->brokenColumns($object, $type === DestinationType::Static ? $destination : null))->toBe(['id']);
    $form->fill(ReplicaForm::fill($replica->toArray()));
    $mapping = $form->getComponentByStatePath('schema');
    expect($mapping instanceof FieldBinding ? $mapping->getWarnings() : $mapping->getSourceWarnings())
        ->toHaveKey('record_id');
})->with([DestinationType::Dynamic, DestinationType::Static])->with([
    'filter' => [
        'filter',
        ['condition' => "@ == 'blocked'", 'mode' => 'exclude', 'missing_or_null_behavior' => 'include'],
    ],
    'trim' => ['trim', []],
    'cast' => ['cast', ['type' => 'integer']],
]);

it('only connects static destination primary keys to source primary-key fields', function () {
    $destination = new StaticDestination(
        key: 'keyed',
        label: 'Keyed',
        table: 'keyed',
        primaryKey: ['id', 'tenant'],
        schema: Schema::object([
            'id' => Schema::string(),
            'tenant' => Schema::string(),
            'name' => Schema::string(),
        ])->required(['id', 'tenant']),
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
        'schema' => [],
    ]);
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $data = ReplicaForm::fill($replica->toArray());
    $form->fill([
        ...$data,
        'schema' => MappingState::mapping([
            'id' => MappingState::source('record_id'),
            'tenant' => MappingState::source('tenant_id'),
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('schema');

    expect($mapping->getConnectionModes())
        ->toHaveKey('record_id.id', 'whole')
        ->toHaveKey('tenant_id.tenant', 'whole')
        ->toHaveKey('owner.name', 'whole')
        ->not->toHaveKey('record_id.name')
        ->not->toHaveKey('tenant_id.name')
        ->not->toHaveKey('owner.id')
        ->not->toHaveKey('owner.tenant');
    expect($mapping->getStaticFormSchema('id'))
        ->toBeNull()
        ->and($mapping->getStaticFormSchema('tenant'))
        ->toBeNull()
        ->and($mapping->getStaticFormSchema('name'))
        ->not->toBeNull();
    $replica->fill(ReplicaForm::forUpdate($form->getState(), $replica, app(DestinationFactory::class)));
    expect($replica->columnMap($object))->toBe(['record_id' => 'id', 'tenant_id' => 'tenant']);

    $form->fill([
        ...$data,
        'schema' => MappingState::mapping([
            'id' => MappingState::source('owner'),
            'tenant' => MappingState::source('tenant_id'),
        ]),
    ]);
    expect($form->getState(...))->toThrow(ValidationException::class);

    foreach ([
        new SchemaMapping('owner', 'id', $object->field('owner')),
        SchemaMapping::fromArray(['kind' => 'static', 'target' => 'id', 'static' => 'constant']),
    ] as $invalid) {
        $rows = [$invalid, new SchemaMapping('tenant_id', 'tenant', $object->field('tenant_id'))];
        expect(fn () => ReplicaForm::forUpdate(
            [
                'name' => 'Keyed',
                'schema' => array_map(fn (SchemaMapping $row) => $row->jsonSerialize(), $rows),
            ],
            $replica,
            app(DestinationFactory::class),
        ))
            ->toThrow(ValidationException::class);
    }
});

it('requires the whole source key in static mappings at the form and save boundaries', function (
    array $primaryKey,
    array $bindings,
) {
    app(DestinationFactory::class)->register(new StaticDestination(
        'keyed',
        'Keyed',
        'keyed',
        ['id'],
        Schema::object(['id' => Schema::string(), 'name' => Schema::string()])->required(['id']),
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
        'schema' => [],
    ]);
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill([...ReplicaForm::fill($replica->toArray()), 'schema' => MappingState::mapping($bindings)]);
    expect($form->getState(...))->toThrow(ValidationException::class);
    $mappings = [];
    foreach ($bindings as $target => $entry) {
        $mappings[] = new SchemaMapping($entry['source'], $target, $object->field($entry['source']));
    }
    expect(fn () => ReplicaForm::forUpdate(
        [
            'name' => 'Keyed',
            'schema' => array_map(fn (SchemaMapping $mapping) => $mapping->jsonSerialize(), $mappings),
        ],
        $replica,
        app(DestinationFactory::class),
    ))
        ->toThrow(ValidationException::class, $primaryKey === [] ? 'must declare a primary key' : 'tenant_id');
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
        Schema::object(['id' => Schema::string()])->required(['id']),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'primary_key' => ['record_id'],
        'schema' => JsonSchema::fromArray([
            'type' => 'object',
            'properties' => ['record_id' => ['type' => 'string'], 'name' => ['type' => 'string']],
            'required' => ['name'],
        ]),
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => $type,
        'destination' => $type === DestinationType::Static ? 'keyed' : null,
        'schema' => [],
    ]);
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill(ReplicaForm::fill($replica->toArray()));
    $mapping = $form->getComponentByStatePath('schema');
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
    $replica = TestCase::makeReplica(['upstream_id' => $upstream->id, 'schema' => []]);
    $database = $replica->getConnection();
    expect($database->getSchemaBuilder()->hasTable($replica->destination))->toBeFalse();
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $data = ReplicaForm::fill($replica->toArray());
    $form->fill([
        ...$data,
        'schema' => MappingState::binding([
            'tenant_id' => 'tenant',
            'display_name' => 'name',
            'id' => ['value' => 'constant'],
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('schema');
    expect(array_column(array_filter($mapping->getSources(), fn (array $source) => $source['required']), 'id'))
        ->toBe(['tenant_id', 'record_id']);
    view()->share('errors', new \Illuminate\Support\ViewErrorBag);
    expect($mapping->toHtml())->toContain('eidos-mapping-required');
    expect(fn () => ReplicaForm::forUpdate($form->getState(), $replica, app(DestinationFactory::class)))
        ->toThrow(ValidationException::class, 'record_id');
    expect($database->getSchemaBuilder()->hasTable($replica->destination))->toBeFalse();

    $form->fill([
        ...$data,
        'schema' => MappingState::binding([
            'record_id' => 'id',
            'tenant_id' => 'tenant',
            'display_name' => 'name',
        ]),
    ]);
    $replica->update(ReplicaForm::forUpdate($form->getState(), $replica, app(DestinationFactory::class)));
    $index = array_find(
        $database->getSchemaBuilder()->getIndexes($replica->destination),
        fn (array $index) => $index['primary'],
    );
    expect($index['columns'])->toBe(['tenant', 'id']);
    expect($database->getSchemaBuilder()->getColumnListing($replica->destination))->toBe(['tenant', 'id', 'name']);
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
    app(TransformRegistry::class)->register(Transformation::make('as_uuid')
        ->inputSchema(['type' => 'string'])
        ->outputSchema(['type' => 'string', 'format' => 'uuid']));
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'owners',
        label: 'Owners',
        table: 'owners',
        primaryKey: ['id'],
        schema: Schema::object([
            'id' => Schema::string(),
            'owner' => Schema::string()->format('uuid')->with('x-ferry-reference', true)->title('Owner'),
        ])->required(['id', 'owner']),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        '_owner' => JsonSchema::fromArray([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]),
        '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
    ]]);
    $form = ReplicaForm::configure(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'Owners',
        'code' => 'owners',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'static:owners',
        'schema' => MappingState::mapping([
            'id' => MappingState::source('_idrref'),
            'owner' => MappingState::source('_owner'),
        ], [
            '_description' => ['transforms' => [['type' => 'as_uuid', 'data' => []]]],
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('schema');
    expect($mapping->getSourceSchema()['properties']['_owner'])->toMatchArray([
        'type' => 'string',
        'format' => 'uuid',
        'x-ferry-reference' => true,
    ]);
    expect($mapping->getConnectionModes())
        ->toHaveKey('_owner.owner', 'whole')
        ->toHaveKey('_description.owner', 'whole');
    $state = $form->getState();
    $owner = collect($state['schema'])->firstWhere('source', '_owner');
    expect($owner['result'])->toMatchArray(['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true]);
    expect(SchemaMapping::fromArray($owner)->outputSchema()?->singleType())->toBe('string');

    $form->fill([
        'name' => 'Owners',
        'code' => 'owners',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'static:owners',
        'schema' => MappingState::mapping([
            'id' => MappingState::source('_idrref'),
            'owner' => MappingState::source('_description'),
        ], [
            '_description' => ['transforms' => [['type' => 'as_uuid', 'data' => []]]],
        ]),
    ]);
    $transformed = collect($form->getState()['schema'])->firstWhere('source', '_description');
    expect($transformed['target'])->toBe('owner');
    expect($transformed['result'])->toBe(['type' => 'string', 'format' => 'uuid']);
});

it('updates only static cards and the changed transform editor in a large dynamic form', function () {
    app(TransformRegistry::class)->register(Trim::make());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $properties = [];
    for ($index = 0; $index < 165; $index++) {
        $properties['_fld' . $index] = ['type' => 'string', 'maxLength' => 100, 'title' => 'Field ' . $index];
    }
    TestCase::makeItem($upstream, ['sources' => $properties]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, ['state' => [
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => DestinationType::Dynamic->value,
    ]]);
    $sourceKey = $page->instance()->form->getComponentByStatePath('schema')->getBindingKey('_fld164');
    $page->set('data.schema.bound.' . $sourceKey, true)
        ->set('data.schema.bindings.' . $sourceKey . '.target', 'product_code');

    $page->callAction(TestAction::make('addConstant')->schemaComponent('schema', 'form'));
    expect($page->effects)->not->toHaveKey('html');
    expect(implode('', $page->effects['partials']))
        ->toContain('data-binding-constant=')
        ->not->toContain('data-binding-source=');
    $field = $page->instance()->form->getComponentByStatePath('schema');
    $constant = array_key_first($field->getConstants());
    $page->set('data.schema.bindings.' . $constant . '.input.static', 'value');
    $draft = $page->get('data.schema');

    $path = 'schema.sources.' . $field->getSourceStateKeys()['_fld0'] . '.transforms';
    $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
    $page->callAction($action->arguments(['block' => 'trim']));
    $page->assertSet('data.' . $path, [['type' => 'trim', 'data' => []]]);
    expect($page->effects)->not->toHaveKey('html');
    expect(array_keys($page->effects['partials']))->toBe(['schema-component::form.' . $path]);

    $page->callAction($action->arguments(['block' => 'trim', 'index' => 0, 'remove' => true]));
    $page->assertSet('data.' . $path, []);
    expect(array_keys($page->effects['partials']))->toBe(['schema-component::form.' . $path]);
    expect($page->get('data.schema'))->toBe($draft);

    $page->callAction(TestAction::make('removeConstant')
        ->schemaComponent('schema', 'form')
        ->arguments([
            'constant' => $constant,
        ]));
    expect($page->effects)->not->toHaveKey('html');
    expect(implode('', $page->effects['partials']))->not->toContain('data-binding-constant=', 'data-binding-source=');
    $page->assertSet('data.schema.bindings.' . $sourceKey . '.target', 'product_code');
});

it('refreshes mapping compatibility when transform actions change the source type', function () {
    app(TransformRegistry::class)->register(Trim::make());
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 9, 'minLength' => 9]),
    ]]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, ['state' => [
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'static:customers',
    ]]);
    $field = $page->instance()->form->getComponentByStatePath('schema');
    expect($field->getConnectionModes()['_description'])
        ->toHaveKeys(['name', 'tax_number'])
        ->not->toHaveKey('is_active');
    $path = 'schema.sources.' . $field->getSourceStateKeys()['_description'] . '.transforms';
    $targets = [$field->getStateKeys()['name'] => MappingState::source('_description')];
    $page->set('data.schema.targets', $targets);
    $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
    $page->callAction($action->arguments(['block' => 'cast']), ['data' => ['type' => 'boolean']]);
    expect($page->instance()->form->getComponentByStatePath('schema')->getConnectionModes()['_description'])
        ->toBe(['is_active' => 'whole']);
    $card = 'schema-component::form.schema.card_' . $field->getSourceStateKeys()['_description'];
    expect($page->effects)->not->toHaveKey('html');
    expect($page->effects['partials'])->not->toHaveKey('schema-component::form.schema');
    expect($page->effects['partials'][$card])
        ->toContain('data-mapping-source-settings="_description"', '>boolean</span>')
        ->not->toContain('data-mapping-source-settings="_code"');
    expect($page->effects['partials']['schema-component::form.schema.configuration'])
        ->toContain('updateConfiguration(');
    $page->callAction($action->arguments(['block' => 'cast', 'index' => 0, 'remove' => true]));
    expect($page->instance()->form->getComponentByStatePath('schema')->getConnectionModes()['_description'])
        ->toHaveKeys(['name', 'tax_number'])
        ->not->toHaveKey('is_active');
    expect($page->effects['partials'])
        ->toHaveKeys([$card, 'schema-component::form.schema.configuration'])
        ->not->toHaveKey('schema-component::form.schema');
    $page->assertSet('data.schema.targets', $targets);

    expect($page->instance()->form->getComponentByStatePath('schema')->getStaticFormSchema('is_active'))
        ->not->toBeNull();
});

it('shows Rosetta comments and constraints in both mapping modes', function (string $destination, ?string $comment) {
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_idrref' => JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 8, 'minLength' => 8]),
            '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
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
    $form = ReplicaForm::configure(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => $destination,
        'schema' => [],
    ]);
    $mapping = $form->getComponentByStatePath('schema');
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
    $key = $mapping->getSourceStateKeys()['_code'];
    expect(
        $form
            ->getComponentByStatePath('schema.sources.' . $key . '.transforms')
            ->getInputSchema()
            ->toArray(),
    )
        ->toMatchArray(['type' => 'string', 'minLength' => 8, 'maxLength' => 8]);
})->with([DestinationType::Dynamic->value, 'static:customers'])
    ->with([null, "Imported from 1C.\nKeep <original> text."]);

/** @return Collection<string, Select> */
$selects = function (array $state) {
    $livewire = SchemaHost::make();
    $livewire->data = $state;

    return collect(
        ReplicaForm::configure(
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
    $form = ReplicaForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill();

    expect($livewire->data)->toMatchArray([
        'upstream_id' => $upstream->id,
        'source' => $first->external_id,
        'destination' => DestinationType::Dynamic->value,
    ]);
    foreach (['upstream_id', 'source', 'destination'] as $name) {
        expect($form->getComponent($name)->canSelectPlaceholder())->toBeFalse();
    }
});

it('leaves unavailable upstreams and objects unselected', function () {
    $livewire = SchemaHost::make();
    $form = ReplicaForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill();

    expect($livewire->data)->toMatchArray([
        'upstream_id' => null,
        'source' => null,
        'destination' => DestinationType::Dynamic->value,
    ]);
});

it('selects the first object and clears mappings when changing upstreams', function (bool $hasObjects) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $next = TestCase::makeUpstream(['code' => 'next'], ['status' => StructureStatus::Ready, 'generation' => 1]);
    if ($hasObjects) {
        TestCase::makeItem($next, ['external_id' => 'next-object']);
    }
    $livewire = SchemaHost::make();
    $form = ReplicaForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => DestinationType::Dynamic->value,
        'schema' => MappingState::binding(['_idrref' => 'external_id']),
    ]);

    $form->getComponent('upstream_id')->state($next->id)->callAfterStateUpdated();

    expect($livewire->data['source'])->toBe($hasObjects ? 'next-object' : null);
    $mapping = $form->getComponentByStatePath('schema');
    expect($mapping->isSourceUsed('_idrref'))->toBeFalse();
})->with([true, false]);

it('binds dynamic fields and connects static fields to the declared destination fields', function () {
    app(DestinationFactory::class)->register(customers_destination());

    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_idrref' => JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
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

    $form = function (string $destination) use ($upstream) {
        $livewire = SchemaHost::make();
        $livewire->data = [
            'upstream_id' => $upstream->id,
            'source' => 'object-0',
            'destination' => $destination !== '' ? 'static:' . $destination : DestinationType::Dynamic->value,
        ];

        return ReplicaForm::configure(
            FilamentSchema::make($livewire)
                ->statePath('data')
                ->operation('create'),
        );
    };

    /** @var FieldBinding $binding */
    $binding = $form('')->getComponentByStatePath('schema');
    /** @var FieldMapping $mapping */
    $mapping = $form('customers')->getComponentByStatePath('schema');

    expect($mapping->hasStaticValues())->toBeFalse();
    expect($mapping->hasStaticForms())->toBeTrue();

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
        ->toBe(['customer_id' => 'string', 'name' => 'string', 'tax_number' => 'string', 'is_active' => 'boolean'])
        ->and($form('')->getComponentByStatePath('schema'))
        ->toBeInstanceOf(FieldBinding::class)
        ->and($form('customers')->getComponentByStatePath('schema'))
        ->toBeInstanceOf(FieldMapping::class);

    expect($mapping->getConnectionModes()['_fld1'])->toBe(['name' => 'whole', 'tax_number' => 'whole']);

    $schema = $form('customers');
    $schema->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => [],
    ]);
    $mapping = $schema->getComponentByStatePath('schema');
    $key = $mapping->getSourceStateKeys()['_fld1'];
    $schema
        ->getComponentByStatePath('schema.sources.' . $key . '.transforms')
        ->state([['type' => 'cast', 'data' => ['type' => 'string']]]);
    expect($mapping->getConnectionModes()['_fld1'])
        ->toBe(['name' => 'whole', 'tax_number' => 'whole']);
});

it('validates the static mapping against the declared destination fields', function (array $mapping, bool $valid) {
    app(DestinationFactory::class)->register(customers_destination());

    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_idrref' => JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        ],
        'sources' => [],
    ]);

    $livewire = SchemaHost::make();
    $livewire->data = [
        'name' => 'Customers',
        'code' => 'customers',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => MappingState::mapping($mapping),
    ];

    $schema = ReplicaForm::configure(
        FilamentSchema::make($livewire)
            ->statePath('data')
            ->operation('create'),
    );

    $schema->fill($livewire->data);

    if ($valid) {
        expect(array_map(fn (array $row) => array_diff_key(
            $row,
            array_flip(['field', 'result']),
        ), $schema->getState()['schema']))
            ->toBe(MappingState::mapping($mapping + ['is_active' => MappingState::value(false)], [
                '_description' => ['transforms' => []],
                '_code' => ['transforms' => []],
                '_idrref' => ['transforms' => []],
            ]))
            ->and(
                TestCase::mappedReplicaAttributes(
                    $schema->getState(),
                    app(DestinationFactory::class),
                )['type'],
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
        'destination' => DestinationType::Dynamic->value,
        'schema' => MappingState::binding(['_idrref' => $column]),
    ]);

    if ($valid) {
        expect(array_map(fn (array $row) => array_diff_key(
            $row,
            array_flip(['field', 'result']),
        ), $schema->getState()['schema']))
            ->toBe(MappingState::binding(['_idrref' => $column], ['_idrref' => ['transforms' => []]]));

        return;
    }

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'valid column' => ['external_id', true],
    'uppercase column' => ['External', false],
    'former technical column' => ['idrref', true],
]);

it('creates every parameter of a chosen transform so that live inputs can bind to them', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_folder' => JsonSchema::fromArray(['type' => 'boolean'])]]);
    $livewire = SchemaHost::make();
    $schema = ReplicaForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $schema->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => DestinationType::Dynamic->value,
        'schema' => [],
    ]);

    $mapping = $schema->getComponentByStatePath('schema');
    $key = $mapping->getSourceStateKeys()['_folder'];
    $field = $schema->getComponentByStatePath('schema.sources.' . $key . '.transforms');
    $action = $field->getAction('fieldTransforms')(['block' => 'filter']);
    $components = $action->getSchema(FilamentSchema::make($livewire))->getFlatComponents();
    $value = collect($components)->first(
        fn ($component) => $component instanceof Textarea && $component->getName() === 'condition',
    );

    expect($value)
        ->toBeInstanceOf(Textarea::class)
        ->and($value->getPlaceholder())
        ->toBe('@ == true')
        ->and(collect($components)->contains(
            fn ($component) => method_exists($component, 'getName') && $component->getName() === 'column',
        ))
        ->toBeFalse();
});

it('orders source objects with references first, obsolete ones last and titles case-insensitively', function () use (
    $selects,
) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $objects = [
        [0, 'Контрагенты'],
        [0, 'авансы'],
        [1, 'Заказ'],
        [2, '(Не используется) Старое'],
        [0, 'Номенклатура 10'],
        [0, 'Номенклатура 2'],
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
        'авансы',
        'Контрагенты',
        'Номенклатура 2',
        'Номенклатура 10',
        'Заказ',
        '(Не используется) Старое',
    ]);
});

it('offers dynamic and every registered static destination in one select', function () use ($selects) {
    app(DestinationFactory::class)->register(customers_destination());
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'dynamic',
        label: 'Static destination named dynamic',
        table: 'other_customers',
        primaryKey: ['id'],
        schema: customers_destination()->getSchema(),
    ));

    $fields = $selects([]);

    expect($fields->keys()->all())->toBe(['upstream_id', 'source', 'destination']);
    expect($fields->get('destination')->getOptions())->toBe([
        'dynamic' => DestinationType::Dynamic->getLabel(),
        'static:customers' => 'Customers',
        'static:dynamic' => 'Static destination named dynamic',
    ]);
});

it('validates the combined destination selection', function (?string $destination) {
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $livewire = SchemaHost::make();
    $form = ReplicaForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'Customers',
        'code' => 'customers',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'schema' => [],
    ]);
    $form->getComponent('destination')->state($destination)->callAfterStateUpdated();

    try {
        $form->getState();
        test()->fail('A destination from the available options must be selected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.destination');
    }
})->with([null, 'static:missing', 'unknown']);

it('resets the shared schema when switching destinations', function (string $from, string $to) {
    app(DestinationFactory::class)->register(customers_destination());
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'other_customers',
        label: 'Other customers',
        table: 'other_customers',
        primaryKey: ['id'],
        schema: customers_destination()->getSchema(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_description' => JsonSchema::fromArray([
        'type' => 'string',
        'maxLength' => 100,
    ])]]);
    $livewire = SchemaHost::make();
    $form = ReplicaForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => $from,
        'schema' => $from === 'dynamic'
            ? MappingState::binding(['_description' => 'name'])
            : MappingState::mapping(['name' => MappingState::source('_description')]),
    ]);

    $form->getComponent('destination')->state($to)->callAfterStateUpdated();

    expect($livewire->data['destination'])->toBe($to);
    $mapping = $form->getComponentByStatePath('schema');
    expect($mapping)
        ->toBeInstanceOf($to === 'dynamic' ? FieldBinding::class : FieldMapping::class);
    expect($mapping->isSourceUsed('_description'))->toBeFalse();
    expect(array_map(
        fn (array $settings) => $settings['transforms'],
        $mapping->getSourceSettings(),
    ))->toBe(['_idrref' => [], '_description' => []]);
    view()->share('errors', new \Illuminate\Support\ViewErrorBag);
    expect($form->toHtml())->toContain('Field Mapping');
})->with([
    'dynamic to static' => ['dynamic', 'static:customers'],
    'static to dynamic' => ['static:customers', 'dynamic'],
    'static to another static' => ['static:customers', 'static:other_customers'],
]);

it('passes outdated mappings to the field warnings instead of a separate entry', function (DestinationType $type) {
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_idrref' => JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_description' => JsonSchema::fromArray(['type' => 'boolean']),
        ],
    ]);
    $replica = new Replica([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => $type,
        'destination' => $type === DestinationType::Static ? 'customers' : null,
        'schema' => [
            ...(
                $type === DestinationType::Dynamic
                    ? [TestCase::keyMapping()]
                    : [new SchemaMapping('_idrref', 'customer_id', TestCase::keyMapping()->field)]
            ),
            new SchemaMapping('_description', 'name', JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])),
            new SchemaMapping('_missing', 'gone', JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])),
        ],
    ]);
    $livewire = SchemaHost::make();
    $schema = ReplicaForm::configure(
        FilamentSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'),
    );
    $schema->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => $type === DestinationType::Static ? 'static:' . $replica->destination : 'dynamic',
        'schema' => $type === DestinationType::Dynamic
            ? MappingState::binding(['_description' => 'name', '_missing' => 'gone'])
            : MappingState::mapping([
                'name' => MappingState::source('_description'),
                'gone' => MappingState::source('_missing'),
            ]),
    ]);

    $field = $schema->getComponentByStatePath('schema');

    expect($schema->getComponent('broken_mappings', withHidden: true))->toBeNull();

    if ($field instanceof FieldBinding) {
        expect(array_keys($field->getWarnings()))->toBe(['_description', '_missing']);
    } else {
        expect($field)->toBeInstanceOf(FieldMapping::class);
        expect(array_keys($field->getSourceWarnings()))->toBe(['_description', '_missing']);
        expect(array_keys($field->getTargetWarnings()))->toBe(['name', 'gone', 'tax_number']);
    }
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('warns on a changed source used only by a filter', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        '_marked' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 10]),
    ]]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'schema' => [
            TestCase::keyMapping(),
            new SchemaMapping('_marked', null, JsonSchema::fromArray(['type' => 'boolean']), transforms: [
                new \PHPinnacle\Ferry\Data\TransformSetting('filter', [
                    'mode' => 'exclude',
                    'condition' => '@ == true',
                    'missing_or_null_behavior' => 'include',
                ]),
            ]),
        ],
    ]);
    $form = ReplicaForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill(ReplicaForm::fill($replica->toArray()));

    expect($form->getComponentByStatePath('schema')->getWarnings())->toHaveKey('_marked');
});

it('stores physical source names directly and detects renamed source columns', function (DestinationType $type) {
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $property = ['_fld17' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Email']];
    $object = TestCase::makeItem($upstream, [
        'fields' => ['_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11])],
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
    $form = ReplicaForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'source' => $object->external_id,
        'destination' => $type === DestinationType::Dynamic ? 'dynamic' : 'static:customers',
        'name' => 'Physical names',
        'code' => 'physical_names',
        'schema' => [
            [
                'kind' => 'source',
                'source' => '_idrref',
                'target' => $type === DestinationType::Dynamic ? 'external_id' : 'customer_id',
            ],
            ['kind' => 'source', 'source' => '_fld17', 'target' => 'name', 'transforms' => $transforms],
            ['kind' => 'source', 'source' => '_code', 'target' => 'tax_number', 'transforms' => []],
        ],
    ]);
    $state = $form->getState();
    $attributes = TestCase::mappedReplicaAttributes($state, app(DestinationFactory::class));
    expect(array_column($state['schema'], 'source'))->toContain('_fld17');
    expect(array_column($attributes['schema'], 'source'))->toContain('_fld17');
    $replica = Replica::create($attributes);
    $stored = $replica->fresh()->toArray()['schema'];

    $edit = ReplicaForm::configure(
        FilamentSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'),
    );
    $edit->fill(ReplicaForm::fill($replica->fresh()->toArray()));
    expect(ReplicaForm::forUpdate($edit->getState(), $replica, app(DestinationFactory::class))['schema'])
        ->toEqual($stored)
        ->and($replica->columnMap($object)['_fld17'])
        ->toBe('name');

    $schema = $object->schema->toArray();
    $schema['properties']['_fld18'] = $schema['properties']['_fld17'];
    unset($schema['properties']['_fld17']);
    $object->schema = JsonSchema::fromArray($schema);

    expect($replica->brokenColumns(
        $object,
        $type === DestinationType::Static
            ? app(DestinationFactory::class)->getOrFail('customers')
            : null,
    ))
        ->toContain('name')
        ->and($replica->columnMap($object))
        ->not->toHaveKey('_fld17');
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('shows each composite column as a separate source card and saves only its selected binding', function (string $destination) {
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'owners',
        label: 'Owners',
        table: 'owners',
        primaryKey: ['id'],
        schema: Schema::object([
            'id' => Schema::string(),
            'owner' => Schema::string()->format('uuid')->with('x-ferry-reference', true)->title('Owner'),
        ])->required(['id', 'owner']),
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
    $form = ReplicaForm::configure(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'Owners',
        'code' => 'owners',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => $destination,
        'schema' => $destination === DestinationType::Dynamic->value
            ? MappingState::binding(['_idrref' => 'external_id', '_fld17_rrref' => 'owner'])
            : MappingState::mapping([
                'id' => MappingState::source('_idrref'),
                'owner' => MappingState::source('_fld17_rrref'),
            ]),
    ]);
    $mapping = $form->getComponentByStatePath('schema');
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
    $data = TestCase::mappedReplicaAttributes($form->getState(), app(DestinationFactory::class));
    $bound = array_values(array_filter(
        $data['schema'],
        fn (array $row) => $row['target'] !== null && $row['source'] !== '_idrref',
    ));
    expect($bound)
        ->toHaveCount(1)
        ->and($bound[0]['source'])
        ->toBe('_fld17_rrref')
        ->and($bound[0]['target'])
        ->toBe('owner')
        ->and($bound[0]['field'])
        ->toBe([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]);
    $filled = ReplicaForm::fill([...$data, 'type' => $data['type']->value, 'destination' => 'owners']);
    $bound = array_values(array_filter(
        $filled['schema'],
        fn (array $row) => $row['target'] !== null && $row['source'] !== '_idrref',
    ));
    expect($bound[0]['source'])->toBe('_fld17_rrref');
})->with([DestinationType::Dynamic->value, 'static:owners']);

it('uses destination JSON Schema titles, constraints and required mappings in the editor', function () {
    $schema = Schema::object([
        'name' => Schema::string()->title('Customer name')->description('Official name')->max(100),
        'is_active' => Schema::boolean()->title('Active'),
    ])->required(['name']);
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'schema_customers',
        label: 'Customers',
        table: 'customers',
        primaryKey: ['id'],
        schema: $schema,
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_description' => JsonSchema::fromArray([
        'type' => 'string',
        'maxLength' => 100,
    ])]]);
    $form = ReplicaForm::configure(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'static:schema_customers',
        'schema' => MappingState::mapping(['name' => MappingState::source('_description')]),
    ]);

    $mapping = $form->getComponentByStatePath('schema');

    expect($mapping->getTargetSchema())->toBe($schema->toArray());
    expect($mapping->getRequiredTargets())->toBe(['name']);
    expect(array_column($mapping->getTargets(), 'label', 'id'))->toBe([
        'name' => 'Customer name',
        'is_active' => 'Active',
    ]);
    expect(
        app(DestinationFactory::class)
            ->staticFor(new Replica([
                'type' => DestinationType::Static,
                'destination' => 'schema_customers',
            ]))
            ->getSchema(),
    )
        ->toBe($schema);
});

it('omits empty optional forms and validates static values against destination bounds', function () {
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'bounded',
        label: 'Bounded',
        table: 'bounded',
        primaryKey: ['id'],
        schema: Schema::object([
            'id' => Schema::string(),
            'name' => Schema::string()->max(3),
            'description' => Schema::string()->nullable(),
            'active' => Schema::boolean(),
            'quantity' => Schema::integer(),
        ])->required(['id', 'name']),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $form = ReplicaForm::configure(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'static:bounded',
        'name' => 'Bounded',
        'code' => 'bounded',
        'schema' => MappingState::mapping(['id' => MappingState::source('_idrref')]),
    ]);
    $mapping = $form->getComponentByStatePath('schema');
    $mapping->getStaticFormSchema('name')->getComponent('value')->state('abc');
    $mapping->getStaticFormSchema('quantity')->getComponent('value')->state(0);

    $data = TestCase::mappedReplicaAttributes($form->getState(), app(DestinationFactory::class));

    expect(array_column(
        array_filter($data['schema'], fn (array $row) => $row['kind'] === 'static'),
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
        schema: Schema::object([
            'external_id' => Schema::string()->max(255),
            'exact_id' => Schema::string()->max(36)->nullable(),
            'short_id' => Schema::string()->max(35)->nullable(),
            'uuid_id' => Schema::string()->format('uuid')->with('x-ferry-reference', true)->nullable(),
        ])->required(['external_id']),
    );
    app(DestinationFactory::class)->register($destination);
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, ['fields' => [
        '_partner' => JsonSchema::fromArray([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]),
        '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
    ]]);
    $form = ReplicaForm::configure(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'References',
        'code' => 'references',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'static:references',
        'schema' => MappingState::mapping([
            'external_id' => MappingState::source('_idrref'),
            'exact_id' => MappingState::source('_partner'),
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('schema');

    expect($mapping->getConnectionModes())
        ->not->toHaveKey('_partner.external_id')->toHaveKey('_idrref.external_id', 'whole')->toHaveKey(
            '_partner.exact_id',
            'whole',
        )
        ->not->toHaveKey('_partner.short_id')
        ->not->toHaveKey('_description.uuid_id');
    $replica = Replica::create(TestCase::mappedReplicaAttributes($form->getState(), app(DestinationFactory::class)));
    expect($replica->brokenColumns($object, $destination))->toBe([]);
    expect($replica->schema[0]->source)->toBe('_idrref');
    expect($replica->schema[0]->target)->toBe('external_id');
});
