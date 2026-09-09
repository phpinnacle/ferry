<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use Throwable;

class PauseSyncAction
{
    public static function table(): Action
    {
        return Action::make('pause_sync')
            ->authorize('update')
            ->label(__('phpinnacle-ferry::resources.sync.actions.pause'))
            ->icon('phosphor-pause-circle')
            ->iconButton()
            ->color('warning')
            ->visible(fn (Sync $record) => $record->status === SyncStatus::Active)
            ->action(function (ConnectorManager $manager, Sync $record) {
                try {
                    $manager->pause($record);

                    Notification::make()
                        ->title(__('phpinnacle-ferry::resources.sync.messages.paused'))
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
