<?php

namespace PHPinnacle\Ferry\Resources\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use Throwable;

class CheckConnectorStatusAction
{
    public static function table(string $name, string $label): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('phosphor-arrows-clockwise')
            ->iconButton()
            ->action(function (ConnectorManager $manager, Connection|Sync $record) {
                try {
                    $manager->checkStatus($record);

                    Notification::make()
                        ->title($record->connector?->status?->getLabel())
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
