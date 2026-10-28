<?php

namespace PHPinnacle\Ferry\Resources\Upstreams\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PHPinnacle\Common\Filters\ActiveFilter;
use PHPinnacle\Common\Tables\ActiveColumn;
use PHPinnacle\Common\Tables\CreatedColumn;
use PHPinnacle\Common\Tables\UpdatedColumn;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Actions\RefreshConnectorStatusesAction;
use PHPinnacle\Ferry\Resources\Upstreams\Actions\PrepareStructureAction;
use PHPinnacle\Ferry\Resources\Upstreams\Actions\TestUpstreamAction;
use PHPinnacle\Ferry\Resources\Upstreams\UpstreamResource;

class UpstreamTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->heading(__('phpinnacle-ferry::resources.upstream.pages.list'))
            ->emptyStateHeading(__('phpinnacle-ferry::resources.upstream.empty.heading'))
            ->emptyStateDescription(__('phpinnacle-ferry::resources.upstream.empty.description'))
            ->emptyStateIcon(UpstreamResource::getNavigationIcon())
            ->columns([
                TextColumn::make('name')
                    ->label(__('phpinnacle-ferry::resources.upstream.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label(__('phpinnacle-ferry::resources.upstream.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('driver')
                    ->label(__('phpinnacle-ferry::resources.upstream.fields.driver'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('structure.status')
                    ->label(__('phpinnacle-ferry::resources.upstream.fields.structure'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('connector.status')
                    ->label(__('phpinnacle-ferry::resources.upstream.fields.source_connector_status'))
                    ->badge()
                    ->tooltip(fn (Upstream $record) => $record->connector?->status === ConnectorStatus::Failed
                        ? $record->connector->error
                        : null)
                    ->sortable(),
                ActiveColumn::make()
                    ->action(fn (Upstream $record) => $record->toggleActive()),
                CreatedColumn::make(),
                UpdatedColumn::make(),
            ])
            ->headerActions([
                RefreshConnectorStatusesAction::table(),
                CreateAction::make()
                    ->label(__('phpinnacle-ferry::resources.upstream.actions.create')),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label(__('phpinnacle-ferry::resources.upstream.actions.delete')),
            ])
            ->recordActions([
                TestUpstreamAction::table(),
                PrepareStructureAction::table(),
                EditAction::make()
                    ->label(__('phpinnacle-ferry::resources.upstream.actions.update'))
                    ->iconButton(),
                DeleteAction::make()
                    ->label(__('phpinnacle-ferry::resources.upstream.actions.delete'))
                    ->iconButton(),
            ])
            ->filters([
                ActiveFilter::make(),
            ])
            ->defaultSort('name');
    }
}
