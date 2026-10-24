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
use Livewire\Livewire;
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
require_once __DIR__ . '/../Fixtures/FieldMappingForm.php';

uses(TestCase::class);

it('partially updates every changed presentation and fully renders structural schema changes', function (bool $dynamicTarget) {
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\FieldMappingForm::class, [
        'dynamicTarget' => $dynamicTarget,
    ]);
    $keys = $page->instance()->form->getComponent('mapping')->getSourceStateKeys();
    $page->set('data.mapping.sources.' . $keys['first'] . '.type', 'boolean');
    expect($page->effects)->not->toHaveKey('html');
    if ($dynamicTarget) {
        expect($page->effects['partials'])->toHaveKey('schema-component::form.mapping');
    } else {
        expect($page->effects['partials'])->not->toHaveKey('schema-component::form.mapping');
        foreach ($keys as $key) {
            expect($page->effects['partials']['schema-component::form.mapping.heading_' . $key])
                ->toContain('>boolean</span>');
        }
    }
    expect($page->instance()->form->getComponent('mapping')->getConnectionModes())->toBe([
        'first' => ['destination' => 'whole'],
        'second' => ['destination' => 'whole'],
    ]);
})->with([false, true]);

it('refreshes source cards when a reactive schema changes its types and annotations', function (string $component) {
    $definition = ['properties' => ['code' => ['title' => 'Code', 'type' => 'string', 'maxLength' => 10]]];
    $source = function () use (&$definition) {
        return $definition;
    };
    $field = $component === FieldBinding::class
        ? FieldBinding::make('mapping')->jsonSchema($source)->simple(TextInput::make('target'))
        : FieldMapping::make('mapping')->jsonSchema(source: $source, dest: []);
    Schema::make(SchemaHost::make())->statePath('data')->components([$field])->fill(['mapping' => []]);
    expect($field->getSources()[0]['presentation']['type'])->toBe('string');
    $definition = [
        'properties' => ['code' => ['title' => 'Quantity', 'type' => 'integer', 'minimum' => 0]],
        'required' => ['code'],
    ];
    $card = $field->getSources()[0];
    expect($card['label'])
        ->toBe('Quantity')
        ->and($card['required'])
        ->toBeTrue()
        ->and($card['presentation']['type'])
        ->toBe('integer')
        ->and($card['presentation']['constraints'])
        ->toBe([['kind' => 'range', 'value' => '≥ 0', 'scope' => []]]);
})->with(['binding' => [FieldBinding::class], 'mapping' => [FieldMapping::class]]);

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
    expect($schema->getState()['mapping'])
        ->toEqualCanonicalizing(MappingState::mapping(
            [
                'name.part' => MappingState::value(null),
                'enabled' => MappingState::value(false),
            ],
            [],
            array_column($schema->getComponent('mapping')->getSources(), 'id'),
        ));

    $schema->fill(['mapping' => MappingState::mapping(['name.part' => MappingState::source('source')])]);
    expect($schema->getState()['mapping'])
        ->toEqualCanonicalizing(MappingState::mapping(
            [
                'name.part' => MappingState::source('source'),
                'enabled' => MappingState::value(false),
            ],
            [],
            array_column($schema->getComponent('mapping')->getSources(), 'id'),
        ));
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
    expect($schema->getState()['mapping'])
        ->toBe(MappingState::mapping(
            [
                'name.part' => MappingState::value('HELLO'),
                'enabled' => MappingState::value(false),
                'count' => MappingState::value(0.0),
                '0' => MappingState::value('007'),
                'raw' => MappingState::value('009'),
            ],
            [],
            array_column($schema->getComponent('mapping')->getSources(), 'id'),
        ));
});

it('supports array static editors alongside collection connections', function (array $mapping) {
    $schema = static_mapping_schema($mapping);
    expect($schema->getState()['mapping'])->toBe(MappingState::mapping($mapping, [], ['source']));
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
    expect($schema->getState()['mapping'])
        ->toBe(MappingState::mapping(
            ['name' => MappingState::source('title')],
            [],
            array_column($schema->getComponent('mapping')->getSources(), 'id'),
        ));
});

it('applies column spans to their source and target sides without changing mappings', function (bool $inverse) {
    $field = FieldMapping::make('mapping')
        ->jsonSchema(source: ['properties' => ['title' => ['type' => 'string']]], dest: [
            'properties' => ['name' => ['type' => 'string']],
        ])
        ->inverse($inverse);
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill(['mapping' => MappingState::mapping(['name' => MappingState::source('title')])]);
    view()->share('errors', new ViewErrorBag);
    $state = $schema->getState();

    expect($field->getColumnSpans())->toBe(['source' => 5, 'target' => 5, 'gap' => 2]);
    expect($schema->toHtml())
        ->toContain('--ferry-left-column: 5fr', '--ferry-gap-column: 2fr', '--ferry-right-column: 5fr');
    $field->columnSpans(source: 4, target: 7, gap: 1);
    expect($schema->toHtml())
        ->toContain(
            '--ferry-left-column: ' . ($inverse ? 7 : 4) . 'fr',
            '--ferry-gap-column: 1fr',
            '--ferry-right-column: ' . ($inverse ? 4 : 7) . 'fr',
        );
    expect($schema->getState())->toBe($state);
})->with([false, true]);

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
    expect(str_contains($text, 'Field visibility'))->toBe($contained);
    expect(substr_count($html, 'type="radio"'))->toBe($contained ? 3 : 0);
    if ($contained) {
        expect($text)->toContain('All', 'Mapped', 'Unmapped');
    }
    expect($html)->toContain('aria-live="polite"');
    expect($html)->toContain(FilamentAsset::getAlpineComponentSrc('field-mapping', FerryServiceProvider::PACKAGE));
    expect($schema->getState()['mapping'])
        ->toBe(MappingState::mapping(
            ['name' => MappingState::source('title')],
            [],
            array_column($schema->getComponent('mapping')->getSources(), 'id'),
        ));
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
        ->not->toContain('type="search"')->toContain('Field visibility')->toContain('Review the destination.');
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
    expect($schema->getState()['mapping'])
        ->toBe(MappingState::mapping(
            ['name' => MappingState::source('active')],
            [],
            array_column($schema->getComponent('mapping')->getSources(), 'id'),
        ));
});

it('persists and validates source settings independently of connections', function (bool $inverse) {
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
        if (array_key_exists('name', $targets) && $targets['name']['kind'] === 'source') {
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
    $schema->getComponentByStatePath('mapping.sources.' . $key . '.alias')->state('');
    expect($schema->getState(...))->toThrow(ValidationException::class);
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
    $settingsKey = $field->getSourceStateKeys()['input'];
    $schema->getComponentByStatePath('widget.sources.' . $settingsKey . '.transforms')->state($transforms);

    expect($schema->getState(...))->toThrow(ValidationException::class);

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

it('shows the property key separately from its title and preserves mapping state', function (bool $binding) {
    $sourceId = '63e1c289-8a21-4ac1-bfb0-353d38184bfd';
    $sourceSchema = ['properties' => [$sourceId => ['type' => 'string', 'title' => 'Email']]];
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
    expect($technicalName[1])->toBe($sourceId);
    expect($field->getSources()[0]['id'])->toBe($sourceId);
    expect($field->getSources()[0]['label'])->toBe('Email');
    expect($schema->getState()['widget'])->toBe($state);
})->with([false, true]);

/** @param list<array<string, mixed>> $rows */
function static_source_mapping_schema(array $rows = [], bool $disabled = false): Schema
{
    $field = FieldMapping::make('mapping')
        ->staticValues()
        ->disabled($disabled)
        ->jsonSchema(source: ['properties' => ['static_1' => ['type' => 'string']]], dest: [
            'properties' => [
                'name.part' => ['type' => 'string'],
                'count' => ['type' => 'integer'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ])
        ->sourceSettings(fn (?Closure $inputSchema) => [
            TextInput::make('alias')->default('default'),
            FieldTransforms::make('transforms')->inputSchema(
                $inputSchema === null ? ['type' => 'string'] : fn () => $inputSchema()->toArray(),
            ),
        ])
        ->outputSchema(function (FieldMapping $component, TransformRegistry $registry) {
            $schema = $component->getSourceSchema();
            foreach ($component->getSourceSettings() as $source => $settings) {
                $schema['properties'][$source] = $registry
                    ->resultSchema(
                        \PHPinnacle\Ferry\Forms\JsonSchema::fromArray($schema['properties'][$source]),
                        $settings['transforms'] ?? [],
                    )
                    ?->toArray() ?? [];
            }

            return $schema;
        });
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components([$field]);
    $schema->fill(['mapping' => $rows]);

    return $schema;
}

it('adds unconnected static source drafts and connects them without changing the public row format', function () {
    $schema = static_source_mapping_schema();
    $field = $schema->getComponent('mapping');
    $field->getAction('addConstant')->call();
    $field->getAction('addConstant')->call();
    expect(array_keys($field->getConstants()))->toBe(['static_2', 'static_3']);
    expect($schema->getState()['mapping'])->toBe([
        ['kind' => 'source', 'source' => 'static_1', 'target' => null, 'alias' => 'default', 'transforms' => []],
    ]);
    view()->share('errors', new ViewErrorBag);
    expect($schema->toHtml())->toContain('Static 2', 'data-mapping-static-source="static_2"');

    $field->getConstantValueSchema('static_2')->getComponent('static')->state('Customer');
    $state = $field->getRawState();
    $state['targets'][$field->getStateKeys()['name.part']] = MappingState::source('static_2');
    $field->rawState($state);
    expect($schema->getState()['mapping'])->toContain([
        'kind' => 'static',
        'target' => 'name.part',
        'static' => 'Customer',
        'alias' => 'default',
        'transforms' => [],
    ]);

    $field->getAction('removeConstant')(['constant' => 'static_2'])->call();
    expect(array_keys($field->getConstants()))->toBe(['static_2', 'static_3']);
    $state = $field->getRawState();
    unset($state['targets'][$field->getStateKeys()['name.part']]);
    $field->rawState($state);
    $field->getAction('removeConstant')(['constant' => 'static_2'])->call();
    expect(array_keys($field->getConstants()))->toBe(['static_3']);
    expect($field->isSourceUsed('static_2'))->toBeFalse();
    expect($schema->getState()['mapping'])->toHaveCount(1);
});

it('round trips typed static sources and their settings', function (mixed $value, string $target) {
    $rows = [['kind' => 'static', 'target' => $target, 'static' => $value, 'alias' => 'saved', 'transforms' => []]];
    $schema = static_source_mapping_schema($rows);
    expect($schema->getState()['mapping'][0])->toBe($rows[0]);
})->with([
    'string' => ['Customer', 'name.part'],
    'integer' => [0, 'count'],
    'array item' => ['priority', 'tags'],
]);

it('checks static source compatibility after transforms and validates connected values', function () {
    app(TransformRegistry::class)->register(\PHPinnacle\Ferry\Transformations\Cast::make());
    $schema = static_source_mapping_schema();
    $field = $schema->getComponent('mapping');
    $field->getAction('addConstant')->call();
    expect($field->getConnectionModes()['static_2'])->not->toHaveKey('count');
    $key = $field->getSourceStateKeys()['static_2'];
    $schema
        ->getComponentByStatePath('mapping.sources.' . $key . '.transforms')
        ->state([
            ['key' => 'cast', 'params' => ['type' => 'integer']],
        ]);
    expect($field->getConnectionModes()['static_2']['count'])->toBe('whole');
    $state = $field->getRawState();
    $state['targets'][$field->getStateKeys()['count']] = MappingState::source('static_2');
    $field->rawState($state);
    expect($schema->getState(...))->toThrow(ValidationException::class);
    $field->getConstantValueSchema('static_2')->getComponent('static')->state('10');
    $row = $schema->getState()['mapping'][0];
    expect($row)->toMatchArray(['kind' => 'static', 'target' => 'count', 'static' => '10']);
    expect($row['transforms'])->toBe([['key' => 'cast', 'params' => ['type' => 'integer']]]);
});

it('keeps static source actions disabled when unavailable', function (bool $enabled, bool $disabled) {
    $schema = static_source_mapping_schema([], $disabled);
    $field = $schema->getComponent('mapping')->staticValues($enabled);
    $field->getAction('addConstant')->call();
    expect($field->getConstants())->toBe([]);
    expect($field->getAction('addConstant')->isDisabled())->toBeTrue();
})->with([[false, false], [true, true]]);

it('rejects non-scalar or empty values only after a static source is connected', function (mixed $value) {
    $schema = static_source_mapping_schema();
    $field = $schema->getComponent('mapping');
    $field->getAction('addConstant')->call();
    $field->getConstantValueSchema('static_2')->getComponent('static')->state($value);
    $state = $field->getRawState();
    $state['targets'][$field->getStateKeys()['name.part']] = MappingState::source('static_2');
    $field->rawState($state);
    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([[''], [null], [['invalid']]]);

it('fills settings defaults when loading a static row without settings', function () {
    $schema = static_source_mapping_schema([['kind' => 'static', 'target' => 'name.part', 'static' => 'Customer']]);
    expect($schema->getState()['mapping'][0])->toBe([
        'kind' => 'static',
        'target' => 'name.part',
        'static' => 'Customer',
        'alias' => 'default',
        'transforms' => [],
    ]);
});
