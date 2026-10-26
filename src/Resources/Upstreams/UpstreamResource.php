<?php

namespace PHPinnacle\Ferry\Resources\Upstreams;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use PHPinnacle\Ferry\Models\Upstream;

class UpstreamResource extends Resource
{
    protected static ?string $model = Upstream::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\UpstreamForm::configure($schema);
    }

    public static function getNavigationGroup(): string
    {
        return __('phpinnacle-ferry::resources.upstream.group');
    }

    public static function getNavigationIcon(): ?string
    {
        /** @var string|null */
        return config('phpinnacle-ferry.navigation.upstream.icon');
    }

    public static function getNavigationLabel(): string
    {
        return __('phpinnacle-ferry::resources.upstream.label');
    }

    public static function getNavigationSort(): ?int
    {
        /** @var int|null */
        return config('phpinnacle-ferry.navigation.upstream.sort');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUpstreams::route('/'),
            'create' => Pages\CreateUpstream::route('/create'),
            'edit' => Pages\EditUpstream::route('/{record}/edit'),
        ];
    }

    public static function table(Table $table): Table
    {
        return Tables\UpstreamTable::configure($table);
    }
}
