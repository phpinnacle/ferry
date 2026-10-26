<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Services\ConnectorManager;
use Throwable;

class ActivateReplicaAction
{
    public static function table(): Action
    {
        return Action::make('activate_replica')
            ->authorize('update')
            ->label(__('phpinnacle-ferry::resources.replica.actions.activate'))
            ->icon('phosphor-play-circle')
            ->iconButton()
            ->color('success')
            ->visible(
                fn (Replica $record) => (
                    $record->status !== ReplicaStatus::Active
                    || $record->connector?->status !== ConnectorStatus::Running
                ),
            )
            ->action(function (ConnectorManager $connectors, Replica $record) {
                try {
                    $connectors->activate($record);

                    Notification::make()
                        ->title(__('phpinnacle-ferry::resources.replica.messages.activated'))
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
