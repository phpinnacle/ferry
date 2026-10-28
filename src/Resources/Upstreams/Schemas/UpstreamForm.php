<?php

namespace PHPinnacle\Ferry\Resources\Upstreams\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use PHPinnacle\Common\Concerns\FormSlug;
use PHPinnacle\Common\Forms\ActiveSelect;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Upstreams\Actions\PrepareStructureAction;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Services\StructureAdapterRegistry;

class UpstreamForm
{
    public static function configure(Schema $schema): Schema
    {
        $config = Group::make()
            ->statePath('config')
            ->schema(fn (Get $get) => $get->enum('driver', Driver::class, isNullable: true)?->schema() ?? []);

        return $schema
            ->columns(1)
            ->components([
                Callout::make(__('phpinnacle-ferry::resources.upstream.messages.connector_failed'))
                    ->key('connectorError')
                    ->danger()
                    ->description(fn (?Upstream $record) => $record?->connector?->error)
                    ->visible(
                        fn (?Upstream $record) => (
                            $record?->connector?->status === ConnectorStatus::Failed
                            && filled($record->connector->error)
                        ),
                    )
                    ->extraAttributes(['role' => 'alert']),
                Section::make(__('phpinnacle-ferry::resources.upstream.sections.general'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('phpinnacle-ferry::resources.upstream.fields.name'))
                            ->maxLength(255)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(FormSlug::make('code')),
                        TextInput::make('code')
                            ->label(__('phpinnacle-ferry::resources.upstream.fields.code'))
                            ->maxLength(255)
                            ->scopedUnique(ignoreRecord: true)
                            ->required(),
                        ActiveSelect::make(),
                        Select::make('origin')
                            ->label(__('phpinnacle-ferry::resources.upstream.fields.origin'))
                            ->options(fn (OriginFactory $origins) => $origins->options())
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('driver')
                            ->label(__('phpinnacle-ferry::resources.upstream.fields.driver'))
                            ->options(Driver::class)
                            ->required()
                            ->disabledOn('edit')
                            ->live()
                            ->afterStateUpdated(function () use ($config) {
                                $config->clearCachedChildSchemas();
                                $config->getChildSchemas()['default']->fill();
                            }),
                        Select::make('adapter')
                            ->label(__('phpinnacle-ferry::resources.upstream.fields.adapter'))
                            ->placeholder(__('phpinnacle-ferry::resources.upstream.placeholders.adapter'))
                            ->options(fn (StructureAdapterRegistry $adapters) => $adapters->options())
                            ->disabledOn('edit'),
                    ]),
                $config,
                Section::make(__('phpinnacle-ferry::resources.upstream.sections.structure'))
                    ->icon('phosphor-tree-structure')
                    ->columns(4)
                    ->visibleOn('edit')
                    ->headerActions([
                        PrepareStructureAction::form(),
                    ])
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('phpinnacle-ferry::resources.upstream.fields.status'))
                            ->state(fn (Upstream $record) => $record->structure->status)
                            ->badge(),
                        TextEntry::make('objects')
                            ->label(__('phpinnacle-ferry::resources.upstream.fields.objects'))
                            ->state(fn (Upstream $record) => $record->structure->publishedItems()->count())
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('progress')
                            ->label(__('phpinnacle-ferry::resources.upstream.fields.progress'))
                            ->state(fn (Upstream $record) => sprintf(
                                '%d / %d',
                                $record->structure->processed,
                                $record->structure->estimated,
                            ))
                            ->visible(fn (Upstream $record) => $record->structure->estimated > 0),
                    ]),
            ]);
    }
}
