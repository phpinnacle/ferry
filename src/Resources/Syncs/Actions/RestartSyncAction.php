<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use Throwable;

class RestartSyncAction
{
    public static function table(): Action
    {
        return Action::make('restart_sync')
            ->authorize('update')
            ->label(__('phpinnacle-ferry::resources.sync.actions.restart'))
            ->icon('phosphor-arrow-counter-clockwise')
            ->iconButton()
            ->color('warning')
            ->visible(fn (Sync $record) => $record->sink_connector_status === ConnectorStatus::Failed)
            ->action(function (ConnectorManager $manager, Sync $record) {
                try {
                    $manager->restart($record);

                    Notification::make()
                        ->title(__('phpinnacle-ferry::resources.sync.messages.restarted'))
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
