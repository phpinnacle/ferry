<?php

namespace PHPinnacle\Ferry\Resources\Connections;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use PHPinnacle\Ferry\Models\Connection;

class ConnectionResource extends Resource
{
    protected static ?string $model = Connection::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\ConnectionForm::configure($schema);
    }

    public static function getNavigationGroup(): string
    {
        return __('phpinnacle-ferry::resources.connection.group');
    }

    public static function getNavigationIcon(): ?string
    {
        return config('phpinnacle-ferry.navigation.connection.icon');
    }

    public static function getNavigationLabel(): string
    {
        return __('phpinnacle-ferry::resources.connection.label');
    }

    public static function getNavigationSort(): ?int
    {
        return config('phpinnacle-ferry.navigation.connection.sort');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConnections::route('/'),
            'create' => Pages\CreateConnection::route('/create'),
            'edit' => Pages\EditConnection::route('/{record}/edit'),
        ];
    }

    public static function table(Table $table): Table
    {
        return Tables\ConnectionTable::configure($table);
    }
}
