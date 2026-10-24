<?php

use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

it('migrates persisted source, static and record transform settings and restores them on rollback', function () {
    Queue::fake();
    $sync = TestCase::makeSync();
    $schema = array_map(fn ($mapping) => $mapping->jsonSerialize(), $sync->schema);
    $schema[0]['transforms'] = [
        ['key' => 'trim', 'params' => []],
        ['key' => 'mask', 'params' => ['replacement' => 'first']],
        ['key' => 'mask', 'params' => ['replacement' => 'second']],
    ];
    $schema[] = [
        'kind' => 'static',
        'target' => 'constant',
        'static' => '10',
        'result' => ['type' => 'integer'],
        'transforms' => [['key' => 'cast', 'params' => ['type' => 'integer']]],
    ];
    $schema[] = ['kind' => 'static', 'target' => 'decimal_constant', 'static' => 1.0];
    $transforms = [['key' => 'filter', 'params' => ['condition' => '@ != null']]];
    $query = $sync->getConnection()->table('syncs')->where('id', $sync->id);
    $query->update([
        'schema' => json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        'transforms' => json_encode($transforms, JSON_THROW_ON_ERROR),
    ]);
    $migration = require __DIR__ . '/../../database/migrations/update_ferry_transform_settings.php';
    $migration->up();

    $saved = $sync->fresh();
    expect(array_map(fn ($setting) => $setting->jsonSerialize(), $saved->schema[0]->transforms))->toBe([
        ['type' => 'trim', 'data' => []],
        ['type' => 'mask', 'data' => ['replacement' => 'first']],
        ['type' => 'mask', 'data' => ['replacement' => 'second']],
    ]);
    expect($saved->schema[1]->transforms[0]->jsonSerialize())->toBe([
        'type' => 'cast',
        'data' => ['type' => 'integer'],
    ]);
    expect($saved->transforms[0]->jsonSerialize())->toBe(['type' => 'filter', 'data' => ['condition' => '@ != null']]);
    expect($saved->schema[2]->static)->toBe(1.0);
    expect($saved->schema[2]->type())->toBe(ColumnType::Decimal);
    $migration->down();
    expect(json_decode($query->value('schema'), true, flags: JSON_THROW_ON_ERROR))->toBe($schema);
    expect(json_decode($query->value('transforms'), true, flags: JSON_THROW_ON_ERROR))->toBe($transforms);
});

it('stores and restores the exact native schema emitted by the form', function (
    string|int|float|bool $value,
    string $type,
    array $transforms = [],
) {
    Queue::fake();
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, ['system' => [
        '_description' => new StringField(length: 100, fixed: false),
        '_marked' => new ScalarField(FieldType::Boolean),
        '_unused' => new StringField(length: 20, fixed: false),
    ]]);
    $livewire = SchemaHost::make();
    $form = SyncForm::configure(Schema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => 'dynamic',
        'name' => 'Native schema',
        'code' => 'native_schema',
        'schema' => [
            ['kind' => 'source', 'source' => '_description', 'target' => 'name', 'transforms' => []],
            [
                'kind' => 'source',
                'source' => '_marked',
                'target' => null,
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
            ['kind' => 'static', 'target' => 'constant', 'static' => $value]
                + ($transforms === [] ? [] : ['transforms' => $transforms]),
        ],
    ]);
    $state = $form->getState();
    $attributes = SyncForm::forCreate($state, app(DestinationFactory::class));
    expect($attributes['schema'])->toBe($state['schema']);

    $sync = Sync::create($attributes);
    $saved = $sync->fresh();
    $constant = collect($saved->schema)->sole(fn ($mapping) => $mapping->target === 'constant');
    $raw = $sync->getConnection()->table('syncs')->where('id', $sync->id)->value('schema');
    expect(json_decode($raw, true, flags: JSON_THROW_ON_ERROR))
        ->toBe($state['schema'])
        ->and($saved->toArray()['schema'])
        ->toEqual($state['schema'])
        ->and(collect($saved->schema)->firstWhere('source', '_description')->result?->toArray())
        ->toMatchArray(['type' => 'string', 'maxLength' => 100])
        ->and($constant->static)
        ->toBe($value)
        ->and($constant->type())
        ->toBe(ColumnType::from($type))
        ->and($sync->getConnection()->getSchemaBuilder()->getColumnListing($sync->destination))
        ->toBe(['idrref', 'name', 'constant']);

    $edit = SyncForm::configure(Schema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $edit->fill(SyncForm::fill($saved->toArray()));
    expect($edit->getState()['schema'])->toEqual($state['schema']);
})->with([
    'false' => [false, 'boolean'],
    'zero' => [0, 'integer'],
    'whole decimal' => [1.0, 'decimal'],
    'string true' => ['true', 'string'],
    'transformed string' => ['10', 'integer', [['type' => 'cast', 'data' => ['type' => 'integer']]]],
]);
