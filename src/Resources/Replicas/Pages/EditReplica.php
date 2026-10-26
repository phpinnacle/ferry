<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Resources\Replicas\Actions\ConfirmSchemaChangesAction;
use PHPinnacle\Ferry\Resources\Replicas\ReplicaResource;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;

/**
 * @property Replica $record
 * @phpstan-import-type EditState from ReplicaForm
 * @phpstan-import-type FillState from ReplicaForm
 */
class EditReplica extends EditRecord
{
    protected static string $resource = ReplicaResource::class;

    private DestinationFactory $destinations;

    public function boot(
        DestinationFactory $destinations,
    ): void {
        $this->destinations = $destinations;
    }

    public function getTitle(): string|Htmlable
    {
        return __('phpinnacle-ferry::resources.replica.pages.edit');
    }

    public function hasFormWrapper(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('phpinnacle-ferry::resources.replica.actions.delete'))
                ->requiresConfirmation()
                ->modalHeading(__('phpinnacle-ferry::resources.replica.modals.delete.heading'))
                ->modalDescription(__(
                    'phpinnacle-ferry::resources.replica.modals.delete.' . match ($this->record->type) {
                        DestinationType::Dynamic => 'description_dynamic',
                        DestinationType::Static => 'description_static',
                    },
                ))
                ->modalSubmitActionLabel(__('phpinnacle-ferry::resources.replica.actions.delete')),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return ConfirmSchemaChangesAction::configure(parent::getSaveFormAction());
    }

    /** @param EditState $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ReplicaForm::forUpdate($data, $this->record, $this->destinations);
    }

    /** @param FillState $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ReplicaForm::fill($data);
    }
}
