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
