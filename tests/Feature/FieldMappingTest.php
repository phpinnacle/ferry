<?php

use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Js;
use Illuminate\Support\ViewErrorBag;
use Livewire\Component;
use PHPinnacle\Ferry\FerryServiceProvider;
use PHPinnacle\Ferry\Forms\FieldMapping;
use Tests\TestCase;

uses(TestCase::class);

it('renders the standalone mapping field with package views and on-demand assets', function () {
    $livewire = new class extends Component implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('field-mapping-test');
    $livewire->setName('field-mapping-test');

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([
            FieldMapping::make('mapping')
                ->options(source: ['title'], dest: ['name'])
                ->columnSpanFull(),
        ]);
    $schema->fill(['mapping' => ['name' => 'title']]);

    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();

    expect($html)
        ->toContain('data-mapping-port')
        ->toContain('Select two fields to connect.')
        ->toContain(FilamentAsset::getAlpineComponentSrc('field-mapping', FerryServiceProvider::PACKAGE))
        ->toContain(Js::from(FilamentAsset::getStyleHref('field-mapping', FerryServiceProvider::PACKAGE))->toHtml())
        ->not->toContain('pointermove')
        ->not->toContain('pointerdown');
});

it('keeps the mapping toolbar and footer when either side has no fields', function (
    array $sources,
    array $targets,
) {
    $livewire = new class extends Component implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('empty-field-mapping-test');
    $livewire->setName('empty-field-mapping-test');

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([
            FieldMapping::make('mapping')
                ->options(source: $sources, dest: $targets)
                ->warnings(dest: ['name' => 'Review the destination.'])
                ->labels(source: 'Incoming fields', dest: 'Output fields'),
        ]);
    $schema->fill(['mapping' => []]);
    view()->share('errors', new ViewErrorBag);

    expect($schema->toHtml())
        ->toContain('fi-empty-state')
        ->toContain('No fields available.')
        ->not->toContain('Incoming fields')
        ->not->toContain('Output fields')
        ->not->toContain('type="search"')->toContain('Show unmapped')->toContain(
            'Select two fields to connect.',
        )->toContain('x-data="fieldMapping(')->toContain('x-ref="canvas"')->toContain('Review the destination.');
})->with([
    'no source fields' => [[], ['name']],
    'no target fields' => [['title'], []],
    'no fields' => [[], []],
]);

it('highlights warnings on either side and preserves missing identifiers in both orientations', function (bool $inverse) {
    $livewire = new class extends Component implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('warned-field-mapping-test');
    $livewire->setName('warned-field-mapping-test');

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([
            FieldMapping::make('mapping')
                ->options(source: ['title', 'email'], dest: ['name', 'contact'])
                ->warnings(
                    source: fn () => ['title' => 'Source <type> changed.', '42' => 'Source disappeared.'],
                    dest: ['name' => 'Destination changed.', '42' => 'Destination disappeared.'],
                )
                ->inverse($inverse),
        ]);
    $schema->fill(['mapping' => $inverse ? ['title' => 'name'] : ['name' => 'title']]);
    view()->share('errors', new ViewErrorBag);

    $html = $schema->toHtml();

    expect($html)
        ->toContain('Source &lt;type&gt; changed.')
        ->toContain('Destination changed.')
        ->toContain('42: Source disappeared.')
        ->toContain('42: Destination disappeared.');
    expect(substr_count($html, 'has-warning'))->toBe(2);
})->with([false, true]);
