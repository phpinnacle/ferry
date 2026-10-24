<?php

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Forms\FieldMapping;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/MappingState.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

/** @param array<string, mixed> $property */
function builtin_static_schema(array $property, mixed $value = null): Schema
{
    $field = FieldMapping::make('mapping')
        ->jsonSchema(source: ['properties' => ['source' => ['type' => 'string']]], dest: [
            'properties' => ['target' => $property],
            'required' => ['target'],
        ])
        ->withBuiltinStaticForms();
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill(['mapping' => MappingState::mapping(['target' => MappingState::value($value)])]);

    return $schema;
}

it('registers native editors for basic types and string formats', function (array $property, string $class) {
    $schema = builtin_static_schema($property);
    $field = $schema->getComponent('mapping')->getStaticFormSchema('target')->getComponent('value');

    expect($field)->toBeInstanceOf($class);
})->with([
    'string' => [['type' => 'string'], TextInput::class],
    'integer' => [['type' => 'integer'], TextInput::class],
    'number' => [['type' => 'number'], TextInput::class],
    'boolean' => [['type' => 'boolean'], Toggle::class],
    'nullable boolean' => [['type' => ['boolean', 'null']], Select::class],
    'typed array' => [['type' => 'array', 'items' => ['type' => 'integer']], Repeater::class],
    'null' => [['type' => 'null'], TextInput::class],
    'enum' => [['type' => 'string', 'enum' => ['draft', 'active']], Select::class],
    'uuid' => [['type' => 'string', 'format' => 'uuid'], TextInput::class],
    'reference' => [['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true], TextInput::class],
    'phone' => [['type' => 'string', 'format' => 'phone'], TextInput::class],
    'email' => [['type' => 'string', 'format' => 'email'], TextInput::class],
    'date' => [['type' => 'string', 'format' => 'date'], DatePicker::class],
    'time' => [['type' => 'string', 'format' => 'time'], TimePicker::class],
    'date-time' => [['type' => 'string', 'format' => 'date-time'], DateTimePicker::class],
]);

it('round trips built-in static values with their native types', function (array $property, mixed $value) {
    $state = builtin_static_schema($property, $value)->getState()['mapping'];

    expect($state)->toBe(MappingState::mapping(['target' => MappingState::value($value)], [], ['source']));
})->with([
    'string' => [['type' => 'string'], '007'],
    'integer' => [['type' => 'integer'], 0],
    'number' => [['type' => 'number'], 1.25],
    'boolean' => [['type' => 'boolean'], false],
    'nullable boolean' => [['type' => ['boolean', 'null']], null],
    'nullable string' => [['type' => ['string', 'null']], null],
    'null' => [['type' => 'null'], null],
    'integer array' => [['type' => 'array', 'items' => ['type' => 'integer']], [0, 2]],
    'number array' => [['type' => 'array', 'items' => ['type' => 'number']], [0.0, 1.25]],
    'boolean array' => [['type' => 'array', 'items' => ['type' => 'boolean']], [false, true]],
    'nullable boolean items' => [
        ['type' => 'array', 'items' => ['type' => ['boolean', 'null']]],
        [false, null, true],
    ],
    'enum items' => [['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['one', 'two']]], ['two']],
    'date items' => [
        ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
        ['2026-10-23'],
    ],
    'time items' => [
        ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'time']],
        ['14:30:00'],
    ],
    'nullable items' => [['type' => 'array', 'items' => ['type' => ['string', 'null']]], ['one', null]],
    'empty typed array' => [['type' => 'array', 'items' => ['type' => 'string']], []],
    'enum' => [['type' => 'string', 'enum' => ['draft', 'active']], 'active'],
    'uuid' => [
        ['type' => 'string', 'format' => 'uuid'],
        '550e8400-e29b-41d4-a716-446655440000',
    ],
    'reference' => [
        ['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true],
        '550e8400-e29b-41d4-a716-446655440000',
    ],
    'email' => [['type' => 'string', 'format' => 'email'], 'user@example.com'],
    'phone' => [['type' => 'string', 'format' => 'phone'], '+48123456789'],
    'date' => [['type' => 'string', 'format' => 'date'], '2026-10-22'],
    'time' => [['type' => 'string', 'format' => 'time'], '14:30:00'],
    'date-time' => [
        ['type' => 'string', 'format' => 'date-time'],
        '2026-10-22T14:30:00+00:00',
    ],
]);

it('uses item formats and constraints inside repeaters', function () {
    $schema = builtin_static_schema([
        'type' => 'array',
        'minItems' => 1,
        'maxItems' => 10,
        'items' => ['type' => 'string', 'format' => 'email', 'maxLength' => 64],
    ], ['user@example.com']);
    $repeater = $schema->getComponent('mapping')->getStaticFormSchema('target')->getComponent('value');
    $item = $repeater->getSimpleField();

    expect($repeater)
        ->toBeInstanceOf(Repeater::class)
        ->getMinItems()
        ->toBe(1)
        ->getMaxItems()
        ->toBe(10)
        ->and($item)
        ->toBeInstanceOf(TextInput::class)
        ->getType()
        ->toBe('email')
        ->getMaxLength()
        ->toBe(64);
});

it('validates array counts and nested item values', function (array $property, array $value) {
    $schema = builtin_static_schema($property, $value);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'minimum items' => [['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']], []],
    'maximum items' => [['type' => 'array', 'maxItems' => 1, 'items' => ['type' => 'string']], ['one', 'two']],
    'unique strings' => [['type' => 'array', 'uniqueItems' => true, 'items' => ['type' => 'string']], ['one', 'one']],
    'item length' => [['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3]], ['long']],
    'item format' => [['type' => 'array', 'items' => ['type' => 'string', 'format' => 'email']], ['invalid']],
    'item integer' => [['type' => 'array', 'items' => ['type' => 'integer']], [1.5]],
]);

it('validates submitted values through built-in fields', function (array $property, mixed $value) {
    $schema = builtin_static_schema($property);
    $schema->getComponent('mapping')->getStaticFormSchema('target')->getComponent('value')->rawState($value);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'required' => [['type' => 'string'], ''],
    'length' => [['type' => 'string', 'maxLength' => 3], 'long'],
    'uuid' => [['type' => 'string', 'format' => 'uuid'], 'invalid'],
    'reference' => [['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true], 'invalid'],
    'email' => [['type' => 'string', 'format' => 'email'], 'invalid'],
    'phone' => [['type' => 'string', 'format' => 'phone'], 'letters'],
    'integer' => [['type' => 'integer'], '1.5'],
    'minimum' => [['type' => 'number', 'minimum' => 1], '0'],
    'maximum' => [['type' => 'number', 'maximum' => 1], '2'],
    'enum' => [['type' => 'string', 'enum' => ['draft']], 'other'],
]);

it('leaves unsupported built-in static types available for connections', function (array $property) {
    $field = FieldMapping::make('mapping')
        ->jsonSchema(
            source: ['properties' => ['source' => $property]],
            dest: ['properties' => ['target' => $property], 'required' => ['target']],
        )
        ->withBuiltinStaticForms();
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill();

    expect($field->getStaticFormSchema('target'))->toBeNull();
    expect($field->getStaticDefaults())->toBe([]);
    expect($schema->getState(...))->toThrow(ValidationException::class);

    $schema->fill(['mapping' => MappingState::mapping(['target' => MappingState::value([])])]);
    expect($schema->getState(...))->toThrow(ValidationException::class);

    $mapping = MappingState::mapping(['target' => MappingState::source('source')]);
    $schema->fill(['mapping' => $mapping]);
    expect($schema->getState()['mapping'])->toBe($mapping);
})->with([
    'object' => [['type' => 'object']],
    'nullable object' => [['type' => ['object', 'null']]],
    'untyped array' => [['type' => 'array']],
    'nested array' => [['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]]],
    'object array' => [['type' => 'array', 'items' => ['type' => 'object']]],
    'mixed items' => [['type' => 'array', 'items' => ['type' => ['string', 'number']]]],
    'nullable array' => [['type' => ['array', 'null'], 'items' => ['type' => 'string']]],
]);

it('preserves custom factories when adding built-in static forms', function (string $type) {
    $schema = builtin_static_schema(['type' => $type], 'custom');
    $field = $schema->getComponent('mapping');
    $field->staticForms([$type => fn () => TextInput::make('value')->maxLength(2)])->withBuiltinStaticForms();

    expect($field->getStaticFormSchema('target')->getComponent('value')->getMaxLength())->toBe(2);
    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with(['string', 'array', 'object']);
