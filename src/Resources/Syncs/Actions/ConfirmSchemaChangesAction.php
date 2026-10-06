<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Actions;

use Filament\Actions\Action;
use PHPinnacle\Ferry\Resources\Syncs\Pages\EditSync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Services\StaticDestinationRegistry;

/** @phpstan-import-type EditState from SyncForm */
class ConfirmSchemaChangesAction
{
    public static function configure(Action $action): Action
    {
        $columns = null;
        $droppedColumns = function (EditSync $livewire, StaticDestinationRegistry $destinations) use (&$columns) {
            if ($columns !== null) {
                return $columns;
            }

            /** @var EditState $data */
            $data = $livewire->form->getState();
            $attributes = SyncForm::forUpdate($data, $livewire->record, $destinations);
            $preview = clone $livewire->record;
            $preview->fill($attributes);

            return $columns = $preview->droppedColumns();
        };

        return $action
            ->mountUsing(
                fn (EditSync $livewire, StaticDestinationRegistry $destinations) => $droppedColumns(
                    $livewire,
                    $destinations,
                ),
            )
            ->action(fn (EditSync $livewire) => $livewire->save())
            ->modal(
                fn (EditSync $livewire, StaticDestinationRegistry $destinations) => (
                    $droppedColumns($livewire, $destinations) !== []
                ),
            )
            ->requiresConfirmation()
            ->modalHeading(__('phpinnacle-ferry::resources.sync.modals.drop_columns.heading'))
            ->modalDescription(fn (EditSync $livewire, StaticDestinationRegistry $destinations) => __(
                'phpinnacle-ferry::resources.sync.modals.drop_columns.description',
                ['columns' => implode(', ', $droppedColumns($livewire, $destinations))],
            ));
    }
}
