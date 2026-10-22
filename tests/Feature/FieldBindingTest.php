<?php

use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPinnacle\Ferry\Forms\FieldBinding;
use PHPinnacle\Ferry\Tests\Fixtures\CustomerMappingField;
use PHPinnacle\Ferry\Tests\Fixtures\FieldBindingForm;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/CustomerMappingField.php';
require_once __DIR__ . '/../Fixtures/FieldBindingForm.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

it('binds, edits, validates, and unbinds across Livewire requests', function (bool $complex) {
    $binding = $complex ? ['destination' => 'profile.name', 'trim' => true] : 'profile.name';

    $page = Livewire::test(FieldBindingForm::class, ['complex' => $complex]);
    $key = $page->instance()->form->getComponent('bindings')->getBindingKey('name');

    $page
        ->callAction(TestAction::make('bind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->assertSee('1 of 6 sources bound')
        ->call('submit')
        ->assertHasErrors()
        ->set("data.bindings.bindings.{$key}.destination", 'profile.name')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings.bindings', ['name' => $binding])
        ->callAction(TestAction::make('unbind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->assertSee('0 of 6 sources bound')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings.bindings', []);
})->with([false, true]);

it('loads existing bindings and edits them across Livewire requests', function (bool $complex) {
    $original = $complex ? ['destination' => 'profile.name', 'trim' => false] : 'profile.name';
    $edited = $complex ? ['destination' => 'customer.name', 'trim' => false] : 'customer.name';

    $page = Livewire::test(FieldBindingForm::class, ['complex' => $complex, 'bindings' => ['name' => $original]]);
    $key = $page->instance()->form->getComponent('bindings')->getBindingKey('name');

    $page
        ->assertSee('1 of 6 sources bound')
        ->set("data.bindings.bindings.{$key}.destination", 'customer.name')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings.bindings', ['name' => $edited]);
})->with([false, true]);

/** @param array<string, mixed> $state */
function field_binding_schema(FieldBinding $field, array $state = []): Schema
{
    $livewire = SchemaHost::make();

    $schema = Schema::make($livewire)->statePath('data')->components([$field]);
    $schema->fill(['bindings' => ['sources' => [], 'bindings' => $state]]);

    return $schema;
}

it('only filters bindings by search when enabled, including closure configuration', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field, ['name' => 'profile.name']);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())->not->toContain('type="search"');
    $field->searchable();
    expect(substr_count($schema->toHtml(), 'type="search"'))->toBe(1);
    $field->searchable(false);
    expect($schema->toHtml())->not->toContain('type="search"');

    $enabled = true;
    $field->searchable(function () use (&$enabled) {
        return $enabled;
    });
    expect(substr_count($schema->toHtml(), 'type="search"'))->toBe(1);
    $enabled = false;
    expect($schema->toHtml())->not->toContain('type="search"');
    expect($schema->getState()['bindings']['bindings'])->toBe(['name' => 'profile.name']);
});

it('supports partial updates to a child binding field', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->simple(TextInput::make('destination')->required());
    $schema = field_binding_schema($field, ['name' => 'profile.name']);

    $key = $field->getBindingKey('name');
    $schema->fillPartially(
        ['bindings' => ['bindings' => [$key => ['destination' => 'customer.name']]]],
        ["bindings.bindings.{$key}.destination"],
    );

    expect($schema->getState()['bindings']['bindings'])->toBe(['name' => 'customer.name']);
});

it('round trips object bindings and applies child dehydration callbacks', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->schema([
            TextInput::make('destination')
                ->required()
                ->dehydrateStateUsing(fn (string $state) => strtoupper($state)),
            Toggle::make('trim'),
            TextInput::make('preview')->dehydrated(false),
        ]);
    $schema = field_binding_schema($field, [
        'name' => ['destination' => 'customer.name', 'trim' => true, 'preview' => 'Preview'],
    ]);

    expect($schema->getState())->toBe([
        'bindings' => ['sources' => [], 'bindings' => ['name' => ['destination' => 'CUSTOMER.NAME', 'trim' => true]]],
    ]);
});

it('binds with schema defaults and unbinds without changing other sources', function () {
    $updates = 0;
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->schema([
            TextInput::make('destination')->default('profile.email')->required(),
            Toggle::make('trim')->default(true),
        ])
        ->afterStateUpdated(function () use (&$updates) {
            $updates++;
        });
    $schema = field_binding_schema($field, ['name' => ['destination' => 'profile.name', 'trim' => false]]);

    $field->getAction('bind')(['source' => 'email'])->call();

    expect($schema->getState()['bindings']['bindings'])->toBe([
        'name' => ['destination' => 'profile.name', 'trim' => false],
        'email' => ['destination' => 'profile.email', 'trim' => true],
    ]);

    $field->getAction('unbind')(['source' => 'name'])->call();

    expect($schema->getState()['bindings']['bindings'])->toBe([
        'email' => ['destination' => 'profile.email', 'trim' => true],
    ]);
    expect($updates)->toBe(2);
});

it('binding an existing source preserves its configuration', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination')->default('default.name'));
    $schema = field_binding_schema($field, ['name' => 'profile.name']);

    $field->getAction('bind')(['source' => 'name'])->call();

    expect($schema->getState()['bindings']['bindings'])->toBe(['name' => 'profile.name']);
});

it('treats absent optional state as no bindings', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field);
    $field->rawState(null);

    expect($schema->getState())->toBe(['bindings' => ['sources' => [], 'bindings' => []]]);
});

it('refreshes available sources without discarding a binding that became unavailable', function () {
    $sources = ['name', 'email'];
    $field = FieldBinding::make('bindings')
        ->jsonSchema(function () use (&$sources) {
            return ['type' => 'object', 'properties' => array_fill_keys($sources, [])];
        })
        ->simple(TextInput::make('destination')->required());
    $schema = field_binding_schema($field, ['name' => 'profile.name']);
    expect($field->getBindings())->toHaveCount(1);
    $state = $field->getRawState();

    $sources = ['email'];

    expect($field->getBindings())->toBe([]);
    expect($field->getRawState())->toBe($state);
    expect($schema->getState(...))->toThrow(ValidationException::class);
});

it('binds simple fields with their supplied names and defaults', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(fn () => TextInput::make('routing.path')->default('profile.name')->required());
    $schema = field_binding_schema($field);

    $field->getAction('bind')(['source' => 'name'])->call();

    expect($schema->getState()['bindings']['bindings'])->toBe(['name' => 'profile.name']);
});

it('keeps dotted and numeric source identifiers literal', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema([
            'type' => 'object',
            'properties' => [
                'customer.name' => ['title' => 'Name'],
                '0' => ['title' => 'Zero'],
                '00' => ['title' => 'Double zero'],
            ],
        ])
        ->simple(TextInput::make('destination')->required());
    $state = ['customer.name' => 'profile.name', '0' => 'zero', '00' => 'double_zero'];
    $schema = field_binding_schema($field, $state);

    expect($schema->getState()['bindings']['bindings'])->toBe($state);
});

it('hydrates configured default bindings and nested field defaults', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->schema([
            TextInput::make('destination')->default('default.path')->required(),
            Toggle::make('trim')->default(true),
        ])
        ->default(['sources' => [], 'bindings' => ['name' => ['destination' => 'profile.name', 'trim' => false]]]);
    $schema = field_binding_schema($field);
    $schema->fill();

    expect($schema->getState()['bindings']['bindings'])->toBe([
        'name' => ['destination' => 'profile.name', 'trim' => false],
    ]);
});

it('can be nested in a repeater with independent bindings', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination')->required());
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components([Repeater::make('bindings')->schema([$field])]);
    $schema->fill([
        'bindings' => [
            ['bindings' => ['sources' => [], 'bindings' => ['name' => 'first.name']]],
            ['bindings' => ['sources' => [], 'bindings' => ['name' => 'second.name']]],
        ],
    ]);

    expect($schema->getState()['bindings'])->toBe([
        ['bindings' => ['sources' => [], 'bindings' => ['name' => 'first.name']]],
        ['bindings' => ['sources' => [], 'bindings' => ['name' => 'second.name']]],
    ]);
});

it('keeps callbacks and relative reads isolated between bindings', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->schema([
            TextInput::make('destination')
                ->required()
                ->afterStateUpdated(function (TextInput $component) {
                    $component->state(strtoupper($component->getState()));
                }),
            TextInput::make('suffix')->required(fn (Get $get) => $get('destination') === 'PROFILE.NAME'),
        ]);
    $schema = field_binding_schema($field, [
        'name' => ['destination' => 'profile.name', 'suffix' => 'required'],
        'email' => ['destination' => 'profile.email', 'suffix' => null],
    ]);
    $nameSchema = $field->getBindings()[$field->getBindingKey('name')];
    $nameSchema->getComponent('destination')->callAfterStateUpdated();

    expect($schema->getState()['bindings']['bindings'])->toBe([
        'name' => ['destination' => 'PROFILE.NAME', 'suffix' => 'required'],
        'email' => ['destination' => 'profile.email', 'suffix' => null],
    ]);

    $nameSchema->getComponent('suffix')->state(null);

    expect($schema->getState(...))->toThrow(ValidationException::class);
});

it('preserves nested repeater and builder state', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->schema([
            Repeater::make('rules')->schema([TextInput::make('pattern')->required()]),
            Builder::make('steps')->blocks([
                Block::make('rename')->schema([TextInput::make('destination')->required()]),
            ]),
        ]);
    $state = [
        'name' => [
            'rules' => [['pattern' => '.*']],
            'steps' => [['type' => 'rename', 'data' => ['destination' => 'profile.name']]],
        ],
    ];
    $schema = field_binding_schema($field, $state);

    expect($schema->getState()['bindings']['bindings'])->toBe($state);
});

it('disables bind and unbind actions and child inputs for a disabled component', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->simple(TextInput::make('destination')->required())
        ->disabled();
    field_binding_schema($field, ['name' => 'profile.name']);
    $state = $field->getRawState();

    $field->getAction('bind')(['source' => 'email'])->call();
    $field->getAction('unbind')(['source' => 'name'])->call();

    expect($field->getRawState())->toBe($state);
    expect($field->getAction('bind')->isDisabled())->toBeTrue();
    expect($field->getBindings()[$field->getBindingKey('name')]->getComponent('destination')->isDisabled())->toBeTrue();
});

it('rejects unknown or malformed source action arguments', function (mixed $source) {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination'));
    field_binding_schema($field);

    expect(fn () => $field->getAction('bind')(['source' => $source])->call())->toThrow(ValidationException::class);
    expect($field->getRawState())->toBe(['sources' => [], 'bindings' => []]);
})->with([
    'unknown source' => ['unknown'],
    'null source' => [null],
    'numeric source' => [42],
    'array source' => [['name']],
]);

it('rejects forged binding shapes and unknown sources on submission', function (mixed $state) {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field);
    $field->rawState($state);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'scalar state' => ['invalid'],
    'unknown source' => [['source_unknown' => ['destination' => 'profile.name']]],
    'scalar binding' => [['source_6e616d65' => 'profile.name']],
    'nested simple value' => [['source_6e616d65' => ['destination' => ['path' => 'profile.name']]]],
    'empty simple value' => [['source_6e616d65' => ['destination' => '']]],
]);

it('preserves binding metadata and values across presentation options', function (
    bool $contained,
    bool $compact,
) {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(CustomerMappingField::jsonSchema(...))
        ->labels(source: fn () => 'Incoming', dest: 'Output')
        ->compact(fn () => $compact)
        ->simple(TextInput::make('destination')->required());
    if (!$contained) {
        $field->contained(false);
    }
    $schema = field_binding_schema($field, ['name' => 'profile.name']);
    view()->share('errors', new ViewErrorBag);

    $text = preg_replace('/\s+/', ' ', strip_tags($schema->toHtml()));
    expect($text)->toContain('Customer name', 'The name displayed on the customer profile.', 'Incoming', 'Output');
    expect($text)->toContain('Required', 'Unique string');
    expect(str_contains($text, 'Show unbound'))->toBe($contained);
    expect(str_contains($text, '1 of 6 sources bound'))->toBe($contained);
    expect($schema->getState()['bindings']['bindings'])->toBe(['name' => 'profile.name']);
})->with([true, false])->with([true, false]);

it('keeps the binding toolbar around the empty state', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys([], [])])
        ->searchable()
        ->labels(source: 'Incoming fields', dest: 'Output fields')
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())
        ->toContain('fi-empty-state')
        ->toContain('No fields available.')
        ->not->toContain('Incoming fields')
        ->not->toContain('Output fields')
        ->not->toContain('type="search"')->toContain('Show unbound')->toContain('0 of 0 sources bound')
        ->not->toContain(
            'Bind a source to configure it. Unbind to exclude it.',
        );
});

function field_binding_constants(): FieldBinding
{
    return FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->simple(TextInput::make('destination')->required())
        ->constants(fn () => [
            TextInput::make('column')->required(),
            TextInput::make('value')->required(),
        ]);
}

it('round trips static values beside bindings without treating them as sources', function () {
    $field = field_binding_constants();
    $state = ['name' => 'profile.name', 'status' => ['value' => 'active']];
    $schema = field_binding_schema($field, $state);

    expect($field->getSources())
        ->toHaveCount(2)
        ->and($field->getConstants())
        ->toHaveCount(1)
        ->and($schema->getState()['bindings']['bindings'])
        ->toBe($state);
});

it('adds, edits and removes a static value', function () {
    $updates = 0;
    $field = field_binding_constants()->afterStateUpdated(function () use (&$updates) {
        $updates++;
    });
    $schema = field_binding_schema($field, ['name' => 'profile.name']);

    $field->getAction('addConstant')([])->call();

    $key = array_key_first($field->getConstants());
    $field->getConstants()[$key]->getComponent('column')->state('status');
    $field->getConstants()[$key]->getComponent('value')->state('active');

    expect($schema->getState()['bindings']['bindings'])
        ->toBe(['name' => 'profile.name', 'status' => ['value' => 'active']]);

    $field->getAction('removeConstant')(['constant' => $key])->call();

    expect($schema->getState()['bindings']['bindings'])->toBe(['name' => 'profile.name'])->and($updates)->toBe(2);
});

it('validates every static value field', function () {
    $field = field_binding_constants();
    $schema = field_binding_schema($field, ['status' => ['value' => 'active']]);
    $field->getConstants()[array_key_first($field->getConstants())]->getComponent('value')->state('');

    expect($schema->getState(...))->toThrow(ValidationException::class);
});

it('rejects static value keys that would overwrite bindings or other static values', function (string $column) {
    $field = field_binding_constants();
    $schema = field_binding_schema($field, ['name' => 'profile.name', 'status' => ['value' => 'active']]);
    $field->getAction('addConstant')([])->call();
    $constant = $field->getConstants()[array_key_last($field->getConstants())];
    $constant->getComponent('column')->state($column);
    $constant->getComponent('value')->state('replacement');
    $state = $field->getRawState();

    expect($schema->getState(...))->toThrow(ValidationException::class);
    expect($field->getRawState())->toBe($state);
})->with(['name', 'status']);

it('rejects static values unless the component enables them', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field);
    $field->rawState([
        'sources' => [],
        'bindings' => ['constant_737461747573' => ['column' => 'status', 'value' => 'active']],
    ]);

    expect($schema->getState(...))->toThrow(ValidationException::class);
});

it('rejects forged static value actions and disabled changes', function () {
    $field = field_binding_constants();
    field_binding_schema($field, ['status' => ['value' => 'active']]);
    $state = $field->getRawState();

    expect(fn () => $field->getAction('removeConstant')(['constant' => 'source_6e616d65'])->call())
        ->toThrow(
            ValidationException::class,
        );

    $field->disabled();
    $field->getAction('addConstant')([])->call();
    $field->getAction('removeConstant')(['constant' => 'constant_737461747573'])->call();

    expect($field->getRawState())->toBe($state);
});

it('renders the static value controls and counts only bound sources', function () {
    $schema = field_binding_schema(field_binding_constants(), ['name' => 'profile.name', 'status' => ['value' => 'x']]);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())
        ->toContain('1 of 2 sources bound')
        ->toContain('Static values')
        ->toContain('Add static value');

    $plain = field_binding_schema(
        FieldBinding::make('bindings')
            ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
            ->simple(TextInput::make('d')),
    );

    expect($plain->toHtml())->not->toContain('Static values');
});

it('renders extra source actions on bound and unbound sources', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->simple(TextInput::make('destination'))
        ->extraSourceActions([Action::make('inspect')->label(
            fn (array $arguments) => 'Inspect ' . $arguments['source'],
        )]);
    $schema = field_binding_schema($field, ['name' => 'profile.name']);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())->toContain('Inspect name')->toContain('Inspect email');
});

it('highlights warned sources and preserves warnings for missing source identifiers', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->warnings(fn () => ['name' => 'Review <name>.', '42' => 'Source disappeared.'])
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field, ['name' => 'profile.name']);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();

    expect($html)
        ->toContain('Review &lt;name&gt;.')
        ->toContain('42: Source disappeared.');
});

it('keeps source settings available before binding and after unbinding across Livewire requests', function (bool $complex) {
    $page = Livewire::test(FieldBindingForm::class, ['complex' => $complex, 'sourceSettings' => true]);
    $page
        ->assertSet('data.bindings.sources.source_6e616d65.alias', 'name')
        ->set('data.bindings.sources.source_6e616d65.alias', 'Customer')
        ->set('data.bindings.sources.source_6e616d65.enabled', false)
        ->callAction(TestAction::make('bind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->set('data.bindings.bindings.source_6e616d65.destination', 'profile.name')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings.sources.name', ['alias' => 'Customer', 'enabled' => false])
        ->callAction(TestAction::make('unbind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings.bindings', [])
        ->assertSet('saved.bindings.sources.name', ['alias' => 'Customer', 'enabled' => false])
        ->set('data.bindings.sources.source_6e616d65.alias', '')
        ->call('submit')
        ->assertHasErrors(['data.bindings.sources.source_6e616d65.alias' => 'required']);
})->with([false, true]);
