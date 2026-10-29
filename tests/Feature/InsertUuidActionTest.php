<?php

use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema as FormSchema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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
    $this->action = TestAction::make('insertUuid')->schemaComponent('mapping', 'form');
});

it('creates and persists one UUID key through the native header action for both destinations', function (
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
    $page->mountAction($this->action)->assertSchemaStateSet(['sources' => ['tenant_id', 'row_id']]);
    $page
        ->setActionData(['field' => 'record_id', 'sources' => ['row_id', 'tenant_id']])
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertSet('data.schema.x-primary-key', ['record_id'])
        ->call('$refresh')
        ->assertSee('record_id');

    $form = $page->instance()->form;
    $mapping = $form->getComponentByStatePath('mapping');
    expect(array_keys($page->get('data.schema.properties')))
        ->toBe(['record_id', '_idrref', 'tenant_id', 'row_id', 'name', 'unused']);
    expect($mapping->getSources()[0]['id'])->toBe('record_id');
    expect($page->get('data.mapping.0.source'))->toBe('record_id');
    expect(array_slice($page->get('data.mapping'), 1))->toBe($originalRows);
    expect($form->getState(...))->toThrow(ValidationException::class);
    expect(array_column($mapping->getSources(), 'required', 'id'))
        ->toMatchArray(['record_id' => true, 'tenant_id' => true, 'row_id' => true]);
    if ($type === DestinationType::Static) {
        expect($mapping->getConnectionModes())
            ->toHaveKey('record_id.id', 'whole')
            ->toHaveKey('tenant_id.tenant', 'whole')
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
    expect(array_keys($keyTransforms->getAvailableTransforms(app(\PHPinnacle\Ferry\Services\TransformRegistry::class))))
        ->toBe(['insert_uuid']);
    expect($keyTransforms->getAvailableBlocks())->toBe([]);
    expect($keyTransforms->isDeletable())->toBeFalse();
    expect($keyTransforms->isReorderable())->toBeFalse();
    expect($keyTransforms->isDisabled())->toBeTrue();
    $editor = $keyTransforms->getAction('fieldTransforms');
    expect($editor(['block' => 'insert_uuid', 'index' => 0, 'remove' => true])->isDisabled())->toBeTrue();
    $original = $keyTransforms->getSettings();
    foreach ([[], [...$original, ...$original], [['type' => 'cast', 'data' => ['type' => 'string']]]] as $invalid) {
        $keyTransforms->state($invalid);
        expect($form->getState(...))->toThrow(ValidationException::class);
        $invalidRows = $state['mapping'];
        $invalidRows[$index]['options']['transforms'] = $invalid;
        $record = new Replica(ReplicaForm::forCreate($state, app(DestinationFactory::class)));
        expect(fn () => ReplicaForm::forUpdate(
            [...$state, 'mapping' => $invalidRows],
            $record,
            app(DestinationFactory::class),
        ))
            ->toThrow(ValidationException::class);
    }
    $keyTransforms->state([['type' => 'insert_uuid', 'data' => ['fields' => 'row_id']]]);
    expect($form->getState(...))->toThrow(ValidationException::class);
    $keyTransforms->state($original);
    $generated = collect($state['mapping'])->firstWhere('source', 'record_id');
    expect($generated)->toBe([
        'kind' => 'source',
        'source' => 'record_id',
        'target' => 'id',
        'options' => ['transforms' => [['type' => 'insert_uuid', 'data' => ['fields' => 'tenant_id,row_id']]]],
    ]);
    $replica = Replica::create(TestCase::mappedReplicaAttributes($state, app(DestinationFactory::class)))->fresh();
    expect($replica->schema->toArray())->toBe($state['schema']);
    expect(array_key_first($replica->schema->properties()))->toBe('record_id');
    expect($replica->mapping[0]->source)->toBe('record_id');
    expect($replica->primaryKeyMap($this->object))->toBe(['record_id' => 'id']);
    expect($replica->schema->properties()['record_id']->toArray())->toMatchArray([
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
    $generator = 'field_' . collect($replica->mapping)->search(fn ($row) => $row->source === 'record_id') . '_0';
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
        expect($table->getColumnListing($replica->destination))->toBe(['id', 'name', 'tenant']);
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
    $mapping
        ->getAction('insertUuid')
        ->data(['field' => 'record_id', 'sources' => ['tenant_id', 'row_id']])
        ->call();
    expect(array_keys($livewire->data['schema']['properties']))->toBe(['record_id', ...array_keys($properties)]);
    $rows = $mapping->getState();
    $rows[$mapping->getSourceRowIndex('record_id')]['target'] = 'id';
    $mapping->state($rows);
    $replica->fill(ReplicaForm::forUpdate($form->getState(), $replica, app(DestinationFactory::class)))->save();
    expect(array_keys($replica->fresh()->schema->toArray()['properties']))->toBe([
        'record_id',
        ...array_keys($properties),
    ]);
    expect(collect($replica->fresh()->mapping)->firstWhere('target', 'tenant')->static)->toBe(4);
});

it('validates generated field names and requires every physical key part in its native modal', function (
    array $data,
    array $errors,
) {
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, ['state' => [
        'upstream_id' => $this->upstream->id,
        'source' => 'object-0',
        'destination' => 'dynamic',
    ]]);
    $page->callAction($this->action, data: $data)->assertHasActionErrors($errors);
    expect($page->get('data.schema.properties'))->not->toHaveKey('record_id');
})->with([
    'missing name' => [['sources' => ['tenant_id', 'row_id']], ['field' => 'required']],
    'invalid name' => [['field' => 'Bad name', 'sources' => ['tenant_id', 'row_id']], ['field' => 'regex']],
    'existing field' => [['field' => 'name', 'sources' => ['tenant_id', 'row_id']], ['field']],
    'missing part' => [['field' => 'record_id', 'sources' => ['row_id']], ['sources' => 'min']],
    'ordinary field' => [['field' => 'record_id', 'sources' => ['name', 'row_id']], ['sources' => 'in']],
    'duplicate part' => [['field' => 'record_id', 'sources' => ['row_id', 'row_id']], ['sources.0' => 'distinct']],
]);
