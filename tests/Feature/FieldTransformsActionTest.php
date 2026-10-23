<?php

use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Forms\FieldTransforms;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Transformation;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

beforeEach(function () {
    config(['phpinnacle-ferry.transforms' => []]);
    $transforms = app(TransformRegistry::class);
    $transforms->register(Trim::make()->color('success'), MaskField::make()->color('warning'));
    $this->field = FieldTransforms::make('transforms')->label('Name')->inputSchema(['type' => 'string']);
    Schema::make(SchemaHost::make())->statePath('data')->components([$this->field])->fill();
    $this->action = $this->field->getAction('fieldTransforms');
});

it('validates repeated numeric casts and offers transforms for the final type', function () {
    $settings = [
        ['key' => 'cast', 'params' => ['type' => 'integer']],
        ['key' => 'cast', 'params' => ['type' => 'boolean']],
    ];
    $this->field->state($settings);

    expect($this->field->getContainer()->getState()['transforms'])
        ->toBe($settings)
        ->and(array_keys($this->field->getAvailableTransforms(app(TransformRegistry::class))))
        ->toBe(['cast', 'filter']);

    $settings[] = ['key' => 'cast', 'params' => ['type' => 'string']];
    $this->field->state($settings);

    expect($this->field->getContainer()->getState()['transforms'])
        ->toBe($settings)
        ->and(array_keys($this->field->getAvailableTransforms(app(TransformRegistry::class))))
        ->toBe(['cast', 'filter', 'trim', 'mask']);
});

it('uses description colors for configurable and settings-free badges', function () {
    $this->field->state([
        ['key' => 'trim', 'params' => []],
        ['key' => 'mask', 'params' => ['replacement' => 'hidden']],
    ]);

    $html = ($this->action)([])->toHtml();

    expect($html)->toContain(
        'fi-color-success',
        'Trim whitespace',
        'fi-color-warning',
        'Mask field',
        'Remove Trim whitespace',
        'Remove Mask field',
    );
});

it('adds repeated transforms and edits only the selected instance', function () {
    ($this->action)(['transform' => 'trim'])->data([])->call();
    ($this->action)(['transform' => 'mask'])->data(['params' => [
        'replacement' => '***',
    ]])->call();
    ($this->action)(['transform' => 'trim'])->data([])->call();
    ($this->action)(['transform' => 'mask'])->data(['params' => [
        'replacement' => 'second',
    ]])->call();
    ($this->action)(['transform' => 'mask', 'index' => 3])->data(['params' => [
        'replacement' => 'hidden',
    ]])->call();

    expect($this->field->getRawState())->toBe([
        ['key' => 'trim', 'params' => []],
        ['key' => 'mask', 'params' => ['replacement' => '***']],
        ['key' => 'trim', 'params' => []],
        ['key' => 'mask', 'params' => ['replacement' => 'hidden']],
    ]);
});

it('reorders repeated transforms while preserving their individual parameters', function () {
    $settings = [
        ['key' => 'mask', 'params' => ['replacement' => 'first']],
        ['key' => 'mask', 'params' => ['replacement' => 'second']],
        ['key' => 'trim', 'params' => []],
    ];
    $this->field->state($settings);

    /** @var Action $action */
    $action = ($this->action)(['order' => ['1', '2', '0']]);
    expect($action->shouldOpenModal())->toBeFalse();
    $action->call();

    expect($this->field->getRawState())->toBe([$settings[1], $settings[2], $settings[0]]);
});

it('skips the modal for transforms without settings and removes them without changing their neighbors', function () {
    $this->field->state([
        ['key' => 'mask', 'params' => ['replacement' => 'hidden']],
    ]);
    $add = ($this->action)(['transform' => 'trim']);
    expect($add->shouldOpenModal())->toBeFalse();
    $add->data([])->call();

    expect($this->field->getRawState())->toBe([
        ['key' => 'mask', 'params' => ['replacement' => 'hidden']],
        ['key' => 'trim', 'params' => []],
    ]);

    $remove = ($this->action)(['transform' => 'trim', 'index' => 1, 'remove' => true]);
    expect($remove->shouldOpenModal())->toBeFalse();
    $remove->call();

    expect($this->field->getRawState())->toBe([
        ['key' => 'mask', 'params' => ['replacement' => 'hidden']],
    ]);
});

it('removes only the selected configurable instance directly and keeps deletion out of its settings modal', function () {
    $this->field->state([
        ['key' => 'mask', 'params' => ['replacement' => 'first']],
        ['key' => 'mask', 'params' => ['replacement' => 'hidden']],
    ]);
    $edit = ($this->action)(['transform' => 'mask', 'index' => 1]);
    expect($edit->getExtraModalFooterActions())->toBe([]);

    $remove = ($this->action)(['transform' => 'mask', 'index' => 1, 'remove' => true]);
    expect($remove->shouldOpenModal())->toBeFalse();
    $remove->call();

    expect($this->field->getRawState())->toBe([
        ['key' => 'mask', 'params' => ['replacement' => 'first']],
    ]);
});

it('rejects reorder requests that would remove, duplicate or introduce a transform', function (array $order) {
    $settings = [
        ['key' => 'trim', 'params' => []],
        ['key' => 'mask', 'params' => ['replacement' => 'hidden']],
    ];
    $this->field->state($settings);

    expect(fn () => ($this->action)(['order' => $order])->call())
        ->toThrow(ValidationException::class);
    expect($this->field->getRawState())->toBe($settings);
})->with([
    'missing transform' => [['0']],
    'duplicate transform' => [['1', '1']],
    'unknown transform' => [['0', 'missing']],
    'not a list' => [['first' => '1', 'second' => '0']],
]);

it('rejects edits and removals outside the selected transform instances', function (array $arguments) {
    $settings = [['key' => 'mask', 'params' => ['replacement' => 'first']]];
    $this->field->state($settings);

    expect(fn () => ($this->action)([...$arguments])->data([])->call())
        ->toThrow(ValidationException::class);
    expect($this->field->getRawState())->toBe($settings);
})->with([
    'missing removal index' => [['transform' => 'mask', 'remove' => true]],
    'unknown instance' => [['transform' => 'mask', 'index' => 1]],
    'different transform' => [['transform' => 'trim', 'index' => 0]],
]);

it('validates transform state at the standalone field boundary', function (array $settings) {
    $this->field->state($settings);

    expect(fn () => $this->field->getContainer()->getState())->toThrow(ValidationException::class);
})->with([
    'missing key' => [[['params' => []]]],
    'non-string parameters' => [[['key' => 'mask', 'params' => ['replacement' => []]]]],
    'non-list settings' => [['mask' => ['key' => 'mask', 'params' => []]]],
    'unexpected setting data' => [[['key' => 'trim', 'extra' => true]]],
]);

it('does not mutate a disabled transform field', function () {
    $this->field->disabled();
    expect(($this->action)(['transform' => 'trim'])->isDisabled())->toBeTrue();
    expect($this->field->getRawState())->toBe([]);
});

it('offers only transforms that accept the output of the current chain', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(Substring::make());
    $this->field->inputSchema(['type' => 'boolean']);

    expect(array_keys($this->field->getAvailableTransforms($registry)))->toBe(['cast', 'filter']);
    expect(($this->action)([])->toHtml())->not->toContain('Trim whitespace', 'Extract substring', 'Mask field');
    expect(($this->action)(['transform' => 'trim'])->isDisabled())->toBeTrue();
    expect(fn () => ($this->action)(['transform' => 'trim'])->data([])->call())->toThrow(ValidationException::class);
    expect($this->field->getSettings())->toBe([]);

    ($this->action)(['transform' => 'cast'])->data(['params' => ['type' => 'string']])->call();
    expect(array_keys($this->field->getAvailableTransforms($registry)))->toBe([
        'cast',
        'filter',
        'trim',
        'mask',
        'substring',
    ]);
    ($this->action)(['transform' => 'trim'])->data([])->call();
    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['key' => 'cast', 'params' => ['type' => 'string']],
        ['key' => 'trim', 'params' => []],
    ]);

    ($this->action)(['transform' => 'cast', 'index' => 0])->data(['params' => ['type' => 'boolean']])->call();
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
    expect($this->field->getAvailableTransforms($registry))->toBe([]);
    expect(($this->action)([])->toHtml())->toContain('Remove Trim whitespace');
    ($this->action)(['transform' => 'trim', 'index' => 1, 'remove' => true])->call();
    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['key' => 'cast', 'params' => ['type' => 'boolean']],
    ]);
    expect(array_keys($this->field->getAvailableTransforms($registry)))->toBe(['cast', 'filter']);
});

it('validates each transform input after reordering or removing a preceding cast', function () {
    $this->field->inputSchema(['type' => 'boolean'])->state([
        ['key' => 'cast', 'params' => ['type' => 'string']],
        ['key' => 'trim', 'params' => []],
    ]);
    ($this->action)(['order' => [1, 0]])->call();
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
    ($this->action)(['order' => [1, 0]])->call();
    expect($this->field->getContainer()->getState()['transforms'])->toHaveCount(2);

    ($this->action)(['transform' => 'cast', 'index' => 0, 'remove' => true])->call();
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
    ($this->action)(['transform' => 'trim', 'index' => 0, 'remove' => true])->call();
    expect($this->field->getContainer()->getState()['transforms'])->toBe([]);
});

it('offers and validates string transforms for formatted strings', function (array $input) {
    $registry = app(TransformRegistry::class);
    $registry->register(Substring::make());
    $this->field->inputSchema($input);

    expect(array_keys($this->field->getAvailableTransforms($registry)))->toBe([
        'cast',
        'filter',
        'trim',
        'mask',
        'substring',
    ]);

    ($this->action)(['transform' => 'trim'])->data([])->call();
    ($this->action)(['transform' => 'mask'])->data(['params' => ['replacement' => 'hidden']])->call();

    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['key' => 'trim', 'params' => []],
        ['key' => 'mask', 'params' => ['replacement' => 'hidden']],
    ]);
})->with([
    'email' => [['type' => 'string', 'format' => 'email']],
    'nullable email' => [['type' => ['string', 'null'], 'format' => 'email']],
    'uuid' => [['type' => 'string', 'format' => 'uuid']],
    'date' => [['type' => 'string', 'format' => 'date']],
    'uri' => [['type' => 'string', 'format' => 'uri']],
]);

it('preserves dedicated logical types for their declared formats', function (string $format) {
    $this->field->inputSchema(['type' => ['string', 'null'], 'format' => $format]);

    expect(array_keys($this->field->getAvailableTransforms(app(TransformRegistry::class))))
        ->toBe($format === 'date-time' ? ['cast'] : ['cast', 'filter']);
    $this->field->state([['key' => 'trim', 'params' => []]]);
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
})->with(['date-time', 'ferry-reference']);

it('does not offer scalar transforms for array or object inputs', function (array $input) {
    $this->field->inputSchema($input);
    expect($this->field->getAvailableTransforms(app(TransformRegistry::class)))->toBe([]);
    expect(($this->action)([])->toHtml())->not->toContain('Add transform');
    $this->field->state([['key' => 'cast', 'params' => ['type' => 'string']]]);
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
})->with([
    'array' => [['type' => 'array', 'items' => ['type' => 'string']]],
    'object' => [['type' => 'object']],
]);

it('accepts nullable string inputs and rejects invalid parameters', function () {
    $this->field->inputSchema(['type' => ['string', 'null']])->state([['key' => 'trim', 'params' => []]]);
    expect($this->field->getContainer()->getState()['transforms'])->toHaveCount(1);
    $this->field->state([['key' => 'mask', 'params' => []]]);
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
    $this->field->state([['key' => 'filter', 'params' => []]]);
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
});

it('validates stored parameters through the description form without configured handlers', function () {
    app(TransformRegistry::class)->register(Substring::make());
    $this->field->state([['key' => 'substring', 'params' => ['start' => '5', 'end' => '2']]]);
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);

    $this->field->state([['key' => 'substring', 'params' => ['start' => '2', 'end' => '5']]]);
    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['key' => 'substring', 'params' => ['start' => '2', 'end' => '5']],
    ]);
});

it('casts a union of scalar types without losing nullability', function () {
    $this->field->inputSchema(['type' => ['string', 'number', 'null']]);
    expect(array_keys($this->field->getAvailableTransforms(app(TransformRegistry::class))))->toBe(['cast']);

    ($this->action)(['transform' => 'cast'])->data(['params' => ['type' => 'string']])->call();
    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['key' => 'cast', 'params' => ['type' => 'string']],
    ]);
});

it('lets a custom array transform participate in the same schema chain', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(Transformation::make('join')
        ->inputSchema(['type' => 'array', 'items' => ['type' => 'string']])
        ->outputSchema(['type' => 'string', 'maxLength' => 20]));
    $field = FieldTransforms::make('transforms')->inputSchema(['type' => 'array', 'items' => ['type' => 'string']]);
    Schema::make(SchemaHost::make())->statePath('data')->components([$field])->fill();
    $action = $field->getAction('fieldTransforms');
    expect(array_keys($field->getAvailableTransforms($registry)))->toBe(['join']);

    $action(['transform' => 'join'])->data([])->call();
    expect(array_keys($field->getAvailableTransforms($registry)))->toBe(['cast', 'filter', 'trim', 'mask']);
    $action(['transform' => 'trim'])->data([])->call();
    expect($field->getContainer()->getState()['transforms'])->toBe([
        ['key' => 'join', 'params' => []],
        ['key' => 'trim', 'params' => []],
    ]);
    expect($registry->resultSchema(JsonSchema::fromArray(['type' => 'array', 'items' => ['type' => 'string']]), [[
        'key' => 'join',
    ]])?->toArray())->toBe(['type' => 'string', 'maxLength' => 20]);
});

it('derives a filter comparison type from the current preceding cast', function () {
    $this->field->inputSchema(['type' => 'boolean'])->state([
        ['key' => 'cast', 'params' => ['type' => 'string']],
        ['key' => 'filter', 'params' => ['mode' => 'exclude', 'value' => 'true', 'type' => 'string']],
    ]);
    ($this->action)(['transform' => 'cast', 'index' => 0])->data(['params' => ['type' => 'boolean']])->call();

    expect($this->field->getContainer()->getState()['transforms'][1]['params'])
        ->toBe(['mode' => 'exclude', 'value' => 'true', 'type' => 'boolean']);
});
