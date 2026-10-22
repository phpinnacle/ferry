<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase as FerryTestCase;
use PHPinnacle\Ferry\Transformations\Filter;
use PHPinnacle\Ferry\Transforms\FilterTransform;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../TestCase.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(FerryTestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('is registered as a built-in record transform that reads its own source column', function () {
    $filter = $this->app->make(TransformFactory::class)->handler('filter');

    expect($filter)
        ->toBeInstanceOf(FilterTransform::class)
        ->and($this->app->make(TransformFactory::class)->get('filter')->scope())
        ->toBe(TransformScope::Record)
        ->and($filter->sourceColumns(['column' => '_folder']))
        ->toBe(['_folder'])
        ->and($filter->resultType(ColumnType::String, []))
        ->toBe(ColumnType::String);
});

it('builds a filter on the value that never touches tombstone records', function (
    string $mode,
    string $type,
    string $value,
    string $condition,
    string $missing,
) {
    $transforms = new FilterTransform()->connector('record_0', null, [
        'mode' => $mode,
        'column' => '_fld1',
        'type' => $type,
        'value' => $value,
    ]);

    expect($transforms)->toBe([
        'record_0' => [
            'type' => 'io.confluent.connect.transforms.Filter$Value',
            'filter.condition' => $condition,
            'filter.type' => $mode,
            'missing.or.null.behavior' => $missing,
            'predicate' => 'tombstone',
            'negate' => 'true',
        ],
    ]);
})->with([
    'exclude a flag' => ['exclude', 'boolean', 'true', '$[?(@._fld1 == true)]', 'include'],
    'exclude a string' => ['exclude', 'string', 'Raw', "$[?(@._fld1 == 'Raw')]", 'include'],
    'quote a string' => ['include', 'string', "O'Brien", "$[?(@._fld1 == 'O\\'Brien')]", 'exclude'],
    'compare a reference as a string' => [
        'exclude',
        'reference',
        'f951e32b078f11ef856f7cc25565172c',
        "$[?(@._fld1 == 'f951e32b078f11ef856f7cc25565172c')]",
        'include',
    ],
]);

it('asks for Yes or No on a flag and for free input on other fields', function (string $column, string $input) {
    $connection = FerryTestCase::makeConnection([], [
        'status' => StructureStatus::Ready,
        'generation' => 1,
    ]);
    $object = ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_folder' => new ScalarField(FieldType::Boolean),
            '_description' => new StringField(length: 100, fixed: false),
        ],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);
    $livewire = SchemaHost::make();
    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components(Filter::make()->getSchema($object))
        ->fill(['column' => $column]);

    $values = array_filter(
        $schema->getFlatComponents(),
        static fn ($component) => $component->getName() === 'value' && $component->isVisible(),
    );

    expect($values)->toHaveCount(1)->and(array_values($values)[0])->toBeInstanceOf($input);
})->with([
    'flag' => ['_folder', Select::class],
    'string' => ['_description', TextInput::class],
]);

it('validates the mode, the column, the type and the value of a filter', function (array $params, bool $valid) {
    $filter = new FilterTransform;

    expect(Validator::make($params, $filter->rules($params))->passes())->toBe($valid);
})->with([
    'valid flag' => [['mode' => 'exclude', 'column' => '_folder', 'type' => 'boolean', 'value' => 'true'], true],
    'valid string' => [['mode' => 'include', 'column' => '_fld5812rref', 'type' => 'reference', 'value' => 'x'], true],
    'unknown mode' => [['mode' => 'drop', 'column' => '_folder', 'type' => 'boolean', 'value' => 'true'], false],
    'column with a path' => [['mode' => 'exclude', 'column' => '_a.b', 'type' => 'string', 'value' => 'x'], false],
    'column with a quote' => [['mode' => 'exclude', 'column' => "_a'b", 'type' => 'string', 'value' => 'x'], false],
    'decimal type' => [['mode' => 'exclude', 'column' => '_sum', 'type' => 'decimal', 'value' => '1.5'], false],
    'flag that is not a flag' => [
        ['mode' => 'exclude', 'column' => '_folder', 'type' => 'boolean', 'value' => 'maybe'],
        false,
    ],
    'number that is not a number' => [
        ['mode' => 'exclude', 'column' => '_code', 'type' => 'integer', 'value' => 'x'],
        false,
    ],
    'missing value' => [['mode' => 'exclude', 'column' => '_folder', 'type' => 'boolean'], false],
]);
