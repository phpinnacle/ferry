<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use PHPinnacle\Ferry\Forms\FieldBinding;

class FieldBindingForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $saved = [];

    public bool $complex = false;

    /** @param array<string, mixed> $bindings */
    public function mount(bool $complex = false, array $bindings = []): void
    {
        $this->complex = $complex;
        $this->form->fill(['bindings' => $bindings]);
    }

    public function form(Schema $schema): Schema
    {
        $field = FieldBinding::make('bindings')->options(CustomerMappingField::cases());

        if ($this->complex) {
            $field->schema([
                TextInput::make('destination')->required(),
                Toggle::make('trim')->default(true),
            ]);
        } else {
            $field->simple(TextInput::make('destination')->required());
        }

        return $schema->statePath('data')->components([$field]);
    }

    public function submit(): void
    {
        $this->saved = $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}<x-filament-actions::modals /></div>';
    }
}
