<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase as FerryTestCase;
use PHPinnacle\Ferry\Transformations\Filter;
use PHPinnacle\Ferry\Transforms\FilterTransform;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(FerryTestCase::class);

it('registers a field filter whose source comes from its field', function () {
    expect(app(TransformFactory::class)->handler('filter'))
        ->toBeInstanceOf(FilterTransform::class)
        ->and(app(TransformRegistry::class)->get('filter'))
        ->toBeInstanceOf(\PHPinnacle\Ferry\Transformation::class);
});

it('builds a filter on the selected field and preserves tombstones', function (
    string $mode,
    string $type,
    string $value,
    string $condition,
    string $missing,
) {
    expect(new FilterTransform()->connector('field_0_0', '_fld1', [
        'mode' => $mode,
        'type' => $type,
        'value' => $value,
    ]))->toBe([
        'field_0_0' => [
            'type' => 'io.confluent.connect.transforms.Filter$Value',
            'filter.condition' => $condition,
            'filter.type' => $mode,
            'missing.or.null.behavior' => $missing,
            'predicate' => 'tombstone',
            'negate' => 'true',
        ],
    ]);
})->with([
    ['exclude', 'boolean',   'true',    '$[?(@._fld1 == true)]',        'include'],
    ['exclude', 'string',    'Raw',     "$[?(@._fld1 == 'Raw')]",       'include'],
    ['include', 'string',    "O'Brien", "$[?(@._fld1 == 'O\\'Brien')]", 'exclude'],
    ['include', 'string',    'a\\b',    "$[?(@._fld1 == 'a\\\\b')]",    'exclude'],
    ['exclude', 'reference', 'abc',     "$[?(@._fld1 == 'abc')]",       'include'],
]);

it('accepts string reference and boolean fields', function (ColumnType $type) {
    expect(Filter::make()->accepts(JsonSchema::fromArray($type->jsonSchema())))
        ->toBe(in_array($type, [ColumnType::String, ColumnType::Reference, ColumnType::Boolean], true));
})->with(ColumnType::cases());

it('uses the input of its chain for the value editor and persisted comparison type', function (
    string $type,
    string $value,
    string $editor,
) {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(
            Filter::make()->getSchema(null, JsonSchema::fromArray(['type' => $type])),
        );
    $schema->fill(['mode' => 'exclude', 'value' => $value, 'type' => 'forged']);

    expect($schema->getComponent('value'))
        ->toBeInstanceOf($editor)
        ->and($schema->getState())
        ->toBe(['type' => $type, 'mode' => 'exclude', 'value' => $value]);
})->with([
    ['boolean', 'false', Select::class],
    ['string',  'true',  TextInput::class],
]);

it('validates user filter settings in the native form', function (array $params) {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(
            Filter::make()->getSchema(null, JsonSchema::fromArray(['type' => 'boolean'])),
        );
    $schema->fill($params);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    [['mode' => 'drop', 'value' => 'true']],
    [['mode' => 'exclude', 'value' => 'maybe']],
    [['mode' => 'exclude']],
]);
