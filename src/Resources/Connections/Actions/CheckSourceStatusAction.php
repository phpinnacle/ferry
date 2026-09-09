<?php

namespace PHPinnacle\Ferry\Resources\Connections\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use Throwable;

class CheckSourceStatusAction
{
    public static function table(): Action
    {
        return Action::make('check_source_status')
            ->label(__('phpinnacle-ferry::resources.connection.actions.check_source_status'))
            ->icon('phosphor-arrows-clockwise')
            ->iconButton()
            ->action(function (ConnectorManager $manager, Connection $record) {
                try {
                    $manager->sourceStatus($record);

                    Notification::make()
                        ->title($record->fresh()?->source_connector_status?->getLabel())
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
