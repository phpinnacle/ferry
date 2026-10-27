<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use PHPinnacle\Common\Tables\CreatedColumn;
use PHPinnacle\Common\Tables\UpdatedColumn;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Resources\Actions\RefreshConnectorStatusesAction;
use PHPinnacle\Ferry\Resources\Replicas\Actions\PauseReplicaAction;
use PHPinnacle\Ferry\Resources\Replicas\Actions\StartReplicaAction;
use PHPinnacle\Ferry\Resources\Replicas\ReplicaResource;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;

class ReplicaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->heading(__('phpinnacle-ferry::resources.replica.pages.list'))
            ->emptyStateHeading(__('phpinnacle-ferry::resources.replica.empty.heading'))
            ->emptyStateDescription(__('phpinnacle-ferry::resources.replica.empty.description'))
            ->emptyStateIcon(ReplicaResource::getNavigationIcon())
            ->columns([
                TextColumn::make('name')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('upstream.name')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.upstream'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('destination')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.destination'))
                    ->state(fn (Replica $record, DestinationFactory $destinations) => $destinations->resolve($record))
                    ->badge()
                    ->searchable(),
                TextColumn::make('connector.status')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.sink_connector_status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.status'))
                    ->badge()
                    ->sortable(),
                CreatedColumn::make(),
                UpdatedColumn::make(),
            ])
            ->headerActions([
                RefreshConnectorStatusesAction::table(),
                CreateAction::make()
                    ->label(__('phpinnacle-ferry::resources.replica.actions.create'))
                    ->modalIcon(ReplicaResource::getNavigationIcon())
                    ->modalHeading(__('phpinnacle-ferry::resources.replica.pages.create'))
                    ->modalDescription(__('phpinnacle-ferry::resources.replica.modals.create.description'))
                    ->schema(ReplicaForm::creation(...))
                    ->mutateDataUsing(ReplicaForm::forCreate(...))
                    ->createAnother(false)
                    ->successRedirectUrl(fn (Replica $record) => ReplicaResource::getUrl('edit', [
                        'record' => $record,
                    ])),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label(__('phpinnacle-ferry::resources.replica.actions.delete'))
                    ->modalHeading(__('phpinnacle-ferry::resources.replica.modals.delete.heading'))
                    ->modalDescription(__('phpinnacle-ferry::resources.replica.modals.delete.description_bulk')),
            ])
            ->recordActions([
                StartReplicaAction::table(),
                PauseReplicaAction::table(),
                EditAction::make()
                    ->label(__('phpinnacle-ferry::resources.replica.actions.update'))
                    ->iconButton(),
                DeleteAction::make()
                    ->label(__('phpinnacle-ferry::resources.replica.actions.delete'))
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalHeading(__('phpinnacle-ferry::resources.replica.modals.delete.heading'))
                    ->modalDescription(fn (Replica $record) => __(
                        'phpinnacle-ferry::resources.replica.modals.delete.' . match ($record->type) {
                            DestinationType::Dynamic => 'description_dynamic',
                            DestinationType::Static => 'description_static',
                        },
                    ))
                    ->modalSubmitActionLabel(__('phpinnacle-ferry::resources.replica.actions.delete')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.status'))
                    ->options(ReplicaStatus::class),
                SelectFilter::make('upstream')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.upstream'))
                    ->relationship('upstream', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('destination')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.destination'))
                    ->options(
                        fn (DestinationFactory $destinations) => Replica::query()
                            ->distinct()
                            ->orderBy('destination')
                            ->get(['type', 'destination'])
                            ->mapWithKeys(fn (Replica $replica) => [
                                $replica->destination => $destinations->resolve($replica)->getLabel(),
                            ])
                            ->all(),
                    )
                    ->searchable(),
            ])
            ->defaultSort('name');
    }
}
