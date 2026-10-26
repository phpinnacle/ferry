<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Services\ConnectorManager;
use Throwable;

class PauseReplicaAction
{
    public static function table(): Action
    {
        return Action::make('pause_replica')
            ->authorize('update')
            ->label(__('phpinnacle-ferry::resources.replica.actions.pause'))
            ->icon('phosphor-pause-circle')
            ->iconButton()
            ->color('warning')
            ->visible(fn (Replica $record) => $record->status === ReplicaStatus::Active)
            ->action(function (ConnectorManager $connectors, Replica $record) {
                try {
                    $connectors->pause($record);

                    Notification::make()
                        ->title(__('phpinnacle-ferry::resources.replica.messages.paused'))
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
