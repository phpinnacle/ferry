<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Actions;

use Filament\Actions\Action;
use PHPinnacle\Ferry\Resources\Syncs\Pages\EditSync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Services\DestinationFactory;

/** @phpstan-import-type EditState from SyncForm */
class ConfirmSchemaChangesAction
{
    public static function configure(Action $action): Action
    {
        $columns = [];

        return $action
            ->mountUsing(function (
                EditSync $livewire,
                DestinationFactory $destinations,
            ) use (&$columns) {
                /** @var EditState $data */
                $data = $livewire->form->getState();
                $preview = clone $livewire->record;
                $preview->fill(SyncForm::forUpdate($data, $livewire->record, $destinations));
                $columns = $preview->droppedColumns();
            })
            ->action(fn (EditSync $livewire) => $livewire->save())
            ->modal(function () use (&$columns) {
                return $columns !== [];
            })
            ->requiresConfirmation()
            ->modalHeading(__('phpinnacle-ferry::resources.sync.modals.drop_columns.heading'))
            ->modalDescription(function () use (&$columns) {
                return __('phpinnacle-ferry::resources.sync.modals.drop_columns.description', [
                    'columns' => implode(', ', $columns),
                ]);
            });
    }
}
