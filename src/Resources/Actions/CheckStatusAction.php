<?php

namespace PHPinnacle\Ferry\Resources\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\ConnectorManager;
use Throwable;

class CheckStatusAction
{
    public static function table(string $name, string $label): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('phosphor-arrows-clockwise')
            ->iconButton()
            ->action(function (ConnectorManager $connectors, Upstream|Replica $record) {
                try {
                    $connectors->checkStatus($record);

                    Notification::make()
                        ->title(($record->connector->status ?? ConnectorStatus::Unknown)->getLabel())
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
