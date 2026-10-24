<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use PHPinnacle\Ferry\Forms\FieldMapping;

class FieldMappingForm extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public array $data = [];

    public bool $dynamicTarget = false;

    public function mount(bool $dynamicTarget = false): void
    {
        $this->dynamicTarget = $dynamicTarget;
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $target = $this->dynamicTarget
            ? fn (Get $get) => [
                'properties' => ['destination' => [
                    'type' => $get('mapping.sources.source_6669727374.type') ?? 'string',
                ]],
            ] : ['properties' => ['destination' => ['type' => 'boolean']]];

        return $schema
            ->statePath('data')
            ->components([
                FieldMapping::make('mapping')
                    ->jsonSchema(source: [
                        'properties' => ['first' => ['type' => 'string'], 'second' => ['type' => 'string']],
                    ], dest: $target)
                    ->sourceSettings(fn () => [Select::make('type')
                        ->options(['string' => 'String', 'boolean' => 'Boolean'])
                        ->default('string')
                        ->live()])
                    ->outputSchema(function (FieldMapping $component) {
                        $schema = $component->getSourceSchema();
                        $type = $component->getSourceSettings()['first']['type'] ?? 'string';
                        $schema['properties']['first']['type'] = $type;
                        $schema['properties']['second']['type'] = $type;

                        return $schema;
                    }),
            ]);
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
