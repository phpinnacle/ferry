<?php

namespace PHPinnacle\Ferry\Resources\Connections\Tables;

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
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Resources\Connections\Actions\PrepareStructureAction;
use PHPinnacle\Ferry\Resources\Connections\Actions\TestConnectionAction;
use PHPinnacle\Ferry\Resources\Connections\ConnectionResource;

class ConnectionTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->heading(__('phpinnacle-ferry::resources.connection.pages.list'))
            ->emptyStateHeading(__('phpinnacle-ferry::resources.connection.empty.heading'))
            ->emptyStateDescription(__('phpinnacle-ferry::resources.connection.empty.description'))
            ->emptyStateIcon(ConnectionResource::getNavigationIcon())
            ->columns([
                TextColumn::make('name')
                    ->label(__('phpinnacle-ferry::resources.connection.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label(__('phpinnacle-ferry::resources.connection.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('driver')
                    ->label(__('phpinnacle-ferry::resources.connection.fields.driver'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('host')
                    ->label(__('phpinnacle-ferry::resources.connection.fields.host'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('database')
                    ->label(__('phpinnacle-ferry::resources.connection.fields.database'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('phpinnacle-ferry::resources.connection.fields.structure'))
                    ->badge()
                    ->sortable(),
                ActiveColumn::make()
                    ->action(fn (Connection $record) => $record->toggleActive()),
                CreatedColumn::make(),
                UpdatedColumn::make(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('phpinnacle-ferry::resources.connection.actions.create')),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label(__('phpinnacle-ferry::resources.connection.actions.delete')),
            ])
            ->recordActions([
                TestConnectionAction::table(),
                PrepareStructureAction::table(),
                EditAction::make()
                    ->label(__('phpinnacle-ferry::resources.connection.actions.update'))
                    ->iconButton(),
                DeleteAction::make()
                    ->label(__('phpinnacle-ferry::resources.connection.actions.delete'))
                    ->iconButton(),
            ])
            ->filters([
                ActiveFilter::make(),
            ])
            ->defaultSort('name');
    }
}
