<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm as ResourceForm;

class ReplicaForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @param array<string, mixed> $state */
    public function mount(array $state): void
    {
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return ResourceForm::configure($schema->statePath('data')->operation('create'));
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}<x-filament-actions::modals /></div>';
    }
}
