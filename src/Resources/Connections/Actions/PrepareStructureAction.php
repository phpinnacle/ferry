<?php

namespace PHPinnacle\Ferry\Resources\Connections\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Connection;

class PrepareStructureAction
{
    public static function form(): Action
    {
        return self::action();
    }

    public static function table(): Action
    {
        return self::action()->iconButton();
    }

    private static function action(): Action
    {
        return Action::make('prepare_structure')
            ->label(fn (?Connection $record) => __(
                'phpinnacle-ferry::resources.connection.actions.structure.'
                . ($record->status ?? StructureStatus::Pending)->value,
            ))
            ->icon('phosphor-tree-structure')
            ->color('gray')
            ->visible(fn (?Connection $record) => $record?->canPrepareStructure() ?? false)
            ->requiresConfirmation(fn (?Connection $record) => $record?->status === StructureStatus::Ready)
            ->modalIcon('phosphor-tree-structure')
            ->modalHeading(__('phpinnacle-ferry::resources.connection.modals.structure.heading'))
            ->modalDescription(__('phpinnacle-ferry::resources.connection.modals.structure.description'))
            ->modalSubmitActionLabel(__('phpinnacle-ferry::resources.connection.actions.structure.ready'))
            ->action(fn (Connection $record) => self::notify($record->prepareStructure()));
    }

    private static function notify(bool $queued): void
    {
        $notification = Notification::make()->title(__(
            $queued
                ? 'phpinnacle-ferry::resources.connection.messages.structure_queued'
                : 'phpinnacle-ferry::resources.connection.messages.structure_active',
        ));

        $queued ? $notification->success() : $notification->warning();

        $notification->send();
    }
}
