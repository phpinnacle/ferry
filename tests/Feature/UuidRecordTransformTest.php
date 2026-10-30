<?php

use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema as FormSchema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPinnacle\Eidos\Enums\MappingMode;
use PHPinnacle\Eidos\Forms\JsonSchemaPicker;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\SinkConfigFactory;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Jason\SchemaBuilder;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/ReplicaForm.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
    config(['database.connections.sqlite.host' => 'db.internal', 'database.connections.sqlite.port' => 5432]);
    app(DestinationFactory::class)->register(new StaticDestination(
        'keyed',
        'Keyed',
        'keyed',
        ['id'],
        SchemaBuilder::make(Schema::object([
            'id' => SchemaBuilder::make()->type('string')->format('uuid')->build(),
            'tenant' => Schema::integer(),
            'name' => Schema::string(),
        ]))
            ->required(['id', 'name'])
            ->build(),
    ));
    $this->upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $this->object = TestCase::makeItem($this->upstream, [
        'primary_key' => ['tenant_id', 'row_id'],
        'sources' => [
            'tenant_id' => ['type' => 'integer'],
            'row_id' => ['type' => 'string', 'maxLength' => 20],
            'name' => ['type' => 'string', 'maxLength' => 100],
            'unused' => ['type' => 'string'],
        ],
    ]);
    $this->action = TestAction::make('add')->schemaComponent('transforms', 'form');
});

it('creates and persists one UUID key through the tabbed record builder for both destinations', function (
    DestinationType $type,
) {
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, [
        'state' => [
            'name' => 'UUID replica',
            'code' => 'uuid_replica',
            'upstream_id' => $this->upstream->id,
            'source' => 'object-0',
            'destination' => $type === DestinationType::Static ? 'static:keyed' : 'dynamic',
            'mapping' => [
                ['kind' => 'source', 'source' => 'name', 'target' => 'name', 'options' => []],
                ['kind' => 'source', 'source' => 'tenant_id', 'target' => null, 'options' => []],
            ],
        ],
    ]);
    $originalRows = $page->get('data.mapping');
    $page->callAction($this->action, arguments: ['block' => 'insert_uuid'])->assertHasNoErrors();
    $item = array_key_first($page->get('data.transforms'));
    $html = $page->effects['html'] ?? implode('', $page->effects['partials'] ?? []);
    expect($html)->toContain('wire:model.live.blur="data.transforms.' . $item . '.data.output"');
    expect($page->get('data.transforms.' . $item . '.data.output'))->toBe('id');
    $sourceAttribute = $type === DestinationType::Static ? 'data-mapping-source-settings' : 'data-binding-source';
    expect($html)->toContain($sourceAttribute . '="id"');
    $picker = $page
        ->instance()
        ->form
        ->getComponentByStatePath('transforms')
        ->getChildSchema($item)
        ->getComponentByStatePath('fields');
    expect($picker)->toBeInstanceOf(JsonSchemaPicker::class);
    expect($picker->isMultiple())->toBeTrue();
    expect($picker->getState())->toBe(["$['tenant_id']", "$['row_id']"]);
    $page
        ->set('data.transforms.' . $item . '.data.fields', ["$['row_id']", "$['tenant_id']"])
        ->set('data.transforms.' . $item . '.data.output', 'record_id')
        ->assertSee('record_id');
    $html = $page->effects['html'] ?? implode('', $page->effects['partials'] ?? []);
    expect($html)->toContain($sourceAttribute . '="record_id"');
    expect(array_values($page->get('data.transforms')))->toBe([[
        'type' => 'insert_uuid',
        'data' => ['fields' => ["$['row_id']", "$['tenant_id']"], 'output' => 'record_id'],
    ]]);

    $form = $page->instance()->form;
    $mapping = $form->getComponentByStatePath('mapping');
    expect(array_keys($mapping->getSourceSchema()['properties']))
        ->toBe(['record_id', '_idrref', 'tenant_id', 'row_id', 'name', 'unused']);
    expect($mapping->getSources()[0]['id'])->toBe('record_id');
    expect($mapping->getSources()[0]['label'])->toBe('Record Id');
    expect(collect($page->get('data.mapping'))->firstWhere('source', 'record_id'))->not->toBeNull();
    expect(array_slice($page->get('data.mapping'), 0, count($originalRows)))->toBe($originalRows);
    expect($form->getState(...))->toThrow(ValidationException::class);
    expect(array_column($mapping->getSources(), 'required', 'id'))
        ->toMatchArray(['record_id' => true, 'tenant_id' => true, 'row_id' => true]);
    if ($type === DestinationType::Static) {
        expect($mapping->getConnectionModes())
            ->toHaveKey('record_id.id', MappingMode::Whole)
            ->toHaveKey('tenant_id.tenant', MappingMode::Whole)
            ->not->toHaveKey('tenant_id.id')
            ->not->toHaveKey('row_id.id');
    }

    $index = $mapping->getSourceRowIndex('record_id');
    $page->set('data.mapping.' . $index . '.target', 'id');
    $mapping = $page->instance()->form->getComponentByStatePath('mapping');
    $rows = array_values(array_filter(
        $page->get('data.mapping'),
        fn (array $row) => $row['kind'] !== 'static' || $row['target'] !== 'tenant',
    ));
    $page->set('data.mapping', $rows);
    $mapping = $page->instance()->form->getComponentByStatePath('mapping');
    $page->set('data.mapping.' . $mapping->getSourceRowIndex('tenant_id') . '.target', 'tenant');
    $form = $page->instance()->form;
    $state = $form->getState();
    $index = $form->getComponentByStatePath('mapping')->getSourceRowIndex('record_id');
    $keyTransforms = $form->getComponentByStatePath('mapping.' . $index . '.options.transforms');
    expect($keyTransforms->getAvailableTransforms(app(\PHPinnacle\Ferry\Services\TransformRegistry::class)))->toBe([]);
    expect($keyTransforms->getAvailableBlocks())->toBe([]);
    $keyTransforms->state([['type' => 'cast', 'data' => ['type' => 'string']]]);
    expect($form->getState(...))->toThrow(ValidationException::class);
    $invalidRows = $state['mapping'];
    $invalidRows[array_search('record_id', array_column($state['mapping'], 'source'), true)]['options']['transforms'] =
        [['type' => 'cast', 'data' => ['type' => 'string']]];
    $record = new Replica(ReplicaForm::forCreate($state, app(DestinationFactory::class)));
    try {
        ReplicaForm::forUpdate([...$state, 'mapping' => $invalidRows], $record, app(DestinationFactory::class));
        test()->fail('Invalid key transforms must fail validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.mapping');
    }

    $keyTransforms->state([]);
    $recordTransforms = $form->getComponentByStatePath('transforms');
    $original = $recordTransforms->getRawState();
    $invalid = $original;
    $invalid[array_key_first($invalid)]['data']['fields'] = ["$['row_id']"];
    $recordTransforms->state($invalid);
    expect($form->getState(...))->toThrow(ValidationException::class);
    $recordTransforms->state($original);
    $generated = collect($state['mapping'])->firstWhere('source', 'record_id');
    expect($generated)->toBe([
        'kind' => 'source',
        'source' => 'record_id',
        'target' => 'id',
        'options' => ['transforms' => []],
    ]);
    $replica = Replica::create(TestCase::mappedReplicaAttributes($state, app(DestinationFactory::class)))->fresh();
    expect($replica->schema->toArray())->toBe($state['schema']);
    expect($replica->schema->properties())->not->toHaveKey('record_id');
    expect(collect($replica->mapping)->firstWhere('source', 'record_id')->target)->toBe('id');
    expect($replica->primaryKeyMap($this->object))->toBe(['record_id' => 'id']);
    expect($replica->transformedSchema()->properties()['record_id']->toArray())->toMatchArray([
        'type' => 'string',
        'format' => 'uuid',
        'minLength' => 36,
        'maxLength' => 36,
    ]);
    $edit = ReplicaForm::configure(
        FormSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $edit->fill(ReplicaForm::fill($replica->toArray()));
    expect($edit->getState()['mapping'])->toBe($state['mapping']);
    expect($edit->getState()['transforms'])->toBe($state['transforms']);
    $savedPicker = $edit
        ->getComponentByStatePath('transforms')
        ->getChildSchema(array_key_first($edit->getComponentByStatePath('transforms')->getRawState()))
        ->getComponentByStatePath('fields');
    expect($savedPicker->getState())->toBe(["$['tenant_id']", "$['row_id']"]);
    expect($edit->getComponentByStatePath('mapping')->getSources()[0]['id'])->toBe('record_id');

    $source = new SourceFactory;
    $capture = $source->source($this->upstream, config('phpinnacle-ferry.origins.kafka'));
    expect($capture['message.key.columns'])->toBe('public._reference0:tenant_id,row_id');
    expect($capture['column.include.list'])
        ->toBe(
            'public._reference0.tenant_id,public._reference0.row_id,public._reference0.name',
        );
    expect($capture['transforms'])->toBe('unwrap');
    $sink = new SinkConfigFactory(app(TransformFactory::class))->make(
        app(DestinationFactory::class)->resolve($replica),
        $replica,
        $this->object,
        'test-topic',
    );
    $generator = 'replica_0';
    expect($sink)
        ->toMatchArray([
            'transforms' => $generator . ',rename_keys,rename_values',
            'transforms.' . $generator . '.type' => 'org.phpinnacle.toblerone.InsertUuid$Key',
            'transforms.' . $generator . '.fields' => 'tenant_id,row_id',
            'transforms.' . $generator . '.output' => 'record_id',
            'transforms.rename_keys.include' => 'record_id',
            'transforms.rename_keys.renames' => 'record_id:id',
            'pk.mode' => 'record_key',
            'pk.fields' => 'id',
            'insert.mode' => 'upsert',
            'delete.enabled' => 'true',
        ])
        ->not->toHaveKey('transforms.' . $generator . '.predicate');
    if ($type === DestinationType::Dynamic) {
        $table = $replica->getConnection()->getSchemaBuilder();
        expect($table->getColumnListing($replica->destination))->toBe(['name', 'tenant', 'id']);
        expect(collect($table->getIndexes($replica->destination))->firstWhere('primary', true)['columns'])->toBe([
            'id',
        ]);
    }
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('preserves destination constants when adding a UUID key', function () {
    $replica = TestCase::makeReplica([
        'upstream_id' => $this->upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'keyed',
        'mapping' => [
            new FieldMapping('name', 'name'),
            new FieldMapping(null, 'tenant', [], 4, FieldKind::Static),
        ],
    ]);
    $livewire = SchemaHost::make();
    $form = ReplicaForm::configure(
        FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill(ReplicaForm::fill($replica->toArray()));
    $mapping = $form->getComponentByStatePath('mapping');
    $properties = $mapping->getSourceSchema()['properties'];
    expect(array_keys($properties))->toBe(array_keys($replica->schema->toArray()['properties']));
    $transforms = $form->getComponentByStatePath('transforms');
    $action = $transforms->getAction('add')(['block' => 'insert_uuid']);
    $action->callBefore();
    $action->call();
    $item = array_key_first($transforms->getRawState());
    $transforms->getChildSchema($item)->getComponentByStatePath('output')->state('record_id');
    $transforms->callAfterStateUpdated();
    expect(array_keys($livewire->data['schema']['properties']))->toBe(array_keys($properties));
    expect(array_keys($mapping->getSourceSchema()['properties']))->toBe(['record_id', ...array_keys($properties)]);
    $rows = $mapping->getState();
    $rows[$mapping->getSourceRowIndex('record_id')]['target'] = 'id';
    $mapping->state($rows);
    $replica->fill(ReplicaForm::forUpdate($form->getState(), $replica, app(DestinationFactory::class)))->save();
    expect(array_keys($replica->fresh()->transformedSchema()->toArray()['properties']))->toBe([
        'record_id',
        ...array_keys($properties),
    ]);
    expect(collect($replica->fresh()->mapping)->firstWhere('target', 'tenant')->static)->toBe(4);
});

it('validates UUID field names and all physical key parts in its native tab', function (array $data, array $errors) {
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, ['state' => [
        'upstream_id' => $this->upstream->id,
        'source' => 'object-0',
        'destination' => 'dynamic',
    ]]);
    $page
        ->set('data.transforms', ['uuid' => ['type' => 'insert_uuid', 'data' => $data]])
        ->call('validateForm')
        ->assertHasErrors($errors);
})->with([
    'missing name' => [
        ['fields' => ["$['tenant_id']", "$['row_id']"]],
        ['data.transforms.uuid.data.output' => 'required'],
    ],
    'invalid name' => [
        ['fields' => ["$['tenant_id']", "$['row_id']"], 'output' => 'Bad name'],
        ['data.transforms.uuid.data.output' => 'regex'],
    ],
    'existing name' => [
        ['fields' => ["$['tenant_id']", "$['row_id']"], 'output' => 'name'],
        ['data.transforms.uuid.data.output' => 'not_in'],
    ],
    'missing part' => [
        ['fields' => ["$['row_id']"], 'output' => 'record_id'],
        ['data.transforms.uuid.data.fields' => 'size'],
    ],
    'ordinary field' => [
        ['fields' => ["$['name']", "$['row_id']"], 'output' => 'record_id'],
        ['data.transforms.uuid.data.fields.0' => \PHPinnacle\Eidos\Validation\ValidPath::class],
    ],
]);

it('rejects duplicate UUID sources through the Eidos picker validation', function () {
    $form = ReplicaForm::configure(FormSchema::make(SchemaHost::make())->statePath('data'));
    $form->fill([
        'upstream_id' => $this->upstream->id,
        'source' => 'object-0',
        'destination' => 'dynamic',
        'transforms' => [[
            'type' => 'insert_uuid',
            'data' => ['fields' => ["$['row_id']", "$['row_id']"], 'output' => 'record_id'],
        ]],
    ]);

    try {
        $form->getState();
        test()->fail('Duplicate UUID sources must fail validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())
            ->toHaveKey(
                'data.transforms.'
                . array_key_first($form->getComponentByStatePath('transforms')->getRawState())
                . '.data.fields.0',
            );
    }
});
