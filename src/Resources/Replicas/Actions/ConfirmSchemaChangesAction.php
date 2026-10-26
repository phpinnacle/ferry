<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Actions;

use Filament\Actions\Action;
use PHPinnacle\Ferry\Resources\Replicas\Pages\EditReplica;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;

/** @phpstan-import-type EditState from ReplicaForm */
class ConfirmSchemaChangesAction
{
    public static function configure(Action $action): Action
    {
        $columns = [];

        return $action
            ->mountUsing(function (
                EditReplica $livewire,
                DestinationFactory $destinations,
            ) use (&$columns) {
                /** @var EditState $data */
                $data = $livewire->form->getState();
                $preview = clone $livewire->record;
                $preview->fill(ReplicaForm::forUpdate($data, $livewire->record, $destinations));
                $columns = $preview->droppedColumns();
            })
            ->action(fn (EditReplica $livewire) => $livewire->save())
            ->modal(function () use (&$columns) {
                return $columns !== [];
            })
            ->requiresConfirmation()
            ->modalHeading(__('phpinnacle-ferry::resources.replica.modals.drop_columns.heading'))
            ->modalDescription(function () use (&$columns) {
                return __('phpinnacle-ferry::resources.replica.modals.drop_columns.description', [
                    'columns' => implode(', ', $columns),
                ]);
            });
    }
}
