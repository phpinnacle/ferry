<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Services\ConnectorManager;
use Throwable;

class RestartReplicaAction
{
    public static function table(): Action
    {
        return Action::make('restart_replica')
            ->authorize('update')
            ->label(__('phpinnacle-ferry::resources.replica.actions.restart'))
            ->icon('phosphor-arrow-counter-clockwise')
            ->iconButton()
            ->color('warning')
            ->visible(fn (Replica $record) => $record->connector?->status === ConnectorStatus::Failed)
            ->action(function (ConnectorManager $connectors, Replica $record) {
                try {
                    $connectors->restart($record);

                    Notification::make()
                        ->title(__('phpinnacle-ferry::resources.replica.messages.restarted'))
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
