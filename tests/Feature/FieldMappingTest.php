<?php

use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\FerryServiceProvider;
use PHPinnacle\Ferry\Forms\FieldBinding;
use PHPinnacle\Ferry\Forms\FieldMapping;
use PHPinnacle\Ferry\Forms\FieldTransforms;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Trim;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/MappingState.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

/**
 * @param array<array-key, mixed> $mapping
 * @param list<string> $targets
 */
function static_mapping_schema(array $mapping, array $targets = []): Schema
{
    $livewire = SchemaHost::make();
    $field = FieldMapping::make('mapping')
        ->jsonSchema(source: ['properties' => ['source' => ['type' => 'string']]], dest: [
            'properties' => array_intersect_key(
                [
                    'name.part' => ['type' => ['string', 'null']],
                    'enabled' => ['type' => 'boolean'],
                    'count' => ['type' => 'number'],
                    '0' => ['type' => 'string'],
                    'raw' => ['type' => 'integer'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                array_fill_keys([...array_keys($mapping), ...$targets], true),
            ),
        ])
        ->staticForms([
            'string' => fn (string $target) => TextInput::make('value')
                ->label($target)
                ->required()
                ->maxLength(5)
                ->dehydrateStateUsing(fn (string $state) => strtoupper($state)),
            'boolean' => fn () => Toggle::make('value')->default(false),
            'number' => fn () => TextInput::make('value')->numeric()->required()->minValue(0),
            'array' => fn () => TagsInput::make('value')->required()->nestedRecursiveRules(['string']),
        ])
        ->dehydrateStateUsing(fn (array $state) => $state);

    $schema = Schema::make($livewire)->statePath('data')->components([$field]);
    $schema->fill(['mapping' => MappingState::mapping($mapping)]);

    return $schema;
}

it('offers registered static editors for every destination and validates their defaults', function () {
    $schema = static_mapping_schema([], ['name.part', 'enabled', 'raw']);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())
        ->toContain('data-mapping-static-form="name.part"')
        ->toContain('data-mapping-static-form="enabled"')
        ->not->toContain('data-mapping-static-form="raw"');
    expect($schema->getState(...))->toThrow(ValidationException::class);

    $schema
        ->getComponent('mapping')
        ->staticForms([
            'string' => fn () => TextInput::make('value')->nullable(),
            'boolean' => fn () => Toggle::make('value')->default(false),
        ]);
    $schema->fill(['mapping' => MappingState::mapping([])]);
    expect($schema->getState()['mapping'])->toEqualCanonicalizing([
        'name.part' => MappingState::value(null),
        'enabled' => MappingState::value(false),
    ]);

    $schema->fill(['mapping' => MappingState::mapping(['name.part' => MappingState::source('source')])]);
    expect($schema->getState()['mapping'])->toEqualCanonicalizing([
        'name.part' => MappingState::source('source'),
        'enabled' => MappingState::value(false),
    ]);
});

it('renders inline static forms and round trips typed values and literal identifiers', function () {
    $schema = static_mapping_schema([
        'name.part' => MappingState::value('hello'),
        'enabled' => MappingState::value(false),
        'count' => MappingState::value(0),
        '0' => MappingState::value('007'),
        'raw' => MappingState::value('009'),
    ]);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())->toContain('role="switch"');
    expect($schema->getState()['mapping'])->toBe([
        'name.part' => MappingState::value('HELLO'),
        'enabled' => MappingState::value(false),
        'count' => MappingState::value(0.0),
        '0' => MappingState::value('007'),
        'raw' => MappingState::value('009'),
    ]);
});

it('supports array static editors alongside collection connections', function (array $mapping) {
    expect(static_mapping_schema($mapping)->getState()['mapping'])->toBe($mapping);
})->with([
    'static array' => [['tags' => MappingState::value(['one', 'two'])]],
    'collected source' => [['tags' => [MappingState::source('source')]]],
]);

it('validates static values through their registered Filament fields', function (array $mapping) {
    $schema = static_mapping_schema($mapping);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'required text' => [['name.part' => MappingState::value('')]],
    'long text' => [['name.part' => MappingState::value('longer than five')]],
    'negative number' => [['count' => MappingState::value(-1)]],
    'empty static value without an editor' => [['raw' => MappingState::value('')]],
    'extra static keys' => [['enabled' => [...MappingState::value(true), 'extra' => true]]],
]);

it('only renders mapping search inputs when enabled, including closure configuration', function () {
    $livewire = SchemaHost::make();

    $field = FieldMapping::make('mapping')->jsonSchema(source: ['properties' => ['title' => []]], dest: [
        'properties' => ['name' => []],
    ]);
    $schema = Schema::make($livewire)->statePath('data')->components([$field]);
    $schema->fill(['mapping' => MappingState::mapping(['name' => MappingState::source('title')])]);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())->not->toContain('type="search"');
    $field->searchable();
    expect(substr_count($schema->toHtml(), 'type="search"'))->toBe(2);
    $field->searchable(false);
    expect($schema->toHtml())->not->toContain('type="search"');

    $enabled = true;
    $field->searchable(function () use (&$enabled) {
        return $enabled;
    });
    expect(substr_count($schema->toHtml(), 'type="search"'))->toBe(2);
    $enabled = false;
    expect($schema->toHtml())->not->toContain('type="search"');
    expect($schema->getState()['mapping'])->toBe(['name' => MappingState::source('title')]);
});

it('preserves standalone mapping content and values across presentation options', function (
    bool $contained,
    bool $dense,
    bool $compact,
) {
    $livewire = SchemaHost::make();

    $field = FieldMapping::make('mapping')
        ->jsonSchema(source: [
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'The incoming display name.',
                    'x-badges' => [['label' => 'Imported', 'color' => 'gray']],
                ],
            ],
        ], dest: [
            'required' => ['name'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The destination display name.',
                    'x-icon' => 'heroicon-o-user',
                    'x-badges' => [['label' => 'Unique', 'color' => 'gray']],
                ],
            ],
        ])
        ->dense(fn () => $dense)
        ->compact(fn () => $compact)
        ->columnSpanFull();
    if (!$contained) {
        $field->contained(false);
    }

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([$field]);
    $schema->fill(['mapping' => MappingState::mapping(['name' => MappingState::source('title')])]);

    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();

    $text = preg_replace('/\s+/', ' ', strip_tags($html));
    expect($text)->toContain('Required', 'Unique string', 'Imported string');
    expect(str_contains($text, 'The incoming display name.'))->toBe(!$compact);
    expect(str_contains($text, 'The destination display name.'))->toBe(!$compact);
    expect($html)->toContain('The incoming display name.', 'The destination display name.');
    expect(str_contains($html, 'data-mapping-description'))->toBe(!$compact);
    expect(str_contains($html, 'x-tooltip="{ content:'))->toBe($compact);
    expect($html)->toContain($dense ? 'min-h-14' : 'min-h-20');
    expect($html)->not->toContain($dense ? 'min-h-20' : 'min-h-14');
    expect(str_contains($text, 'Show unmapped'))->toBe($contained);
    expect($html)->toContain('aria-live="polite"');
    expect($html)->toContain(FilamentAsset::getAlpineComponentSrc('field-mapping', FerryServiceProvider::PACKAGE));
    expect($schema->getState()['mapping'])->toBe(['name' => MappingState::source('title')]);
})->with([true, false])->with([true, false])->with([true, false]);

it('keeps the mapping toolbar when either side has no fields', function (
    array $sources,
    array $targets,
) {
    $livewire = SchemaHost::make();

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([
            FieldMapping::make('mapping')
                ->jsonSchema(source: ['properties' => array_fill_keys($sources, [])], dest: ['properties' => array_fill_keys($targets, [])])
                ->searchable()
                ->warnings(dest: ['name' => 'Review the destination.'])
                ->labels(source: 'Incoming fields', dest: 'Output fields'),
        ]);
    $schema->fill(['mapping' => MappingState::mapping([])]);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())
        ->toContain('No fields available.')
        ->not->toContain('Incoming fields')
        ->not->toContain('Output fields')
        ->not->toContain('type="search"')->toContain('Show unmapped')->toContain('Review the destination.');
})->with([
    'no source fields' => [[], ['name']],
    'no target fields' => [['title'], []],
    'no fields' => [[], []],
]);

it('highlights warnings on either side and preserves missing identifiers in both orientations', function (bool $inverse) {
    $livewire = SchemaHost::make();

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([
            FieldMapping::make('mapping')
                ->jsonSchema(source: ['properties' => ['title' => [], 'email' => []]], dest: [
                    'properties' => ['name' => [], 'contact' => []],
                ])
                ->warnings(
                    source: fn () => ['title' => 'Source <type> changed.', '42' => 'Source disappeared.'],
                    dest: ['name' => 'Destination changed.', '42' => 'Destination disappeared.'],
                )
                ->inverse($inverse),
        ]);
    $schema->fill(['mapping' => MappingState::mapping(['name' => MappingState::source('title')])]);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();

    expect($html)
        ->toContain('Source &lt;type&gt; changed.')
        ->toContain('Destination changed.')
        ->toContain('42: Source disappeared.')
        ->toContain('42: Destination disappeared.');
})->with([false, true]);

it('renders native actions on every source before mapping in both orientations', function (bool $inverse) {
    $livewire = SchemaHost::make();

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([
            FieldMapping::make('mapping')
                ->jsonSchema(source: ['properties' => ['title' => [], 'email' => []]], dest: [
                    'properties' => ['name' => [], 'contact' => []],
                ])
                ->sourceSettings(fn (string $source) => [
                    Actions::make([
                        Action::make('inspect')->label('Inspect ' . $source),
                        Action::make('hidden')
                            ->hidden()
                            ->label(
                                fn () => throw new LogicException('Hidden actions must not evaluate presentation.'),
                            ),
                    ]),
                ])
                ->inverse($inverse),
        ]);
    $schema->fill(['mapping' => MappingState::mapping([])]);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();

    expect($html)
        ->toContain('Inspect title')
        ->toContain('Inspect email');
})->with([false, true]);

it('does not render static editors without a registered type factory', function (bool $inverse) {
    $livewire = SchemaHost::make();

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([
            FieldMapping::make('mapping')
                ->jsonSchema(source: ['properties' => ['title' => []]], dest: [
                    'properties' => ['name' => [], 'contact' => []],
                ])
                ->inverse($inverse),
        ]);
    $schema->fill(['mapping' => MappingState::mapping([])]);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();

    expect($html)
        ->not->toContain('data-mapping-static-form');
})->with([false, true]);

it('renders schema names, formats and separate disclosure controls for scoped details', function () {
    $field = FieldMapping::make('mapping')->jsonSchema(source: [
        'properties' => [
            'tag_codes' => [
                'title' => 'Tags',
                'type' => 'array',
                'maxItems' => 10,
                'uniqueItems' => true,
                'items' => ['type' => 'string', 'maxLength' => 16],
                'x-icon' => 'heroicon-o-tag',
                'x-color' => 'violet',
            ],
        ],
    ], dest: [
        'properties' => [
            'id' => ['title' => 'Identifier', 'type' => 'string', 'format' => 'uuid'],
            'status' => ['type' => 'string', 'enum' => ['active', '<draft>']],
        ],
    ]);
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill(['mapping' => MappingState::mapping([])]);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();
    expect($html)
        ->toContain(
            'aria-label="Items: ≤ 10"',
            'aria-label="Items: Length: ≤ 16"',
            'data-mapping-constraint="unique"',
            '&lt;draft&gt;',
            'var(--violet-600)',
            'Show item types for Tags',
            'data-mapping-details',
            ':aria-controls=',
        )
        ->and(strip_tags($html))
        ->toContain('Tags', 'tag_codes', 'string[]', 'uuid', 'enum')
        ->and($html)
        ->not->toContain('Show item types for status');
});

it('shows output metadata without retaining the input format or limits', function () {
    $field = FieldMapping::make('mapping')
        ->jsonSchema(source: [
            'properties' => ['id' => [
                'title' => 'Identifier',
                'type' => 'string',
                'format' => 'uuid',
                'maxLength' => 36,
            ]],
        ], dest: ['properties' => ['enabled' => ['type' => 'boolean']]])
        ->outputSchema(fn () => ['properties' => ['id' => ['type' => 'boolean']]]);
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill(['mapping' => MappingState::mapping([])]);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();
    expect(strip_tags($html))
        ->toContain('boolean')
        ->not->toContain('uuid')->and($html)
        ->not->toContain('data-mapping-constraint="length"', 'data-mapping-details-toggle');
});

it('shows only the final type and uses it for connection checks', function () {
    $livewire = SchemaHost::make();

    $field = FieldMapping::make('mapping')
        ->jsonSchema(source: ['properties' => ['active' => ['type' => 'boolean']]], dest: [
            'properties' => ['name' => ['type' => 'string']],
        ])
        ->outputSchema(fn () => ['properties' => ['active' => ['type' => 'string']]]);
    $schema = Schema::make($livewire)->statePath('data')->components([$field]);
    $schema->fill(['mapping' => MappingState::mapping(['name' => MappingState::source('active')])]);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();

    expect(strip_tags($html))->toContain('string')->not->toContain('boolean', '→');
    expect($field->getSources()[0]['type'])->toBe('boolean');
    expect($schema->getState()['mapping'])->toBe(['name' => MappingState::source('active')]);
});

it('submits source settings only for connections and ignores unused invalid drafts', function (bool $inverse) {
    $field = FieldMapping::make('mapping')
        ->jsonSchema(source: [
            'properties' => ['first.part' => ['type' => 'string'], '0' => ['type' => 'string']],
        ], dest: ['properties' => ['name' => ['type' => 'string']]])
        ->sourceSettings(fn (string $source) => [
            TextInput::make('alias')->default($source)->required(),
            Toggle::make('enabled')->default(true),
        ])
        ->inverse($inverse);
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $settings = [
        'first.part' => ['alias' => 'First', 'enabled' => false],
        '0' => ['alias' => 'Zero', 'enabled' => true],
    ];
    foreach ([
        ['name' => MappingState::source('first.part')],
        ['name' => MappingState::value('static')],
        [],
    ] as $targets) {
        $schema->fill(['mapping' => MappingState::mapping($targets, $settings)]);
        if (isset($targets['name']) && $targets['name']['kind'] === 'source') {
            expect($field->getSourceSettings()['first.part'])->toBe($settings['first.part']);
        }
        expect($schema->getState()['mapping'])->toBe(MappingState::mapping($targets, $settings));
    }
    $schema->fill(['mapping' => MappingState::mapping([])]);
    expect($field->getSourceSettings())->toBe([
        'first.part' => ['alias' => 'first.part', 'enabled' => true],
        '0' => ['alias' => '0', 'enabled' => true],
    ]);
    $key = $field->getSourceStateKeys()['first.part'];
    $field->getChildSchemas()['settings_' . $key]->getComponent('alias')->state('');
    expect($schema->getState()['mapping'])->toBe([]);
    $schema->fill(['mapping' => MappingState::mapping(['name' => MappingState::source('first.part')], ['first.part' => [
        'alias' => '',
        'enabled' => false,
    ]])]);
    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([false, true]);

it('submits native nested source settings after child dehydration', function (bool $binding) {
    $sourceSchema = ['properties' => ['first.part' => ['type' => 'string'], '0' => ['type' => 'string']]];
    $field = $binding
        ? FieldBinding::make('widget')->jsonSchema($sourceSchema)->simple(TextInput::make('column'))
        : FieldMapping::make('widget')->jsonSchema(source: $sourceSchema, dest: [
            'properties' => ['first' => [], 'second' => []],
        ]);
    $field->sourceSettings([
        Repeater::make('rules')->schema([TextInput::make('value')->dehydrateStateUsing(
            fn (string $state) => strtoupper($state),
        )]),
        Builder::make('blocks')->blocks([Block::make('replace')->schema([TextInput::make('value')])]),
    ]);
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $settings = [
        'first.part' => [
            'rules' => [['value' => 'first']],
            'blocks' => [['type' => 'replace', 'data' => ['value' => 'One']]],
        ],
        '0' => ['rules' => [['value' => 'second']], 'blocks' => [['type' => 'replace', 'data' => ['value' => 'Two']]]],
    ];
    $entries = $binding
        ? MappingState::binding(['first.part' => 'first', '0' => 'second'], $settings)
        : MappingState::mapping([
            'first' => MappingState::source('first.part'),
            'second' => MappingState::source('0'),
        ], $settings);
    $schema->fill(['widget' => $entries]);
    $settings['first.part']['rules'][0]['value'] = 'FIRST';
    $settings['0']['rules'][0]['value'] = 'SECOND';
    $expected = $binding
        ? MappingState::binding(['first.part' => 'first', '0' => 'second'], $settings)
        : MappingState::mapping([
            'first' => MappingState::source('first.part'),
            'second' => MappingState::source('0'),
        ], $settings);
    expect($schema->getState()['widget'])->toBe($expected);
})->with([false, true]);

it('shows settings for newly available sources without requiring a connection or binding', function (bool $binding) {
    $sources = ['name'];
    $sourceSchema = function () use (&$sources) {
        return ['properties' => array_fill_keys($sources, ['type' => 'string'])];
    };
    $field = $binding
        ? FieldBinding::make('widget')->jsonSchema($sourceSchema)->simple(TextInput::make('column'))
        : FieldMapping::make('widget')->jsonSchema(source: $sourceSchema, dest: [
            'properties' => ['output' => ['type' => 'string']],
        ]);
    $field->sourceSettings(fn (string $source) => [TextInput::make('alias')->label('Alias for ' . $source)]);
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill(['widget' => []]);
    view()->share('errors', new ViewErrorBag);
    expect($schema->toHtml())->toContain('Alias for name')->not->toContain('Alias for email');
    $sources[] = 'email';
    expect($schema->toHtml())->toContain('Alias for name', 'Alias for email');
})->with([false, true]);

it('ignores invalid unused transform drafts and validates them once selected', function (
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
    $settingsKey = $field->getSourceStateKeys()['input'];
    $field->getChildSchemas()['settings_' . $settingsKey]->getComponent('transforms')->state($transforms);

    expect($schema->getState()['widget'])->toBe([]);

    if ($binding) {
        $field->getAction('bind')(['source' => 'input'])->call();
    } else {
        $state = $field->getRawState();
        $state['targets'][$field->getStateKeys()['output']] = MappingState::source('input');
        $field->rawState($state);
    }

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([false, true])->with([
    'incompatible transform' => ['boolean', [['key' => 'trim', 'params' => []]]],
    'missing parameters' => ['string', [['key' => 'mask', 'params' => []]]],
]);

it('shows the technical display name separately while retaining the source identifier', function (bool $binding) {
    $sourceId = '63e1c289-8a21-4ac1-bfb0-353d38184bfd';
    $sourceSchema = ['properties' => [$sourceId => ['type' => 'string', 'title' => 'Email', 'x-name' => '_fld11']]];
    $field = $binding
        ? FieldBinding::make('widget')
            ->jsonSchema($sourceSchema)
            ->simple(TextInput::make('destination')->default('contact.email'))
        : FieldMapping::make('widget')->jsonSchema(source: $sourceSchema, dest: [
            'properties' => ['email' => ['type' => 'string']],
        ]);
    $field->searchable();
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $state = $binding
        ? MappingState::binding([$sourceId => 'contact.email'])
        : MappingState::mapping(['email' => MappingState::source($sourceId)]);
    $schema->fill(['widget' => $state]);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();
    preg_match('/<span class="ferry-mapping-property">([^<]+)<\/span>/', $html, $technicalName);
    expect($technicalName[1])->toBe('_fld11');
    expect($field->getSources()[0]['id'])->toBe($sourceId);
    expect($field->getSources()[0]['label'])->toBe('Email');
    expect($schema->getState()['widget'])->toBe($state);
})->with([false, true]);
