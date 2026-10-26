<?php

namespace PHPinnacle\Ferry\Resources\Upstreams\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Upstream;

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
        $staleAfter = Config::integer('phpinnacle-ferry.structure.stale_after');

        return Action::make('prepare_structure')
            ->authorize('update')
            ->label(fn (?Upstream $record) => __(
                'phpinnacle-ferry::resources.upstream.actions.structure.'
                . ($record->structure->status ?? StructureStatus::Pending)->value,
            ))
            ->icon('phosphor-tree-structure')
            ->color('gray')
            ->visible(fn (?Upstream $record) => $record?->structure->canPrepare($staleAfter) ?? false)
            ->requiresConfirmation(fn (?Upstream $record) => $record?->structure->status === StructureStatus::Ready)
            ->modalIcon('phosphor-tree-structure')
            ->modalHeading(__('phpinnacle-ferry::resources.upstream.modals.structure.heading'))
            ->modalDescription(__('phpinnacle-ferry::resources.upstream.modals.structure.description'))
            ->modalSubmitActionLabel(__('phpinnacle-ferry::resources.upstream.actions.structure.ready'))
            ->action(fn (Upstream $record) => self::notify($record->structure->prepare($staleAfter)));
    }

    private static function notify(bool $queued): void
    {
        $notification = Notification::make()->title(__(
            $queued
                ? 'phpinnacle-ferry::resources.upstream.messages.structure_queued'
                : 'phpinnacle-ferry::resources.upstream.messages.structure_active',
        ));

        $queued ? $notification->success() : $notification->warning();

        $notification->send();
    }
}
