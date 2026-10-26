<?php

namespace PHPinnacle\Ferry\Resources\Replicas;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use PHPinnacle\Ferry\Models\Replica;

class ReplicaResource extends Resource
{
    protected static ?string $model = Replica::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\ReplicaForm::configure($schema);
    }

    public static function getNavigationGroup(): string
    {
        return __('phpinnacle-ferry::resources.replica.group');
    }

    public static function getNavigationIcon(): ?string
    {
        /** @var string|null */
        return config('phpinnacle-ferry.navigation.replica.icon');
    }

    public static function getNavigationLabel(): string
    {
        return __('phpinnacle-ferry::resources.replica.label');
    }

    public static function getNavigationSort(): ?int
    {
        /** @var int|null */
        return config('phpinnacle-ferry.navigation.replica.sort');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReplicas::route('/'),
            'edit' => Pages\EditReplica::route('/{record}/edit'),
        ];
    }

    public static function table(Table $table): Table
    {
        return Tables\ReplicaTable::configure($table);
    }
}
