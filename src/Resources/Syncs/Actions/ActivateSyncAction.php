<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use Throwable;

class ActivateSyncAction
{
    public static function table(): Action
    {
        return Action::make('activate_sync')
            ->authorize('update')
            ->label(__('phpinnacle-ferry::resources.sync.actions.activate'))
            ->icon('phosphor-play-circle')
            ->iconButton()
            ->color('success')
            ->visible(
                fn (Sync $record) => (
                    $record->status !== SyncStatus::Active
                    || $record->sink_connector_status !== ConnectorStatus::Running
                ),
            )
            ->action(function (ConnectorManager $manager, Sync $record) {
                try {
                    $manager->activate($record);

                    Notification::make()
                        ->title(__('phpinnacle-ferry::resources.sync.messages.activated'))
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
