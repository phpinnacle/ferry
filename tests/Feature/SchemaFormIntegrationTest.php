<?php

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Eidos\Forms\FieldBinding;
use PHPinnacle\Eidos\Forms\FieldMapping;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Forms\FieldTransforms;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Transformations\Cast;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Trim;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/MappingState.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

it('keeps schema form translations independent of replica labels', function (
    bool $binding,
    string $locale,
    string $valueLabel,
    string $addLabel,
) {
    app()->setLocale($locale);
    // Replica label overrides must not change reusable form fields.
    __('phpinnacle-ferry::resources.replica.fields.constant_value');
    app('translator')->addLines(
        [
            'resources.replica.fields.constant_value' => 'Replica-only value',
        ],
        $locale,
        'phpinnacle-ferry',
    );
    $source = ['properties' => ['name' => ['type' => 'string']]];
    $field = $binding
        ? FieldBinding::make('mapping')->jsonSchema($source)->simple(TextInput::make('destination'))
        : FieldMapping::make('mapping')->jsonSchema(source: $source, dest: [
            'properties' => ['name' => ['type' => 'string'], 'constant' => ['type' => 'string']],
        ]);
    $field->staticValues();
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill([
        'mapping' => [
            ['kind' => 'source', 'source' => 'name', 'target' => 'name', 'options' => []],
            ['kind' => 'static', 'target' => 'constant', 'static' => 'draft', 'options' => []],
        ],
    ]);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();
    expect($html)
        ->toContain('placeholder="' . $valueLabel . '"', $addLabel)
        ->not->toContain('Replica-only value', 'phpinnacle-eidos::schema-forms.');
})->with([true, false])->with([
    'English' => ['en', 'Value', 'Add static value'],
    'Russian' => ['ru', 'Значение', 'Добавить статическое значение'],
]);

it('validates transform settings on unconnected sources', function (
    bool $binding,
    string $type,
    array $transforms,
) {
    app(TransformRegistry::class)->register(Trim::make(), MaskField::make());
    $source = ['properties' => ['input' => ['type' => $type]]];
    $field = $binding
        ? FieldBinding::make('widget')->jsonSchema($source)->simple(TextInput::make('column')->default('output'))
        : FieldMapping::make('widget')->jsonSchema(source: $source, dest: [
            'properties' => ['output' => ['type' => $type]],
        ]);
    $field->sourceSettings([FieldTransforms::make('transforms')->inputSchema(['type' => $type])]);
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill(['widget' => []]);
    $settingsKey = $field->getSourceRowIndexes()['input'];
    $schema->getComponentByStatePath('widget.' . $settingsKey . '.options.transforms')->state($transforms);

    expect($schema->getState(...))->toThrow(ValidationException::class);

    if ($binding) {
        $field->getAction('bind')(['source' => 'input'])->call();
    } else {
        $state = $field->getRawState();
        $state[$field->getSourceRowIndex('input')]['target'] = 'output';
        $field->rawState($state);
    }

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([false, true])->with([
    'incompatible transform' => ['boolean', [['type' => 'trim', 'data' => []]]],
    'missing parameters' => ['string', [['type' => 'mask', 'data' => []]]],
]);

it('checks static source compatibility after transforms and validates connected values', function () {
    app(TransformRegistry::class)->register(Cast::make());
    $field = FieldMapping::make('mapping')
        ->staticValues()
        ->jsonSchema(dest: ['properties' => ['count' => ['type' => 'integer']]])
        ->sourceSettings(fn (Closure $inputSchema) => [
            FieldTransforms::make('transforms')->inputSchema(fn () => $inputSchema()->toArray()),
        ])
        ->outputSchema(function (FieldMapping $component, TransformRegistry $registry) {
            $schema = $component->getSourceSchema();
            foreach ($component->getSourceSettings() as $source => $settings) {
                $schema['properties'][$source] = $registry
                    ->resultSchema(
                        JsonSchema::fromArray($schema['properties'][$source]),
                        $settings['transforms'] ?? [],
                    )
                    ?->toArray() ?? [];
            }

            return $schema;
        });
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill();
    $field->getAction('addConstant')->call();
    $source = array_key_first($field->getConstants());
    expect($field->getConnectionModes())->toBe([]);
    $key = $field->getSourceRowIndexes()[$source];
    $schema
        ->getComponentByStatePath('mapping.' . $key . '.options.transforms')
        ->state([
            ['type' => 'cast', 'data' => ['type' => 'int64']],
        ]);
    expect($field->getConnectionModes()[$source]['count'])->toBe('whole');
    $state = $field->getRawState();
    $state[$field->getSourceRowIndex($source)]['target'] = 'count';
    $field->rawState($state);
    expect($schema->getState(...))->toThrow(ValidationException::class);
    $field->getConstantValueSchema($source)->getComponent('static')->state('10');
    expect($schema->getState()['mapping'])->toBe([[
        'kind' => 'static',
        'target' => 'count',
        'static' => '10',
        'options' => ['transforms' => [['type' => 'cast', 'data' => ['type' => 'int64']]]],
    ]]);
});

it('uses a generic value label independently of replica labels', function () {
    __('phpinnacle-ferry::resources.replica.fields.constant_value');
    app('translator')->addLines(
        [
            'resources.replica.fields.constant_value' => 'Replica-only value',
        ],
        'en',
        'phpinnacle-ferry',
    );
    $field = FieldMapping::make('mapping')
        ->jsonSchema(dest: ['properties' => ['target' => ['type' => 'string']]])
        ->withBuiltinStaticForms();
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill();
    $field = $schema->getComponent('mapping')->getStaticFormSchema('target')->getComponent('value');

    expect($field->getLabel())->toBe('Value');
});
