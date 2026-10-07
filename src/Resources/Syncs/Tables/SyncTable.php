<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PHPinnacle\Common\Tables\CreatedColumn;
use PHPinnacle\Common\Tables\UpdatedColumn;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Actions\CheckConnectorStatusAction;
use PHPinnacle\Ferry\Resources\Syncs\Actions\ActivateSyncAction;
use PHPinnacle\Ferry\Resources\Syncs\Actions\PauseSyncAction;
use PHPinnacle\Ferry\Resources\Syncs\Actions\RestartSyncAction;
use PHPinnacle\Ferry\Resources\Syncs\SyncResource;

class SyncTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->heading(__('phpinnacle-ferry::resources.sync.pages.list'))
            ->emptyStateHeading(__('phpinnacle-ferry::resources.sync.empty.heading'))
            ->emptyStateDescription(__('phpinnacle-ferry::resources.sync.empty.description'))
            ->emptyStateIcon(SyncResource::getNavigationIcon())
            ->columns([
                TextColumn::make('name')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('connection.name')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.connection'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.type'))
                    ->sortable(),
                TextColumn::make('destination')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.destination'))
                    ->searchable(),
                TextColumn::make('connector.status')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.sink_connector_status'))
                    ->badge()
                    ->sortable(),
                CreatedColumn::make(),
                UpdatedColumn::make(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('phpinnacle-ferry::resources.sync.actions.create')),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label(__('phpinnacle-ferry::resources.sync.actions.delete'))
                    ->modalHeading(__('phpinnacle-ferry::resources.sync.modals.delete.heading'))
                    ->modalDescription(__('phpinnacle-ferry::resources.sync.modals.delete.description_bulk')),
            ])
            ->recordActions([
                ActivateSyncAction::table(),
                PauseSyncAction::table(),
                RestartSyncAction::table(),
                CheckConnectorStatusAction::table(
                    'check_sink_status',
                    __('phpinnacle-ferry::resources.sync.actions.check_status'),
                ),
                EditAction::make()
                    ->label(__('phpinnacle-ferry::resources.sync.actions.update'))
                    ->iconButton(),
                DeleteAction::make()
                    ->label(__('phpinnacle-ferry::resources.sync.actions.delete'))
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalHeading(__('phpinnacle-ferry::resources.sync.modals.delete.heading'))
                    ->modalDescription(fn (Sync $record) => __(
                        'phpinnacle-ferry::resources.sync.modals.delete.' . match ($record->type) {
                            DestinationType::Dynamic => 'description_dynamic',
                            DestinationType::Static => 'description_static',
                        },
                    ))
                    ->modalSubmitActionLabel(__('phpinnacle-ferry::resources.sync.actions.delete')),
            ])
            ->defaultSort('name');
    }
}
