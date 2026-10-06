<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use Throwable;

class CheckSinkStatusAction
{
    public static function table(): Action
    {
        return Action::make('check_sink_status')
            ->label(__('phpinnacle-ferry::resources.sync.actions.check_status'))
            ->icon('phosphor-arrows-clockwise')
            ->iconButton()
            ->action(function (ConnectorManager $manager, Sync $record) {
                try {
                    $manager->sinkStatus($record);

                    Notification::make()
                        ->title($record->fresh()?->connector?->status?->getLabel())
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
