<?php

namespace PHPinnacle\Ferry\Resources\Connections\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use PHPinnacle\Common\Concerns\FormSlug;
use PHPinnacle\Common\Forms\ActiveSelect;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Resources\Connections\Actions\PrepareStructureAction;
use PHPinnacle\Ferry\Resources\Connections\Actions\TestConnectionAction;

class ConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('phpinnacle-ferry::resources.connection.sections.general'))
                    ->columns(4)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.name'))
                            ->columnSpan(2)
                            ->maxLength(255)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(FormSlug::make('code')),
                        TextInput::make('code')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.code'))
                            ->columnSpan(2)
                            ->maxLength(255)
                            ->scopedUnique(ignoreRecord: true)
                            ->required(),
                        Select::make('driver')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.driver'))
                            ->options(Driver::class)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?Driver $state) {
                                if ($state !== null) {
                                    $set('port', $state->defaultPort());
                                }
                            }),
                        ActiveSelect::make(),
                        TextInput::make('host')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.host'))
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('port')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.port'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(65_535)
                            ->required(),
                        TextInput::make('database')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.database'))
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('username')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.username'))
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('password')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.password'))
                            ->password()
                            ->revealable()
                            ->autocomplete(false)
                            ->maxLength(255)
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->required(fn (?Connection $record) => $record === null),
                        TextInput::make('schema')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.schema'))
                            ->maxLength(255),
                        TextInput::make('ssl_mode')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.ssl_mode'))
                            ->maxLength(255),
                        Actions::make([
                            TestConnectionAction::form(),
                        ])->columnSpanFull(),
                    ]),
                Section::make(__('phpinnacle-ferry::resources.connection.sections.structure'))
                    ->icon('phosphor-tree-structure')
                    ->columns(4)
                    ->visibleOn('edit')
                    ->headerActions([
                        PrepareStructureAction::form(),
                    ])
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.status'))
                            ->state(fn (Connection $record) => $record->status)
                            ->badge(),
                        TextEntry::make('objects')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.objects'))
                            ->state(fn (Connection $record) => $record->publishedMetadata()->count())
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('progress')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.progress'))
                            ->state(fn (Connection $record) => sprintf('%d / %d', $record->processed, $record->total))
                            ->visible(fn (Connection $record) => $record->total > 0),
                        TextEntry::make('published_at')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.published_at'))
                            ->state(fn (Connection $record) => $record->published_at)
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('last_error')
                            ->label(__('phpinnacle-ferry::resources.connection.fields.last_error'))
                            ->state(fn (Connection $record) => $record->last_error)
                            ->color('danger')
                            ->icon('phosphor-warning-circle')
                            ->visible(fn (Connection $record) => filled($record->last_error))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
