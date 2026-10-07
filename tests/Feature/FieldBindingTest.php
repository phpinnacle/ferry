<?php

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Livewire;
use PHPinnacle\Ferry\Forms\FieldBinding;
use PHPinnacle\Ferry\Tests\Fixtures\CustomerMappingField;
use PHPinnacle\Ferry\Tests\Fixtures\FieldBindingForm;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/CustomerMappingField.php';
require_once __DIR__ . '/../Fixtures/FieldBindingForm.php';

uses(TestCase::class);

it('binds, edits, validates, and unbinds across Livewire requests', function (bool $complex) {
    $key = 'source_6e616d65';
    $binding = $complex ? ['destination' => 'profile.name', 'trim' => true] : 'profile.name';

    Livewire::test(FieldBindingForm::class, ['complex' => $complex])
        ->callAction(TestAction::make('bind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->assertSee('1 of 6 sources bound')
        ->call('submit')
        ->assertHasErrors()
        ->set("data.bindings.{$key}.destination", 'profile.name')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings', ['name' => $binding])
        ->callAction(TestAction::make('unbind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->assertSee('0 of 6 sources bound')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings', []);
})->with([false, true]);

it('loads existing bindings and edits them across Livewire requests', function (bool $complex) {
    $original = $complex ? ['destination' => 'profile.name', 'trim' => false] : 'profile.name';
    $edited = $complex ? ['destination' => 'customer.name', 'trim' => false] : 'customer.name';

    Livewire::test(FieldBindingForm::class, ['complex' => $complex, 'bindings' => ['name' => $original]])
        ->assertSee('1 of 6 sources bound')
        ->set('data.bindings.source_6e616d65.destination', 'customer.name')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings', ['name' => $edited]);
})->with([false, true]);

/** @param array<string, mixed> $state */
function field_binding_schema(Field $field, array $state = []): Schema
{
    $livewire = new class extends Component implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('field-binding-test');
    $livewire->setName('field-binding-test');

    $schema = Schema::make($livewire)->statePath('data')->components([$field]);
    $schema->fill(['bindings' => $state]);

    return $schema;
}

it('supports partial updates to a child binding field', function () {
    $field = FieldBinding::make('bindings')
        ->options(['name', 'email'])
        ->simple(TextInput::make('destination')->required());
    $schema = field_binding_schema($field, ['name' => 'profile.name']);

    $key = $field->getBindingKey('name');
    $schema->fillPartially(
        ['bindings' => [$key => ['destination' => 'customer.name']]],
        ["bindings.{$key}.destination"],
    );

    expect($schema->getState()['bindings'])->toBe(['name' => 'customer.name']);
});

it('round trips object bindings and applies child dehydration callbacks', function () {
    $field = FieldBinding::make('bindings')
        ->options(['name', 'email'])
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
        'bindings' => ['name' => ['destination' => 'CUSTOMER.NAME', 'trim' => true]],
    ]);
});

it('binds with schema defaults and unbinds without changing other sources', function () {
    $updates = 0;
    $field = FieldBinding::make('bindings')
        ->options(['name', 'email'])
        ->schema([
            TextInput::make('destination')->default('profile.email')->required(),
            Toggle::make('trim')->default(true),
        ])
        ->afterStateUpdated(function () use (&$updates) {
            $updates++;
        });
    $schema = field_binding_schema($field, ['name' => ['destination' => 'profile.name', 'trim' => false]]);

    $field->getAction('bind')(['source' => 'email'])->call();

    expect($schema->getState()['bindings'])->toBe([
        'name' => ['destination' => 'profile.name', 'trim' => false],
        'email' => ['destination' => 'profile.email', 'trim' => true],
    ]);

    $field->getAction('unbind')(['source' => 'name'])->call();

    expect($schema->getState()['bindings'])->toBe([
        'email' => ['destination' => 'profile.email', 'trim' => true],
    ]);
    expect($updates)->toBe(2);
});

it('binding an existing source preserves its configuration', function () {
    $field = FieldBinding::make('bindings')
        ->options(['name'])
        ->simple(TextInput::make('destination')->default('default.name'));
    $schema = field_binding_schema($field, ['name' => 'profile.name']);

    $field->getAction('bind')(['source' => 'name'])->call();

    expect($schema->getState()['bindings'])->toBe(['name' => 'profile.name']);
});

it('treats absent optional state as no bindings', function () {
    $field = FieldBinding::make('bindings')->options(['name'])->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field);
    $field->rawState(null);

    expect($schema->getState())->toBe(['bindings' => []]);
});

it('refreshes available sources without discarding a binding that became unavailable', function () {
    $sources = ['name', 'email'];
    $field = FieldBinding::make('bindings')
        ->options(function () use (&$sources) {
            return $sources;
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
        ->options(['name'])
        ->simple(fn () => TextInput::make('routing.path')->default('profile.name')->required());
    $schema = field_binding_schema($field);

    $field->getAction('bind')(['source' => 'name'])->call();

    expect($schema->getState()['bindings'])->toBe(['name' => 'profile.name']);
});

it('keeps dotted and numeric source identifiers literal', function () {
    $field = FieldBinding::make('bindings')
        ->options(['customer.name' => 'Name', '0' => 'Zero', '00' => 'Double zero'])
        ->simple(TextInput::make('destination')->required());
    $state = ['customer.name' => 'profile.name', '0' => 'zero', '00' => 'double_zero'];
    $schema = field_binding_schema($field, $state);

    expect($schema->getState()['bindings'])->toBe($state);
});

it('hydrates configured default bindings and nested field defaults', function () {
    $field = FieldBinding::make('bindings')
        ->options(['name', 'email'])
        ->schema([
            TextInput::make('destination')->default('default.path')->required(),
            Toggle::make('trim')->default(true),
        ])
        ->default(['name' => ['destination' => 'profile.name', 'trim' => false]]);
    $schema = field_binding_schema($field);
    $schema->fill();

    expect($schema->getState()['bindings'])->toBe([
        'name' => ['destination' => 'profile.name', 'trim' => false],
    ]);
});

it('can be nested in a repeater with independent bindings', function () {
    $field = FieldBinding::make('bindings')
        ->options(['name'])
        ->simple(TextInput::make('destination')->required());
    $schema = field_binding_schema(Repeater::make('bindings')->schema([$field]), [
        ['bindings' => ['name' => 'first.name']],
        ['bindings' => ['name' => 'second.name']],
    ]);

    expect($schema->getState()['bindings'])->toBe([
        ['bindings' => ['name' => 'first.name']],
        ['bindings' => ['name' => 'second.name']],
    ]);
});

it('keeps callbacks and relative reads isolated between bindings', function () {
    $field = FieldBinding::make('bindings')
        ->options(['name', 'email'])
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

    expect($schema->getState()['bindings'])->toBe([
        'name' => ['destination' => 'PROFILE.NAME', 'suffix' => 'required'],
        'email' => ['destination' => 'profile.email', 'suffix' => null],
    ]);

    $nameSchema->getComponent('suffix')->state(null);

    expect($schema->getState(...))->toThrow(ValidationException::class);
});

it('preserves nested repeater and builder state', function () {
    $field = FieldBinding::make('bindings')
        ->options(['name'])
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

    expect($schema->getState()['bindings'])->toBe($state);
});

it('disables bind and unbind actions and child inputs for a disabled component', function () {
    $field = FieldBinding::make('bindings')
        ->options(['name', 'email'])
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
    $field = FieldBinding::make('bindings')->options(['name'])->simple(TextInput::make('destination'));
    field_binding_schema($field);

    expect(fn () => $field->getAction('bind')(['source' => $source])->call())->toThrow(ValidationException::class);
    expect($field->getRawState())->toBe([]);
})->with([
    'unknown source' => ['unknown'],
    'null source' => [null],
    'numeric source' => [42],
    'array source' => [['name']],
]);

it('rejects forged binding shapes and unknown sources on submission', function (mixed $state) {
    $field = FieldBinding::make('bindings')->options(['name'])->simple(TextInput::make('destination'));
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

it('renders source metadata, binding schemas, and controls without ignoring Livewire', function () {
    $field = FieldBinding::make('bindings')
        ->options(CustomerMappingField::cases(...))
        ->labels(source: fn () => 'Incoming', dest: 'Output')
        ->simple(TextInput::make('destination')->required());
    $schema = field_binding_schema($field, ['name' => 'profile.name']);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())
        ->toContain('Customer name')
        ->toContain('The name displayed on the customer profile.')
        ->toContain('Incoming')
        ->toContain('Output')
        ->toContain('1 of 6 sources bound')
        ->toContain('Show unbound')
        ->toContain('data.bindings.source_6e616d65.destination')
        ->not->toContain('wire:ignore');
});
