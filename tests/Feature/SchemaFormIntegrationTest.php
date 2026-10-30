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
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Trim;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

it('renders native static value placeholders in the replica editor locale', function (
    string $locale,
    string $valueLabel,
    string $addLabel,
) {
    \Illuminate\Support\Facades\Queue::fake();
    app()->setLocale($locale);
    __('phpinnacle-ferry::resources.replica.fields.constant_value');
    app('translator')->addLines(
        ['resources.replica.fields.constant_value' => 'Replica-only value'],
        $locale,
        'phpinnacle-ferry',
    );
    $form = TestCase::replicaForm([
        'mapping' => [
            TestCase::keyMapping()->jsonSerialize(),
            ['kind' => 'static', 'target' => 'constant', 'static' => 'draft', 'options' => []],
        ],
    ]);
    view()->share('errors', new ViewErrorBag);

    expect($form->getComponentByStatePath('mapping')->toHtml())
        ->toContain('placeholder="' . $valueLabel . '"', $addLabel);
})->with([
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
        ? FieldBinding::make('widget')
            ->jsonSchema(JsonSchema::fromArray($source))
            ->simple(TextInput::make('column')->default('output'))
        : FieldMapping::make('widget')->jsonSchema(
            source: JsonSchema::fromArray($source),
            target: JsonSchema::fromArray([
                'properties' => ['output' => ['type' => $type]],
            ]),
        );
    $field->sourceSettings([FieldTransforms::make('transforms')->inputSchema(JsonSchema::fromArray([
        'type' => $type,
    ]))]);
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
