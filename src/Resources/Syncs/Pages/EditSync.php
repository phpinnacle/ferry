<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Actions\ConfirmSchemaChangesAction;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Resources\Syncs\SyncResource;
use PHPinnacle\Ferry\Services\StaticDestinationRegistry;

/**
 * @property Sync $record
 * @phpstan-import-type EditState from SyncForm
 */
class EditSync extends EditRecord
{
    protected static string $resource = SyncResource::class;

    private StaticDestinationRegistry $destinations;

    public function boot(StaticDestinationRegistry $destinations): void
    {
        $this->destinations = $destinations;
    }

    public function getTitle(): string|Htmlable
    {
        return __('phpinnacle-ferry::resources.sync.pages.edit');
    }

    public function hasFormWrapper(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('phpinnacle-ferry::resources.sync.actions.delete'))
                ->requiresConfirmation()
                ->modalHeading(__('phpinnacle-ferry::resources.sync.modals.delete.heading'))
                ->modalDescription(__(
                    'phpinnacle-ferry::resources.sync.modals.delete.' . match ($this->record->destinationType()) {
                        DestinationType::Dynamic => 'description_dynamic',
                        DestinationType::Static => 'description_static',
                    },
                ))
                ->modalSubmitActionLabel(__('phpinnacle-ferry::resources.sync.actions.delete')),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return ConfirmSchemaChangesAction::configure(parent::getSaveFormAction());
    }

    /** @param EditState $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SyncForm::forUpdate($data, $this->record, $this->destinations);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SyncForm::fill($data);
    }
}
