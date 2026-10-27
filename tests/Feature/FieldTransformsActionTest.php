<?php

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Common\Forms\InlineBuilder\Block;
use PHPinnacle\Common\Forms\InlineBuilder\BuilderAction;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Forms\FieldTransforms;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fakes\Transform as FakeTransform;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;
use Tests\TestCase;

require_once __DIR__ . '/../Fakes/Transform.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

beforeEach(function () {
    $transforms = app(TransformRegistry::class);
    $transforms->register(Trim::make(), MaskField::make());
    $this->field = FieldTransforms::make('transforms')->label('Name')->inputSchema(['type' => 'string']);
    Schema::make(SchemaHost::make())->statePath('data')->components([$this->field])->fill();
    $this->action = $this->field->getAction('fieldTransforms');
});

it('builds parameter forms when opening a transform rather than rendering its menu', function () {
    $builds = 0;
    app(TransformRegistry::class)->register(
        new FakeTransform('custom', block: Block::make('custom')->schema(function () use (&$builds) {
            $builds++;

            return [TextInput::make('value')->required()];
        })),
    );

    $action = BuilderAction::make($this->field)->schemaComponent($this->field);
    expect($action->toHtml())->toContain('custom');
    expect($builds)->toBe(0);
    expect($action(['block' => 'custom'])->shouldOpenModal())->toBeTrue();
    expect($builds)->toBe(1);
});

it('preserves the registered block availability condition', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(new FakeTransform('custom', block: Block::make('custom')->addable(false)));

    expect($this->field->getAvailableTransforms($registry))->toHaveKey('custom');
    expect($this->field->getAvailableBlocks())->not->toHaveKey('custom');
    expect($this->field->getBlocks())->toHaveKey('custom');
});

it('validates repeated numeric casts and offers transforms for the final type', function () {
    $settings = [
        ['type' => 'cast', 'data' => ['type' => 'int64']],
        ['type' => 'cast', 'data' => ['type' => 'boolean']],
    ];
    $this->field->state($settings);

    expect($this->field->getContainer()->getState()['transforms'])
        ->toBe($settings)
        ->and(array_keys($this->field->getAvailableTransforms(app(TransformRegistry::class))))
        ->toBe(['cast', 'filter']);

    $settings[] = ['type' => 'cast', 'data' => ['type' => 'string']];
    $this->field->state($settings);

    expect($this->field->getContainer()->getState()['transforms'])
        ->toBe($settings)
        ->and(array_keys($this->field->getAvailableTransforms(app(TransformRegistry::class))))
        ->toBe(['cast', 'filter', 'timestamp_converter', 'trim', 'mask']);
});

it('uses block colors for configurable and settings-free badges', function () {
    $this->field->state([
        ['type' => 'trim', 'data' => []],
        ['type' => 'mask', 'data' => ['replacement' => 'hidden']],
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
    expect($html)->toContain('inlineBuilder(')->not->toContain('@js(');
    expect(substr_count($html, 'data-field-interactive'))->toBe(2);
});

it('adds repeated transforms and edits only the selected instance', function () {
    ($this->action)(['block' => 'trim'])->data([])->call();
    ($this->action)(['block' => 'mask'])->data(['data' => [
        'replacement' => '***',
    ]])->call();
    ($this->action)(['block' => 'trim'])->data([])->call();
    ($this->action)(['block' => 'mask'])->data(['data' => [
        'replacement' => 'second',
    ]])->call();
    ($this->action)(['block' => 'mask', 'index' => 3])->data(['data' => [
        'replacement' => 'hidden',
    ]])->call();

    expect($this->field->getRawState())->toBe([
        ['type' => 'trim', 'data' => []],
        ['type' => 'mask', 'data' => ['replacement' => '***']],
        ['type' => 'trim', 'data' => []],
        ['type' => 'mask', 'data' => ['replacement' => 'hidden']],
    ]);
});

it('reorders repeated transforms while preserving their individual parameters', function () {
    $settings = [
        ['type' => 'mask', 'data' => ['replacement' => 'first']],
        ['type' => 'mask', 'data' => ['replacement' => 'second']],
        ['type' => 'trim', 'data' => []],
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
        ['type' => 'mask', 'data' => ['replacement' => 'hidden']],
    ]);
    $add = ($this->action)(['block' => 'trim']);
    expect($add->shouldOpenModal())->toBeFalse();
    $add->data([])->call();

    expect($this->field->getRawState())->toBe([
        ['type' => 'mask', 'data' => ['replacement' => 'hidden']],
        ['type' => 'trim', 'data' => []],
    ]);

    $remove = ($this->action)(['block' => 'trim', 'index' => 1, 'remove' => true]);
    expect($remove->shouldOpenModal())->toBeFalse();
    $remove->call();

    expect($this->field->getRawState())->toBe([
        ['type' => 'mask', 'data' => ['replacement' => 'hidden']],
    ]);
});

it('removes only the selected configurable instance directly and keeps deletion out of its settings modal', function () {
    $this->field->state([
        ['type' => 'mask', 'data' => ['replacement' => 'first']],
        ['type' => 'mask', 'data' => ['replacement' => 'hidden']],
    ]);
    $edit = ($this->action)(['block' => 'mask', 'index' => 1]);
    expect($edit->getExtraModalFooterActions())->toBe([]);

    $remove = ($this->action)(['block' => 'mask', 'index' => 1, 'remove' => true]);
    expect($remove->shouldOpenModal())->toBeFalse();
    $remove->call();

    expect($this->field->getRawState())->toBe([
        ['type' => 'mask', 'data' => ['replacement' => 'first']],
    ]);
});

it('rejects reorder requests that would remove, duplicate or introduce a transform', function (array $order) {
    $settings = [
        ['type' => 'trim', 'data' => []],
        ['type' => 'mask', 'data' => ['replacement' => 'hidden']],
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
    $settings = [['type' => 'mask', 'data' => ['replacement' => 'first']]];
    $this->field->state($settings);

    expect(fn () => ($this->action)([...$arguments])->data([])->call())
        ->toThrow(ValidationException::class);
    expect($this->field->getRawState())->toBe($settings);
})->with([
    'missing removal index' => [['block' => 'mask', 'remove' => true]],
    'unknown instance' => [['block' => 'mask', 'index' => 1]],
    'different transform' => [['block' => 'trim', 'index' => 0]],
]);

it('validates transform state at the standalone field boundary', function (array $settings) {
    $this->field->state($settings);

    expect(fn () => $this->field->getContainer()->getState())->toThrow(ValidationException::class);
})->with([
    'missing key' => [[['data' => []]]],
    'non-string parameters' => [[['type' => 'mask', 'data' => ['replacement' => []]]]],
    'non-list settings' => [['mask' => ['type' => 'mask', 'data' => []]]],
    'unexpected setting data' => [[['type' => 'trim', 'extra' => true]]],
]);

it('does not mutate a disabled transform field', function () {
    $this->field->disabled();
    expect(($this->action)(['block' => 'trim'])->isDisabled())->toBeTrue();
    expect($this->field->getRawState())->toBe([]);
});

it('offers only transforms that accept the output of the current chain', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(Substring::make());
    $this->field->inputSchema(['type' => 'boolean']);

    expect(array_keys($this->field->getAvailableTransforms($registry)))->toBe(['cast', 'filter']);
    expect(($this->action)([])->toHtml())->not->toContain('Trim whitespace', 'Extract substring', 'Mask field');
    expect(($this->action)(['block' => 'trim'])->isDisabled())->toBeTrue();
    expect(fn () => ($this->action)(['block' => 'trim'])->data([])->call())->toThrow(ValidationException::class);
    expect($this->field->getSettings())->toBe([]);

    ($this->action)(['block' => 'cast'])->data(['data' => ['type' => 'string']])->call();
    expect(array_keys($this->field->getAvailableTransforms($registry)))->toBe([
        'cast',
        'filter',
        'timestamp_converter',
        'trim',
        'mask',
        'substring',
    ]);
    ($this->action)(['block' => 'trim'])->data([])->call();
    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['type' => 'cast', 'data' => ['type' => 'string']],
        ['type' => 'trim', 'data' => []],
    ]);

    ($this->action)(['block' => 'cast', 'index' => 0])->data(['data' => ['type' => 'int64']])->call();
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
    expect($this->field->getAvailableTransforms($registry))->toBe([]);
    expect(($this->action)([])->toHtml())->toContain('Remove Trim whitespace');
    ($this->action)(['block' => 'trim', 'index' => 1, 'remove' => true])->call();
    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['type' => 'cast', 'data' => ['type' => 'int64']],
    ]);
    expect(array_keys($this->field->getAvailableTransforms($registry)))->toBe([
        'cast',
        'filter',
        'timestamp_converter',
    ]);
});

it('rejects incompatible reordering and validates removal of a preceding cast', function () {
    $this->field->inputSchema(['type' => 'boolean'])->state([
        ['type' => 'cast', 'data' => ['type' => 'string']],
        ['type' => 'trim', 'data' => []],
    ]);
    $original = $this->field->getSettings();
    expect(fn () => ($this->action)(['order' => [1, 0]])->call())->toThrow(ValidationException::class);
    expect($this->field->getSettings())->toBe($original);
    expect($this->field->getContainer()->getState()['transforms'])->toHaveCount(2);

    ($this->action)(['block' => 'cast', 'index' => 0, 'remove' => true])->call();
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
    ($this->action)(['block' => 'trim', 'index' => 0, 'remove' => true])->call();
    expect($this->field->getContainer()->getState()['transforms'])->toBe([]);
});

it('offers and validates string transforms for formatted strings', function (array $input) {
    $registry = app(TransformRegistry::class);
    $registry->register(Substring::make());
    $this->field->inputSchema($input);

    expect(array_keys($this->field->getAvailableTransforms($registry)))->toBe([
        'cast',
        'filter',
        'timestamp_converter',
        'trim',
        'mask',
        'substring',
    ]);

    ($this->action)(['block' => 'trim'])->data([])->call();
    ($this->action)(['block' => 'mask'])->data(['data' => ['replacement' => 'hidden']])->call();

    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['type' => 'trim', 'data' => []],
        ['type' => 'mask', 'data' => ['replacement' => 'hidden']],
    ]);
})->with([
    'email' => [['type' => 'string', 'format' => 'email']],
    'nullable email' => [['type' => ['string', 'null'], 'format' => 'email']],
    'uuid' => [['type' => 'string', 'format' => 'uuid']],
    'uri' => [['type' => 'string', 'format' => 'uri']],
]);

it('preserves dedicated logical types from their schemas', function (array $definition, array $available) {
    $this->field->inputSchema($definition);

    expect(array_keys($this->field->getAvailableTransforms(app(TransformRegistry::class))))
        ->toBe($available);
    $this->field->state([['type' => 'trim', 'data' => []]]);
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
})->with([
    'date-time' => [['type' => ['string', 'null'], 'format' => 'date-time'], ['cast', 'filter', 'timestamp_converter']],
    'date' => [['type' => 'string', 'format' => 'date'], ['cast', 'filter', 'timestamp_converter']],
    'time' => [['type' => 'string', 'format' => 'time'], ['cast', 'filter', 'timestamp_converter']],
    'reference' => [
        ['type' => ['string', 'null'], 'format' => 'uuid', 'x-ferry-reference' => true],
        ['cast', 'filter'],
    ],
]);

it('offers JSONPath filtering for array and object inputs', function (array $input) {
    $this->field->inputSchema($input);
    expect(array_keys($this->field->getAvailableTransforms(app(TransformRegistry::class))))->toBe(['filter']);
    expect(($this->action)([])->toHtml())->toContain('Filter records', 'fi-dropdown');
    $this->field->state([['type' => 'cast', 'data' => ['type' => 'string']]]);
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
})->with([
    'array' => [['type' => 'array', 'items' => ['type' => 'string']]],
    'object' => [['type' => 'object']],
]);

it('accepts nullable string inputs and rejects invalid parameters', function () {
    $this->field->inputSchema(['type' => ['string', 'null']])->state([['type' => 'trim', 'data' => []]]);
    expect($this->field->getContainer()->getState()['transforms'])->toHaveCount(1);
    $this->field->state([['type' => 'mask', 'data' => []]]);
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
    $this->field->state([['type' => 'filter', 'data' => []]]);
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
});

it('identifies the failing transform when its input or output schema is unavailable', function (bool $accepts) {
    app(TransformRegistry::class)->register(
        new FakeTransform(
            'unavailable',
            accepts: fn () => $accepts,
            output: fn () => null,
            block: Block::make('unavailable')->label('Unavailable transform'),
        ),
    );
    $this->field->state([
        ['type' => 'trim', 'data' => []],
        ['type' => 'unavailable', 'data' => []],
    ]);

    expect($this->field->getContainer()->getState(...))
        ->toThrow(
            ValidationException::class,
            __('phpinnacle-ferry::validation.transform_input_type', ['transform' => 'Unavailable transform']),
        );
})->with([true, false]);

it('validates hydrated numeric controls through their serialized string parameters', function () {
    app(TransformRegistry::class)->register(Substring::make());
    $settings = [['type' => 'substring', 'data' => ['start' => '1', 'end' => '4']]];
    $this->field->getContainer()->fill(['transforms' => $settings]);

    expect($this->field->getItems()[0]['data'])->toBe(['start' => 1, 'end' => 4]);
    expect($this->field->getContainer()->getState()['transforms'])->toBe($settings);
});

it('validates stored parameters through the description form without configured handlers', function () {
    app(TransformRegistry::class)->register(Substring::make());
    $this->field->state([['type' => 'substring', 'data' => ['start' => '5', 'end' => '2']]]);
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);

    $this->field->state([['type' => 'substring', 'data' => ['start' => '2', 'end' => '5']]]);
    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['type' => 'substring', 'data' => ['start' => '2', 'end' => '5']],
    ]);
});

it('casts a union of scalar types without losing nullability', function () {
    $this->field->inputSchema(['type' => ['string', 'number', 'null']]);
    expect(array_keys($this->field->getAvailableTransforms(app(TransformRegistry::class))))->toBe(['cast', 'filter']);

    ($this->action)(['block' => 'cast'])->data(['data' => ['type' => 'string']])->call();
    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['type' => 'cast', 'data' => ['type' => 'string']],
    ]);
});

it('lets a custom array transform participate in the same schema chain', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(
        new FakeTransform(
            'join',
            accepts: fn (JsonSchema $input) => $input->compatible(JsonSchema::fromArray([
                'type' => 'array',
                'items' => ['type' => 'string'],
            ])),
            output: fn () => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 20]),
        ),
    );
    $field = FieldTransforms::make('transforms')->inputSchema(['type' => 'array', 'items' => ['type' => 'string']]);
    Schema::make(SchemaHost::make())->statePath('data')->components([$field])->fill();
    $action = $field->getAction('fieldTransforms');
    expect(array_keys($field->getAvailableTransforms($registry)))->toBe(['filter', 'join']);

    $action(['block' => 'join'])->data([])->call();
    expect(array_keys($field->getAvailableTransforms($registry)))->toBe([
        'cast',
        'filter',
        'timestamp_converter',
        'trim',
        'mask',
    ]);
    $action(['block' => 'trim'])->data([])->call();
    expect($field->getContainer()->getState()['transforms'])->toBe([
        ['type' => 'join', 'data' => []],
        ['type' => 'trim', 'data' => []],
    ]);
    expect($registry->resultSchema(JsonSchema::fromArray(['type' => 'array', 'items' => ['type' => 'string']]), [[
        'type' => 'join',
    ]])?->toArray())->toBe(['type' => 'string', 'maxLength' => 20]);
});

it('preserves JSONPath predicates when a preceding cast changes type', function (string $type) {
    $params = ['mode' => 'exclude', 'condition' => '@ != null', 'missing_or_null_behavior' => 'fail'];
    $this->field->inputSchema(['type' => 'string'])->state([
        ['type' => 'cast', 'data' => ['type' => 'int64']],
        ['type' => 'filter', 'data' => $params],
    ]);
    ($this->action)(['block' => 'cast', 'index' => 0])->data(['data' => ['type' => $type]])->call();

    expect($this->field->getContainer()->getState()['transforms'][1]['data'])->toBe($params);
})->with(['boolean', 'int32', 'int64', 'float32', 'float64']);

it('uses the preceding cast output for the filter example', function () {
    $this->field->inputSchema(['type' => 'boolean']);
    $condition = fn () => collect(
        ($this->action)(['block' => 'filter'])
            ->getSchema(Schema::make($this->field->getLivewire()))->getFlatComponents(),
    )
        ->first(fn ($component) => $component instanceof Textarea && $component->getName() === 'condition');

    expect($condition()->getPlaceholder())->toBe('@ == true');
    $this->field->state([['type' => 'cast', 'data' => ['type' => 'float64']]]);
    expect($condition()->getPlaceholder())->toBe('@ >= 100.5');
});

it('rejects casting to the effective type after each preceding cast', function () {
    ($this->action)(['block' => 'cast'])->data(['data' => ['type' => 'boolean']])->call();
    ($this->action)(['block' => 'cast'])->data(['data' => ['type' => 'boolean']])->call();
    expect($this->field->getContainer()->getState(...))->toThrow(ValidationException::class);
    ($this->action)(['block' => 'cast', 'index' => 1, 'remove' => true])->call();
    ($this->action)(['block' => 'cast'])->data(['data' => [
        'type' => 'int32',
        'replace_null_with_default' => 'false',
    ]])->call();

    expect($this->field->getContainer()->getState()['transforms'])->toBe([
        ['type' => 'cast', 'data' => ['type' => 'boolean']],
        ['type' => 'cast', 'data' => ['type' => 'int32', 'replace_null_with_default' => 'false']],
    ]);
});

it('calculates allowed moves using the complete type chain and refreshes them after parameters change', function () {
    $registry = app(TransformRegistry::class);
    $this->field->inputSchema(['type' => 'boolean'])->state([
        ['type' => 'cast', 'data' => ['type' => 'string']],
        ['type' => 'filter', 'data' => ['condition' => '@ != null']],
        ['type' => 'trim', 'data' => []],
    ]);
    expect($this->field->getAllowedMoves())->toBe([[0, 1], [0, 1, 2], [1, 2]]);
    ($this->action)(['order' => [1, 0, 2]])->call();
    expect(array_column($this->field->getSettings(), 'type'))->toBe(['filter', 'cast', 'trim']);
    expect($this->field->getAllowedMoves())->toBe([[0, 1, 2], [0, 1], [2]]);

    $this->field->inputSchema(['type' => 'string'])->state([
        ['type' => 'cast', 'data' => ['type' => 'int64']],
        ['type' => 'trim', 'data' => []],
    ]);
    expect($this->field->getAllowedMoves())->toBe([[1], [0]]);
    $this->field->state([
        ['type' => 'cast', 'data' => ['type' => 'string']],
        ['type' => 'trim', 'data' => []],
    ]);
    expect($this->field->getAllowedMoves())->toBe([[0, 1], [0, 1]]);
});

it('uses transformation schema callbacks when deciding whether a move is allowed', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(
        new FakeTransform(
            'make_object',
            accepts: fn (JsonSchema $input) => $input->singleType() === 'string',
            output: fn () => JsonSchema::fromArray(['type' => 'object']),
        ),
        new FakeTransform(
            'read_object',
            accepts: fn (JsonSchema $schema) => $schema->singleType() === 'object',
            output: fn (JsonSchema $schema, array $params) => JsonSchema::fromArray(['type' => $params['type']]),
        ),
    );
    $this->field->state([
        ['type' => 'make_object', 'data' => []],
        ['type' => 'read_object', 'data' => ['type' => 'string']],
        ['type' => 'trim', 'data' => []],
    ]);
    expect($this->field->getAllowedMoves())->toBe([[0], [1], [0, 2]]);
});

it('checks the current input type again before applying a reordered chain', function () {
    $registry = app(TransformRegistry::class);
    $settings = [
        ['type' => 'cast', 'data' => ['type' => 'string']],
        ['type' => 'trim', 'data' => []],
    ];
    $this->field->state($settings);
    expect($this->field->getAllowedMoves())->toBe([[0, 1], [0, 1]]);
    $this->field->inputSchema(['type' => 'boolean']);
    expect(fn () => ($this->action)(['order' => ['1', '0']])->call())->toThrow(ValidationException::class);
    expect($this->field->getSettings())->toBe($settings);
});

it('registers independent transform implementations and uses their native blocks', function () {
    $transform = new class implements Transform {
        public function getKey(): string
        {
            return 'custom';
        }

        public function getTarget(): TransformTarget
        {
            return TransformTarget::Value;
        }

        public function accepts(JsonSchema $input): bool
        {
            return $input->singleType() === 'string';
        }

        public function output(JsonSchema $input, array $params): ?JsonSchema
        {
            return JsonSchema::fromArray(['type' => 'string', 'maxLength' => strlen($params['value'])]);
        }

        public function connector(string $field, array $params): array
        {
            return ['type' => 'example.Custom', 'field' => $field, 'value' => $params['value']];
        }

        public function block(): Block
        {
            return Block::make($this->getKey())
                ->label('Custom')
                ->schema([
                    TextInput::make('value')->required(),
                ]);
        }
    };
    $registry = app(TransformRegistry::class);
    $registry->register($transform);

    expect($registry->getOrFail('custom'))->toBe($transform);
    expect($this->field->getAvailableTransforms($registry)['custom'])->toBe($transform);
    expect(array_keys($this->field->getAvailableBlocks()))->toContain('custom');
    $action = BuilderAction::make($this->field)->schemaComponent($this->field);
    $action(['block' => 'custom'])->data(['data' => ['value' => 'hello']])->call();

    expect($this->field->getContainer()->getState()['transforms'])
        ->toBe([['type' => 'custom', 'data' => ['value' => 'hello']]]);
    expect($this->field->getInputSchema()->maxLength())->toBe(5);
    $replica = new Replica(['mapping' => [
        new FieldMapping(source: 'name', target: 'name', options: [
            'transforms' => $this->field->getSettings(),
        ]),
    ]]);
    $config = app(TransformFactory::class)->pipeline($replica, new StructureItem);
    expect($config['transforms.field_0_0.type'])->toBe('example.Custom');
    expect($config['transforms.field_0_0.field'])->toBe('name');
    expect($config['transforms.field_0_0.value'])->toBe('hello');
});

it('passes the selected object and preceding schema to each settings form', function () {
    $object = new StructureItem;
    $context = [];
    $this->field->object($object);
    app(TransformRegistry::class)->register(
        new FakeTransform('custom', block: Block::make('custom')->schema(
            function (FieldTransforms $component, ?int $index) use (&$context) {
                $context = [$component->getObject(), $component->getInputSchema($index)->singleType()];

                return [TextInput::make('value')];
            },
        )),
    );
    $this->field->state([
        ['type' => 'cast', 'data' => ['type' => 'boolean']],
        ['type' => 'custom', 'data' => []],
        ['type' => 'cast', 'data' => ['type' => 'string']],
    ]);

    $action = BuilderAction::make($this->field)->schemaComponent($this->field);
    expect($action(['block' => 'custom', 'index' => 1])->shouldOpenModal())->toBeTrue();
    expect($context)->toBe([$object, 'boolean']);
});
