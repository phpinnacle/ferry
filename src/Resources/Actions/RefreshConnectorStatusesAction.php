<?php

namespace PHPinnacle\Ferry\Resources\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use PHPinnacle\Ferry\Exceptions\ConnectorOperationFailed;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\ConnectorManager;
use Throwable;

class RefreshConnectorStatusesAction
{
    public static function table(): Action
    {
        return Action::make('refresh_connector_statuses')
            ->label(__('phpinnacle-ferry::resources.replica.actions.refresh_statuses'))
            ->icon('phosphor-arrows-clockwise')
            ->color('gray')
            ->authorize(fn () => Gate::allows('viewAny', Replica::class) && Gate::allows('viewAny', Upstream::class))
            ->action(function (ConnectorManager $connectors) {
                $errors = [];
                $connectors->refreshStatuses(function (Connector $connector, Throwable $exception) use (&$errors) {
                    if (!$exception instanceof ConnectorOperationFailed) {
                        throw $exception;
                    }

                    $errors[] = $connector->name . ': ' . $exception->getMessage();
                });

                if ($errors !== []) {
                    Notification::make()
                        ->title(__('phpinnacle-ferry::resources.replica.messages.status_refresh_failed'))
                        ->body(implode("\n", $errors))
                        ->danger()
                        ->send();
                } else {
                    Notification::make()
                        ->title(__('phpinnacle-ferry::resources.replica.messages.statuses_refreshed'))
                        ->success()
                        ->send();
                }
            });
    }
}
