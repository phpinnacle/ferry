<?php

namespace PHPinnacle\Ferry\Resources\Syncs;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Models\Sync;

class SyncResource extends Resource
{
    protected static ?string $model = Sync::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\SyncForm::configure($schema);
    }

    public static function getNavigationGroup(): string
    {
        return __('phpinnacle-ferry::resources.sync.group');
    }

    public static function getNavigationIcon(): ?string
    {
        return config('phpinnacle-ferry.navigation.sync.icon') === null
            ? null
            : Config::string('phpinnacle-ferry.navigation.sync.icon');
    }

    public static function getNavigationLabel(): string
    {
        return __('phpinnacle-ferry::resources.sync.label');
    }

    public static function getNavigationSort(): ?int
    {
        return config('phpinnacle-ferry.navigation.sync.sort') === null
            ? null
            : Config::integer('phpinnacle-ferry.navigation.sync.sort');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncs::route('/'),
            'create' => Pages\CreateSync::route('/create'),
            'edit' => Pages\EditSync::route('/{record}/edit'),
        ];
    }

    public static function table(Table $table): Table
    {
        return Tables\SyncTable::configure($table);
    }
}
