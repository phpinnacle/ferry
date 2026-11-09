<?php

use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Forms\RecordTransforms;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Services\RosettaAdapter;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Jason\Schema;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

it('persists a replica record filter without changing its schema or inferring captured columns', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, ['sources' => ['active' => ['type' => 'boolean']]]);
    $settings = [[
        'type' => 'record_filter',
        'data' => [
            'condition' => '$[?(@.active == true)]',
            'mode' => 'exclude',
            'missing_or_null_behavior' => 'fail',
        ],
    ]];
    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'transforms' => $settings,
        'mapping' => [['kind' => 'source', 'source' => '_idrref', 'target' => 'external_id', 'options' => []]],
    ]);
    $replica = $form->getRecord();

    $replica->update($form->getState());

    $replica->refresh();
    expect($replica->toArray()['transforms'])->toBe($settings);
    expect(TestCase::replicaForm([], $replica)->getState()['transforms'])->toBe($settings);
    expect(app(ReplicaSchemaResolver::class)->transformedSchema($replica)->toArray())->toBe($object->schema->toArray());
    expect(app(RosettaAdapter::class)->capture($upstream)->columns)->toBe(['public._reference0._idrref']);
    expect(app(TransformFactory::class)->pipeline($replica, $object))->toMatchArray([
        'transforms' => 'replica_0,rename_values',
        'transforms.replica_0.type' => 'io.confluent.connect.transforms.Filter$Value',
        'transforms.replica_0.filter.condition' => '$[?(@.active == true)]',
        'transforms.replica_0.filter.type' => 'exclude',
        'transforms.replica_0.missing.or.null.behavior' => 'fail',
        'transforms.replica_0.predicate' => 'tombstone',
        'transforms.replica_0.negate' => 'true',
        'predicates.tombstone.type' => 'org.apache.kafka.connect.transforms.predicates.RecordIsTombstone',
    ]);
});

it('adds a record filter with native defaults and dehydrates the full JSONPath unchanged', function () {
    $builder = RecordTransforms::make('transforms')->inputSchema(Schema::object());
    $form = FilamentSchema::make(SchemaHost::make())->statePath('data')->components([$builder]);
    $form->fill();
    $action = $builder->getAction('add')(['block' => 'record_filter']);
    $action->callBefore();
    $action->call();
    $item = array_key_first($builder->getRawState());
    $builder
        ->getChildSchema($item)
        ->getComponentByStatePath('condition')
        ->state('$.after.customer[?(@.active == true)]');

    expect($form->getState()['transforms'])->toBe([[
        'type' => 'record_filter',
        'data' => [
            'mode' => 'exclude',
            'missing_or_null_behavior' => 'include',
            'condition' => '$.after.customer[?(@.active == true)]',
        ],
    ]]);
});

it('rejects invalid record filter settings through the record editor', function (array $params, string $field) {
    $form = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components([RecordTransforms::make('transforms')->inputSchema(Schema::object())]);
    $form->fill([
        'transforms' => ['filter' => [
            'type' => 'record_filter',
            'data' => $params,
        ]],
    ]);
    $item = array_key_first($form->getComponentByStatePath('transforms')->getRawState());

    expect($form->getState(...))->toThrow(function (ValidationException $exception) use ($field, $item) {
        expect($exception->errors())->toHaveKey('data.transforms.' . $item . '.data.' . $field);
    });
})->with([
    'empty expression' => [
        ['condition' => '', 'mode' => 'exclude', 'missing_or_null_behavior' => 'include'],
        'condition',
    ],
    'non-string expression' => [
        ['condition' => ['$.active'], 'mode' => 'exclude', 'missing_or_null_behavior' => 'include'],
        'condition',
    ],
    'unknown mode' => [['condition' => '$.active', 'mode' => 'drop', 'missing_or_null_behavior' => 'include'], 'mode'],
    'unknown missing behavior' => [
        ['condition' => '$.active', 'mode' => 'exclude', 'missing_or_null_behavior' => 'ignore'],
        'missing_or_null_behavior',
    ],
]);
