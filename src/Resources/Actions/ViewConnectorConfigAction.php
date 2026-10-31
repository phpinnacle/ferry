<?php

namespace PHPinnacle\Ferry\Resources\Actions;

use Filament\Actions\Action;
use Filament\Infolists\Components\CodeEntry;
use Filament\Support\Enums\Width;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;

class ViewConnectorConfigAction
{
    public static function make(): Action
    {
        return Action::make('view_connector_config')
            ->label(__('phpinnacle-ferry::resources.connector.actions.config'))
            ->icon('phosphor-code')
            ->color('gray')
            ->authorize('update')
            ->visible(fn (Upstream|Replica $record) => $record->connector?->config !== null)
            ->modalIcon('phosphor-code')
            ->modalHeading(__('phpinnacle-ferry::resources.connector.actions.config'))
            ->modalDescription(__('phpinnacle-ferry::resources.connector.modals.config.description'))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->schema([
                CodeEntry::make('config')
                    ->hiddenLabel()
                    ->state(fn (Upstream|Replica $record) => $record->connector?->config)
                    ->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ->copyable()
                    ->columnSpanFull(),
            ]);
    }
}
