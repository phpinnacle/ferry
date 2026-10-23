<?php

use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPinnacle\Ferry\Forms\FieldBinding;
use PHPinnacle\Ferry\Tests\Fixtures\CustomerMappingField;
use PHPinnacle\Ferry\Tests\Fixtures\FieldBindingForm;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/CustomerMappingField.php';
require_once __DIR__ . '/../Fixtures/FieldBindingForm.php';
require_once __DIR__ . '/../Fixtures/MappingState.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

it('renders unbound forms without submitting their editor drafts', function (bool $complex) {
    $page = Livewire::test(FieldBindingForm::class, ['complex' => $complex]);
    $field = $page->instance()->form->getComponent('bindings');
    $key = $field->getBindingKey('name');

    expect(substr_count($page->html(), 'class="field-binding-overlay"'))->toBe(6);
    expect(substr_count($page->html(), 'type="text"'))->toBe(6);
    expect($page->html())->not->toContain('heroicon-m-arrow-right');

    if ($complex) {
        expect($field->getBindingSchema('name')->getComponent('trim')->getState())->toBeTrue();
    }

    $page
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings', MappingState::binding([], [], array_column($field->getSources(), 'id')));
    $page->callAction(TestAction::make('bind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']));

    $page->assertSet("data.bindings.bound.{$key}", true);
    $page->call('submit')->assertHasErrors();

    $page
        ->callAction(TestAction::make('unbind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings', MappingState::binding([], [], array_column($field->getSources(), 'id')));

    expect(substr_count($page->html(), 'class="field-binding-overlay"'))->toBe(6);
    expect(substr_count($page->html(), 'type="text"'))->toBe(6);
})->with([false, true]);

it('binds, edits, validates, and unbinds across Livewire requests', function (bool $complex) {
    $binding = $complex ? ['destination' => 'profile.name', 'trim' => true] : 'profile.name';

    $page = Livewire::test(FieldBindingForm::class, ['complex' => $complex]);
    $key = $page->instance()->form->getComponent('bindings')->getBindingKey('name');

    $page
        ->assertSee('aria-label="Bind"', false)
        ->callAction(TestAction::make('bind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->assertSee('1 of 6 sources bound')
        ->assertSee('aria-label="Unbind Customer name"', false)
        ->assertSee('field-binding-connection')
        ->call('submit')
        ->assertHasErrors()
        ->set("data.bindings.bindings.{$key}.destination", 'profile.name')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings', MappingState::binding(
            ['name' => $binding],
            [],
            array_keys(CustomerMappingField::jsonSchema()['properties']),
        ))
        ->callAction(TestAction::make('unbind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->assertSee('0 of 6 sources bound')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings', MappingState::binding(
            [],
            [],
            array_keys(CustomerMappingField::jsonSchema()['properties']),
        ));
})->with([false, true]);

it('validates deferred bindings and submits only bound forms', function (bool $complex) {
    $name = $complex ? ['destination' => 'profile.name', 'trim' => true] : 'profile.name';
    $email = $complex ? ['destination' => 'contact.email', 'trim' => true] : 'contact.email';

    $page = Livewire::test(FieldBindingForm::class, ['complex' => $complex]);
    $field = $page->instance()->form->getComponent('bindings');
    $nameKey = $field->getBindingKey('name');
    $emailKey = $field->getBindingKey('email');

    $page
        ->set("data.bindings.bindings.{$nameKey}.destination", 'profile.name')
        ->set("data.bindings.bound.{$nameKey}", true)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings', MappingState::binding(
            ['name' => $name],
            [],
            array_column($field->getSources(), 'id'),
        ))
        ->set("data.bindings.bound.{$emailKey}", true)
        ->call('submit')
        ->assertHasErrors()
        ->set("data.bindings.bindings.{$emailKey}.destination", 'contact.email')
        ->set("data.bindings.bound.{$nameKey}", false)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings', MappingState::binding(
            ['email' => $email],
            [],
            array_column($field->getSources(), 'id'),
        ));
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
        ->assertSet('saved.bindings', MappingState::binding(
            ['name' => $edited],
            [],
            array_keys(CustomerMappingField::jsonSchema()['properties']),
        ));
})->with([false, true]);

/** @param array<string, mixed> $state */
function field_binding_schema(FieldBinding $field, array $state = []): Schema
{
    $livewire = SchemaHost::make();

    $schema = Schema::make($livewire)->statePath('data')->components([$field]);
    $schema->fill(['bindings' => MappingState::binding($state, [], array_column($field->getSources(), 'id'))]);

    return $schema;
}

it('renders accessible local connection controls for every source', function (bool $disabled) {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(CustomerMappingField::jsonSchema())
        ->simple(TextInput::make('destination'))
        ->disabled($disabled);
    $schema = field_binding_schema($field, ['name' => 'profile.name']);
    view()->share('errors', new ViewErrorBag);
    $html = $schema->toHtml();

    expect(substr_count($html, 'class="field-binding-connection '))->toBe(6);
    expect(strip_tags($html))->not->toContain('Unbind');
    preg_match('/<button[^>]*aria-label="Unbind Customer name"[^>]*>/s', $html, $matches);
    expect($matches)->toHaveCount(1);
    expect((bool) preg_match('/\sdisabled(?:\s|=|>)/', $matches[0]))->toBe($disabled);
    expect($matches[0])->toContain('x-on:click="unbind(')->not->toContain('wire:click');
})->with([false, true]);

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
    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(['name' => 'profile.name'], [], array_column($field->getSources(), 'id')));
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

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(['name' => 'customer.name'], [], array_column($field->getSources(), 'id')));
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
        'bindings' => MappingState::binding(
            ['name' => ['destination' => 'CUSTOMER.NAME', 'trim' => true]],
            [],
            array_column($field->getSources(), 'id'),
        ),
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

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(
            [
                'name' => ['destination' => 'profile.name', 'trim' => false],
                'email' => ['destination' => 'profile.email', 'trim' => true],
            ],
            [],
            array_column($field->getSources(), 'id'),
        ));

    $field->getAction('unbind')(['source' => 'name'])->call();

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(
            [
                'email' => ['destination' => 'profile.email', 'trim' => true],
            ],
            [],
            array_column($field->getSources(), 'id'),
        ));
    expect($updates)->toBe(2);
});

it('binding an existing source preserves its configuration', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination')->default('default.name'));
    $schema = field_binding_schema($field, ['name' => 'profile.name']);

    $field->getAction('bind')(['source' => 'name'])->call();

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(['name' => 'profile.name'], [], array_column($field->getSources(), 'id')));
});

it('treats absent optional state as no bindings', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field);
    $field->rawState(null);

    expect($schema->getState())->toBe(['bindings' => MappingState::binding(
        [],
        [],
        array_column($field->getSources(), 'id'),
    )]);
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

it('does not submit drafts for sources that are no longer available', function () {
    $sources = ['name', 'email'];
    $field = FieldBinding::make('bindings')
        ->jsonSchema(function () use (&$sources) {
            return ['properties' => array_fill_keys($sources, [])];
        })
        ->simple(TextInput::make('destination')->required());
    $schema = field_binding_schema($field);
    $sources = ['email'];

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding([], [], array_column($field->getSources(), 'id')));
});

it('prepares defaults for newly available source forms without binding them', function () {
    $sources = ['name'];
    $field = FieldBinding::make('bindings')
        ->jsonSchema(function () use (&$sources) {
            return ['properties' => array_fill_keys($sources, [])];
        })
        ->simple(TextInput::make('destination')->default('profile.email')->required());
    $schema = field_binding_schema($field);
    $sources[] = 'email';
    view()->share('errors', new ViewErrorBag);
    $schema->toHtml();

    expect($field->getBindingSchema('email')->getComponent('destination')->getState())->toBe('profile.email');
    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding([], [], array_column($field->getSources(), 'id')));

    $field->getAction('bind')(['source' => 'email'])->call();

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(['email' => 'profile.email'], [], array_column($field->getSources(), 'id')));

    $field->getBindingSchema('email')->getComponent('destination')->state('edited.email');
    $field->getAction('unbind')(['source' => 'email'])->call();

    expect($field->getBindingSchema('email')->getComponent('destination')->getState())->toBe('profile.email');
    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding([], [], array_column($field->getSources(), 'id')));
});

it('binds simple fields with their supplied names and defaults', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(fn () => TextInput::make('routing.path')->default('profile.name')->required());
    $schema = field_binding_schema($field);

    $field->getAction('bind')(['source' => 'name'])->call();

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(['name' => 'profile.name'], [], array_column($field->getSources(), 'id')));
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

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding($state, [], array_column($field->getSources(), 'id')));
});

it('hydrates configured default bindings and nested field defaults', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->schema([
            TextInput::make('destination')->default('default.path')->required(),
            Toggle::make('trim')->default(true),
        ])
        ->default(MappingState::binding(['name' => ['destination' => 'profile.name', 'trim' => false]]));
    $schema = field_binding_schema($field);
    $schema->fill();

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(
            [
                'name' => ['destination' => 'profile.name', 'trim' => false],
            ],
            [],
            array_column($field->getSources(), 'id'),
        ));
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
            ['bindings' => MappingState::binding(
                ['name' => 'first.name'],
                [],
                array_column($field->getSources(), 'id'),
            )],
            ['bindings' => MappingState::binding(
                ['name' => 'second.name'],
                [],
                array_column($field->getSources(), 'id'),
            )],
        ],
    ]);

    expect($schema->getState()['bindings'])->toBe([
        ['bindings' => MappingState::binding(['name' => 'first.name'], [], array_column($field->getSources(), 'id'))],
        ['bindings' => MappingState::binding(['name' => 'second.name'], [], array_column($field->getSources(), 'id'))],
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

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(
            [
                'name' => ['destination' => 'PROFILE.NAME', 'suffix' => 'required'],
                'email' => ['destination' => 'profile.email', 'suffix' => null],
            ],
            [],
            array_column($field->getSources(), 'id'),
        ));

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

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding($state, [], array_column($field->getSources(), 'id')));
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
    $state = $field->getRawState();

    expect(fn () => $field->getAction('bind')(['source' => $source])->call())->toThrow(ValidationException::class);
    expect($field->getRawState())->toBe($state);
})->with([
    'unknown source' => ['unknown'],
    'null source' => [null],
    'numeric source' => [42],
    'array source' => [['name']],
]);

it('rejects malformed binding envelopes on submission', function (mixed $state) {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field);
    $field->rawState($state);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'scalar state' => ['invalid'],
    'unexpected state key' => [['unexpected' => []]],
]);

it('rejects forged binding values and unknown sources on submission', function (string $source, mixed $binding) {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['properties' => ['name' => []]])
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field, ['name' => 'profile.name']);
    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(['name' => 'profile.name'], [], array_column($field->getSources(), 'id')));

    $field->rawState([
        'sources' => [],
        'bindings' => [$field->getBindingKey($source) => $binding],
        'bound' => [$field->getBindingKey($source) => true],
    ]);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'unknown source' => ['unknown', ['destination' => 'profile.name']],
    'dotted unknown source' => ['name.destination', ['destination' => 'profile.name']],
    'scalar binding' => ['name', 'profile.name'],
    'nested simple value' => ['name', ['destination' => ['path' => 'profile.name']]],
    'empty simple value' => ['name', ['destination' => '']],
]);

it('rejects forged bound state on submission', function (string $source, mixed $bound, bool $removeBinding) {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['properties' => ['name' => []]])
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field);
    $state = $field->getRawState();
    $state['bound'] = [$field->getBindingKey($source) => $bound];
    if ($removeBinding) {
        $state['bindings'] = [];
    }
    $field->rawState($state);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'unknown source' => ['unknown', true, false],
    'non-boolean flag' => ['name', 'true', false],
    'missing binding' => ['name', true, true],
]);

it('preserves binding metadata and values across presentation options', function (
    bool $contained,
    bool $dense,
    bool $compact,
) {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(CustomerMappingField::jsonSchema(...))
        ->labels(source: fn () => 'Incoming', dest: 'Output')
        ->dense(fn () => $dense)
        ->compact(fn () => $compact)
        ->simple(TextInput::make('destination')->required());
    if (!$contained) {
        $field->contained(false);
    }
    $schema = field_binding_schema($field, ['name' => 'profile.name']);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();
    $text = preg_replace('/\s+/', ' ', strip_tags($html));
    expect($text)->toContain('Customer name', 'Incoming', 'Output');
    expect($text)->toContain('Required', 'Unique string');
    expect(str_contains($text, 'The name displayed on the customer profile.'))->toBe(!$compact);
    expect($html)->toContain('The name displayed on the customer profile.');
    expect(str_contains($html, 'data-mapping-description'))->toBe(!$compact);
    expect(str_contains($html, 'x-tooltip="{ content:'))->toBe($compact);
    expect($html)->toContain($dense ? 'min-h-14' : 'min-h-20');
    expect($html)->not->toContain($dense ? 'min-h-20' : 'min-h-14');
    expect(str_contains($text, 'Show unbound'))->toBe($contained);
    expect(str_contains($text, '1 of 6 sources bound'))->toBe($contained);
    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(['name' => 'profile.name'], [], array_column($field->getSources(), 'id')));
})->with([true, false])->with([true, false])->with([true, false]);

it('keeps the binding toolbar around the empty state', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys([], [])])
        ->searchable()
        ->labels(source: 'Incoming fields', dest: 'Output fields')
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())
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
            TextInput::make('target')->required(),
            TextInput::make('static')->required(),
        ]);
}

it('round trips static values beside bindings without treating them as sources', function () {
    $field = field_binding_constants();
    $state = ['name' => 'profile.name', 'status' => ['value' => 'active']];
    $schema = field_binding_schema($field, $state);

    expect($field->getBindingValues())
        ->toBe(MappingState::binding($state, [], ['name', 'email']))
        ->and($field->getSources())
        ->toHaveCount(2)
        ->and($field->getConstants())
        ->toHaveCount(1)
        ->and($schema->getState()['bindings'])
        ->toBe(MappingState::binding($state, [], array_column($field->getSources(), 'id')));
});

it('adds, edits and removes a static value', function () {
    $updates = 0;
    $field = field_binding_constants()->afterStateUpdated(function () use (&$updates) {
        $updates++;
    });
    $schema = field_binding_schema($field, ['name' => 'profile.name']);

    $field->getAction('addConstant')([])->call();

    $key = array_key_first($field->getConstants());
    $field->getConstants()[$key]->getComponent('target')->state('status');
    $field->getConstants()[$key]->getComponent('static')->state('active');

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(
            ['name' => 'profile.name', 'status' => ['value' => 'active']],
            [],
            array_column($field->getSources(), 'id'),
        ));

    $field->getAction('removeConstant')(['constant' => $key])->call();

    expect($schema->getState()['bindings'])
        ->toBe(MappingState::binding(['name' => 'profile.name'], [], array_column($field->getSources(), 'id')))
        ->and($updates)
        ->toBe(2);
});

it('validates every static value field', function () {
    $field = field_binding_constants();
    $schema = field_binding_schema($field, ['status' => ['value' => 'active']]);
    $field->getConstants()[array_key_first($field->getConstants())]->getComponent('static')->state('');

    expect($schema->getState(...))->toThrow(ValidationException::class);
});

it('rejects static value keys that would overwrite bindings or other static values', function (string $column) {
    $field = field_binding_constants();
    $schema = field_binding_schema($field, ['name' => 'profile.name', 'status' => ['value' => 'active']]);
    $field->getAction('addConstant')([])->call();
    $constant = $field->getConstants()[array_key_last($field->getConstants())];
    $constant->getComponent('target')->state($column);
    $constant->getComponent('static')->state('replacement');
    $state = $field->getRawState();

    expect($schema->getState(...))->toThrow(ValidationException::class);
    expect($field->getRawState())->toBe($state);
})->with(['status']);

it('rejects static values unless the component enables them', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name'], [])])
        ->simple(TextInput::make('destination'));
    $schema = field_binding_schema($field);
    $withConstants = field_binding_constants();
    field_binding_schema($withConstants, ['status' => ['value' => 'active']]);
    $field->rawState($withConstants->getRawState());

    expect($schema->getState(...))->toThrow(ValidationException::class);
});

it('rejects forged static value actions and disabled changes', function () {
    $field = field_binding_constants();
    field_binding_schema($field, ['status' => ['value' => 'active']]);
    $state = $field->getRawState();
    $constant = array_key_first($field->getConstants());

    expect(fn () => $field->getAction('removeConstant')(['constant' => $field->getBindingKey('name')])->call())
        ->toThrow(
            ValidationException::class,
        );

    $field->disabled();
    $field->getAction('addConstant')([])->call();
    $field->getAction('removeConstant')(['constant' => $constant])->call();

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

it('renders native source actions on bound and unbound sources', function () {
    $field = FieldBinding::make('bindings')
        ->jsonSchema(['type' => 'object', 'properties' => array_fill_keys(['name', 'email'], [])])
        ->simple(TextInput::make('destination'))
        ->sourceSettings(fn (string $source) => [
            Actions::make([Action::make('inspect')->label('Inspect ' . $source)]),
        ]);
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
    $field = $page->instance()->form->getComponent('bindings');
    $settingsKey = $field->getSourceStateKeys()['name'];
    $bindingKey = $field->getBindingKey('name');
    $page
        ->assertSet("data.bindings.sources.{$settingsKey}.alias", 'name')
        ->set("data.bindings.sources.{$settingsKey}.alias", 'Customer')
        ->set("data.bindings.sources.{$settingsKey}.enabled", false)
        ->callAction(TestAction::make('bind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->set("data.bindings.bindings.{$bindingKey}.destination", 'profile.name')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings.0.alias', 'Customer')
        ->assertSet('saved.bindings.0.enabled', false)
        ->callAction(TestAction::make('unbind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('saved.bindings.0.target', null)
        ->assertSet('saved.bindings.0.alias', 'Customer')
        ->assertSet('saved.bindings.0.enabled', false)
        ->assertSet("data.bindings.sources.{$settingsKey}.alias", 'Customer')
        ->set("data.bindings.sources.{$settingsKey}.alias", '')
        ->call('submit')
        ->assertHasErrors(["data.bindings.sources.{$settingsKey}.alias" => 'required'])
        ->callAction(TestAction::make('bind')->schemaComponent('bindings', 'form')->arguments(['source' => 'name']))
        ->set("data.bindings.bindings.{$bindingKey}.destination", 'profile.name')
        ->call('submit')
        ->assertHasErrors(["data.bindings.sources.{$settingsKey}.alias" => 'required']);
})->with([false, true]);

it('keeps a static target that shares a source identifier as a separate row', function () {
    $field = field_binding_constants();
    $schema = field_binding_schema($field, ['name' => 'profile.name']);
    $field->getAction('addConstant')([])->call();
    $constant = $field->getConstants()[array_key_last($field->getConstants())];
    $constant->getComponent('target')->state('name');
    $constant->getComponent('static')->state('fixed');

    expect($schema->getState()['bindings'])->toBe([
        ['kind' => 'source', 'source' => 'name', 'target' => 'profile.name'],
        ['kind' => 'source', 'source' => 'email', 'target' => null],
        ['kind' => 'static', 'target' => 'name', 'static' => 'fixed'],
    ]);
});
