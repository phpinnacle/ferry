<?php

use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

it('stores and restores the exact native schema emitted by the form', function (
    string|int|float|bool $value,
    string $type,
    array $transforms = [],
) {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        '_marked' => JsonSchema::fromArray(['type' => 'boolean']),
        '_unused' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 20]),
    ]]);
    $livewire = SchemaHost::make();
    $form = ReplicaForm::configure(Schema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'dynamic',
        'name' => 'Native schema',
        'code' => 'native_schema',
        'mapping' => [
            ['kind' => 'source', 'source' => '_idrref', 'target' => 'external_id', 'options' => []],
            ['kind' => 'source', 'source' => '_description', 'target' => 'name', 'options' => ['transforms' => []]],
            [
                'kind' => 'source',
                'source' => '_marked',
                'target' => null,
                'options' => [
                    'transforms' => [
                        [
                            'type' => 'filter',
                            'data' => [
                                'mode' => 'exclude',
                                'condition' => '@ == true',
                                'missing_or_null_behavior' => 'include',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'kind' => 'static',
                'target' => 'constant',
                'static' => $value,
                'options' => $transforms === [] ? [] : ['transforms' => $transforms],
            ],
        ],
    ]);
    $state = $form->getState();
    $attributes = TestCase::mappedReplicaAttributes($state, app(DestinationFactory::class));
    expect($attributes['mapping'])->toBe($state['mapping']);
    expect($attributes['schema']->toArray())->toBe($state['schema']);

    $replica = Replica::create($attributes);
    $saved = $replica->fresh();
    $constant = collect($saved->mapping)->sole(fn ($mapping) => $mapping->target === 'constant');
    $raw = $replica->getConnection()->table('replicas')->where('id', $replica->id)->value('mapping');
    expect(json_decode($raw, true, flags: JSON_THROW_ON_ERROR))
        ->toBe($state['mapping'])
        ->and($saved->toArray()['mapping'])
        ->toEqual($state['mapping'])
        ->and(
            collect($saved->toArray()['mapping'])
                ->sole(fn (array $mapping) => $mapping['target'] === 'constant')['static'],
        )
        ->toBe($value)
        ->and($saved->outputSchema(collect($saved->mapping)->firstWhere('source', '_description'))?->toArray())
        ->toMatchArray(['type' => 'string', 'maxLength' => 100])
        ->and($constant->static)
        ->toBe($value)
        ->and($saved->outputSchema($constant)?->singleType())
        ->toBe($type)
        ->and($replica->getConnection()->getSchemaBuilder()->getColumnListing($replica->destination))
        ->toBe(['external_id', 'name', 'constant']);

    $edit = ReplicaForm::configure(Schema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $edit->fill(ReplicaForm::fill($saved->toArray()));
    expect($edit->getState()['mapping'])->toEqual($state['mapping']);
})->with([
    'false' => [false, 'boolean'],
    'zero' => [0, 'integer'],
    'whole decimal' => [1.0, 'number'],
    'string true' => ['true', 'string'],
    'transformed string' => ['10', 'integer', [['type' => 'cast', 'data' => ['type' => 'int64']]]],
]);
