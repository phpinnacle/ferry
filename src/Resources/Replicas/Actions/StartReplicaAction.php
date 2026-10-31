<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Services\ConnectorManager;
use Throwable;

class StartReplicaAction
{
    public static function table(): Action
    {
        return Action::make('start_replica')
            ->authorize('update')
            ->label(__('phpinnacle-ferry::resources.replica.actions.start'))
            ->icon('phosphor-play-circle')
            ->iconButton()
            ->color('success')
            ->visible(fn (Replica $record) => $record->mapping !== [] && !$record->isRunning())
            ->action(function (ConnectorManager $connectors, Replica $record) {
                try {
                    $connectors->start($record);

                    Notification::make()
                        ->title(__('phpinnacle-ferry::resources.replica.messages.started'))
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
