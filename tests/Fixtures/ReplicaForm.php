<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm as ResourceForm;
use PHPinnacle\Ferry\Tests\TestCase;

class ReplicaForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public array $data = [];

    public bool $creating = false;

    public ?Replica $record = null;

    /** @param array<string, mixed> $state */
    public function mount(array $state, bool $creating = false, ?Replica $record = null): void
    {
        $this->creating = $creating;
        if (!$creating) {
            $this->record = $record ?? TestCase::makeReplica([...$state, 'mapping' => [], 'transforms' => []]);
        }

        $this->form->fill([...($this->record?->toArray() ?? []), ...$state]);
    }

    public function form(Schema $schema): Schema
    {
        $schema
            ->statePath('data')
            ->record($this->record)
            ->operation($this->creating ? 'create' : 'edit');

        return $this->creating ? ResourceForm::creation($schema) : ResourceForm::configure($schema);
    }

    public function validateForm(): void
    {
        $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}<x-filament-actions::modals /></div>';
    }
}
