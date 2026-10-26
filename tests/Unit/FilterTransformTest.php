<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Transformations\Filter;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

it('registers the built-in field filter', function () {
    expect(app(TransformRegistry::class)->get('filter'))->toBeInstanceOf(Filter::class);
});

it('binds JSONPath predicates to the selected field without changing literals or nested item references', function (
    string $predicate,
    string $condition,
) {
    $config = new Filter()->connector('_fld1', [
        'mode' => 'include',
        'condition' => $predicate,
        'missing_or_null_behavior' => 'fail',
    ]);

    expect($config['filter.condition'])->toBe($condition);
})->with([
    'boolean' => ['@ == true', "$[?(@['_fld1'] == true)]"],
    'integer zero' => ['@ == 0', "$[?(@['_fld1'] == 0)]"],
    'negative integer' => ['@ < -42', "$[?(@['_fld1'] < -42)]"],
    'decimal precision' => ['@ >= 9007199254740993.2500', "$[?(@['_fld1'] >= 9007199254740993.2500)]"],
    'exponent' => ['@ > 1.25e-3', "$[?(@['_fld1'] > 1.25e-3)]"],
    'multiple comparisons' => ['@ >= 10 && @ < 100', "$[?(@['_fld1'] >= 10 && @['_fld1'] < 100)]"],
    'child path' => ['@.price > 100', "$[?(@['_fld1'].price > 100)]"],
    'root path' => ['$.price > 100', "$[?($['_fld1'].price > 100)]"],
    'quoted references' => ["@ == '@ and $'", "$[?(@['_fld1'] == '@ and $')]"],
    'escaped quote' => ["@ == 'O\\'Brien @ $'", "$[?(@['_fld1'] == 'O\\'Brien @ $')]"],
    'double quotes' => ['@ == "@ and $"', '$[?(@[\'_fld1\'] == "@ and $")]'],
    'regex' => ['@ =~ /^user@.*$/i', "$[?(@['_fld1'] =~ /^user@.*$/i)]"],
    'array literal' => ['@ in [1, 2, 3] && @ != 0', "$[?(@['_fld1'] in [1, 2, 3] && @['_fld1'] != 0)]"],
    'nested predicate' => [
        '@.items[?(@.price > $.minimum)] empty false',
        "$[?(@['_fld1'].items[?(@.price > $['_fld1'].minimum)] empty false)]",
    ],
    'nested bracket selector' => [
        "@.items[?(@['price'] > 0)] empty false && @.enabled == true",
        "$[?(@['_fld1'].items[?(@['price'] > 0)] empty false && @['_fld1'].enabled == true)]",
    ],
    'property with dollar' => ['@.$schema == "test"', "$[?(@['_fld1'].$" . 'schema == "test")]'],
]);

it('uses literal field names in JSONPath', function (string $field, string $path) {
    $config = new Filter()->connector($field, [
        'mode' => 'exclude',
        'condition' => '@ == true',
        'missing_or_null_behavior' => 'include',
    ]);

    expect($config['filter.condition'])->toBe('$[?(@' . $path . ' == true)]');
})->with([
    ['field.with.dots', "['field.with.dots']"],
    ["field'quote",     "['field\\'quote']"],
    ['field\\slash',    "['field\\\\slash']"],
]);

it('preserves filter modes, explicit missing behavior and tombstones', function (string $mode, string $missing) {
    expect(new Filter()->connector('_fld1', [
        'mode' => $mode,
        'condition' => '@ == false',
        'missing_or_null_behavior' => $missing,
    ]))->toBe([
        'type' => 'io.confluent.connect.transforms.Filter$Value',
        'filter.condition' => "$[?(@['_fld1'] == false)]",
        'filter.type' => $mode,
        'missing.or.null.behavior' => $missing,
        'predicate' => 'tombstone',
        'negate' => 'true',
    ]);
})->with(['include', 'exclude'])->with(['include', 'exclude', 'fail']);

it('accepts every input schema and preserves its type', function (array $definition) {
    $input = JsonSchema::fromArray($definition);
    $filter = Filter::make();

    expect($filter->accepts($input))
        ->toBeTrue()
        ->and($filter->output($input, ['condition' => '@ != null']))
        ->toBe($input);
})->with([
    [['type' => 'string']],
    [['type' => 'integer']],
    [['type' => 'number']],
    [['type' => 'boolean']],
    [['type' => 'string', 'format' => 'date-time']],
    [['type' => 'array', 'items' => ['type' => 'integer']]],
    [['type' => 'object']],
    [['type' => ['string', 'number', 'null']]],
    [[]],
]);

it('uses a JSONPath predicate editor and defaults the mode and missing behavior', function () {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(Filter::make()));
    $schema->fill();
    $schema->getComponent('condition')->rawState('@ >= 9007199254740993.2500');

    expect($schema->getComponent('condition'))
        ->toBeInstanceOf(Textarea::class)
        ->and($schema->getComponent('mode')->canSelectPlaceholder())
        ->toBeFalse()
        ->and($schema->getComponent('missing_or_null_behavior'))
        ->toBeInstanceOf(Select::class)
        ->and($schema->getComponent('missing_or_null_behavior')->canSelectPlaceholder())
        ->toBeFalse()
        ->and($schema->getState())
        ->toBe([
            'mode' => 'exclude',
            'missing_or_null_behavior' => 'include',
            'condition' => '@ >= 9007199254740993.2500',
        ]);
});

it('shows a predicate example for the input type in both the hint and placeholder', function (
    array $definition,
    string $example,
) {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(
            SchemaHost::transformSchema(Filter::make(), JsonSchema::fromArray($definition)),
        );
    $field = $schema->getComponent('condition');
    $schema->fill();
    view()->share('errors', new ViewErrorBag);

    expect($field->getPlaceholder())
        ->toBe($example)
        ->and(html_entity_decode(strip_tags($field->toHtml())))
        ->toContain($example)
        ->not->toContain('Kafka Connect');
})->with([
    'string' => [['type' => 'string'], "@ == 'value'"],
    'integer' => [['type' => 'integer'], '@ >= 100'],
    'number' => [['type' => 'number'], '@ >= 100.5'],
    'boolean' => [['type' => 'boolean'], '@ == true'],
    'array' => [['type' => 'array'], '@ empty false'],
    'object' => [['type' => 'object'], '@.price > 100'],
    'null' => [['type' => 'null'], '@ == null'],
    'nullable number' => [['type' => ['number', 'null']], '@ >= 100.5'],
    'unknown' => [[], '@ != null'],
    'union' => [['type' => ['string', 'number']], '@ != null'],
]);

it('validates user filter settings through the native form', function (array $params) {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(Filter::make()));
    $schema->fill($params);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    [['mode' => 'drop', 'condition' => '@ == true', 'missing_or_null_behavior' => 'fail']],
    [['mode' => 'exclude', 'condition' => '@ == true', 'missing_or_null_behavior' => 'ignore']],
    [['mode' => 'exclude', 'condition' => '@ == true']],
    [['mode' => 'exclude', 'condition' => '', 'missing_or_null_behavior' => 'fail']],
    [['mode' => 'exclude', 'condition' => '   ', 'missing_or_null_behavior' => 'fail']],
    [['mode' => 'exclude', 'condition' => [], 'missing_or_null_behavior' => 'fail']],
]);
