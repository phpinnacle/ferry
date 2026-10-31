<?php

namespace PHPinnacle\Ferry\Resources\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\ConnectorManager;
use Throwable;

class RequestSnapshotAction
{
    public static function make(): Action
    {
        return Action::make('request_snapshot')
            ->authorize('update')
            ->label(__('phpinnacle-ferry::resources.connector.actions.snapshot'))
            ->icon('phosphor-arrows-clockwise')
            ->color('gray')
            ->visible(fn (ConnectorManager $connectors, Upstream|Replica $record) => $connectors->canSnapshot($record))
            ->requiresConfirmation()
            ->modalHeading(__('phpinnacle-ferry::resources.connector.modals.snapshot.heading'))
            ->modalDescription(fn (Upstream|Replica $record) => __(
                'phpinnacle-ferry::resources.connector.modals.snapshot.'
                . ($record instanceof Replica ? 'replica' : 'upstream'),
            ))
            ->modalSubmitActionLabel(__('phpinnacle-ferry::resources.connector.actions.snapshot'))
            ->action(function (ConnectorManager $connectors, Upstream|Replica $record) {
                try {
                    $connectors->snapshot($record);

                    Notification::make()
                        ->title(__('phpinnacle-ferry::resources.connector.messages.snapshot_requested'))
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
