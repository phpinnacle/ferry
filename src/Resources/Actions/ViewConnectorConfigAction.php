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
            ->modalHeading(__('phpinnacle-ferry::resources.connector.actions.config'))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('phpinnacle-ferry::resources.connector.actions.close'))
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
