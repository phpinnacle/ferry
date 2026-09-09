<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Schemas;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Common\Concerns\FormSlug;
use PHPinnacle\Ferry\Contracts\StaticDestination;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Forms\FieldBinding;
use PHPinnacle\Ferry\Forms\FieldMapping;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\StaticDestinationRegistry;
use PHPinnacle\Ferry\Services\SyncReviewer;
use PHPinnacle\Rosetta\Enums\MetadataKind;

class SyncForm
{
    public static function configure(Schema $schema): Schema
    {
        $objectCache = [];

        $resolveObject = function (?string $connectionId, ?string $source) use (&$objectCache) {
            if ($connectionId === null || $source === null) {
                return null;
            }

            $key = $connectionId . ':' . $source;

            if (array_key_exists($key, $objectCache)) {
                return $objectCache[$key];
            }

            $connection = Connection::query()->find($connectionId);

            return $objectCache[$key] = $connection?->publishedObject($source);
        };

        $sourceOptionsCache = [];

        $resolveSourceOptions = function (?string $connectionId) use (&$sourceOptionsCache) {
            return $sourceOptionsCache[$connectionId ?? ''] ??= self::sourceOptions($connectionId);
        };

        $brokenColumns = null;

        $resolveBrokenColumns = function (?Sync $record, SyncReviewer $reviewer) use (&$brokenColumns) {
            return $brokenColumns ??= self::brokenColumns($record, $reviewer);
        };

        return $schema
            ->columns(1)
            ->components([
                Section::make(__('phpinnacle-ferry::resources.sync.sections.general'))
                    ->columns(4)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.name'))
                            ->columnSpan(2)
                            ->maxLength(255)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(FormSlug::make('code')),
                        TextInput::make('code')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.code'))
                            ->columnSpan(2)
                            ->maxLength(255)
                            ->scopedUnique(ignoreRecord: true)
                            ->required()
                            ->live(onBlur: true)
                            ->disabledOn('edit')
                            ->hint(function (?string $state, Get $get, StaticDestinationRegistry $destinations) {
                                $staticKey = self::staticKey($get);

                                if ($staticKey !== null) {
                                    $destination = self::destination(
                                        $destinations,
                                        $staticKey,
                                    );

                                    return sprintf(
                                        '%s: %s',
                                        __('phpinnacle-ferry::resources.sync.fields.destination'),
                                        $destination?->table() ?? '',
                                    );
                                }

                                return sprintf(
                                    '%s: %s%s',
                                    __('phpinnacle-ferry::resources.sync.fields.destination'),
                                    Config::string('phpinnacle-ferry.sync.table_prefix'),
                                    $state ?? '',
                                );
                            }),
                        Select::make('connection_id')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.connection'))
                            ->options(self::connectionOptions(...))
                            ->columnSpan(2)
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(function (Set $set) {
                                $set('source', null);
                                self::resetMapping($set);
                            }),
                        Select::make('source')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.source'))
                            ->options(
                                fn (Get $get) => $resolveSourceOptions($get->string(
                                    'connection_id',
                                    isNullable: true,
                                )),
                            )
                            ->placeholder(__('phpinnacle-ferry::resources.sync.values.no_objects'))
                            ->columnSpan(2)
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(self::resetMapping(...)),
                        Select::make('static_destination')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.static_destination'))
                            ->placeholder(__('phpinnacle-ferry::resources.sync.destination_types.dynamic'))
                            ->selectablePlaceholder()
                            ->options(self::destinationOptions(...))
                            ->columnSpan(4)
                            ->searchable()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateHydrated(fn (Select $component, ?Sync $record) => $component->state(
                                $record?->static_destination,
                            ))
                            ->afterStateUpdated(self::resetMapping(...)),
                    ]),
                self::mappingSection($resolveBrokenColumns, $resolveObject),
            ]);
    }

    /**
     * @param Closure(?Sync, SyncReviewer): list<string> $resolveBrokenColumns
     * @param Closure(?string, ?string): ?ConnectionMetadata $resolveObject
     */
    private static function mappingSection(Closure $resolveBrokenColumns, Closure $resolveObject): Section
    {
        return Section::make(__('phpinnacle-ferry::resources.sync.sections.mapping'))
            ->schema([
                TextEntry::make('broken_mappings')
                    ->hiddenLabel()
                    ->state(fn (?Sync $record, SyncReviewer $reviewer) => __(
                        'phpinnacle-ferry::resources.sync.values.broken_mappings',
                        ['columns' => implode(', ', $resolveBrokenColumns($record, $reviewer))],
                    ))
                    ->visible(
                        fn (?Sync $record, SyncReviewer $reviewer) => $resolveBrokenColumns($record, $reviewer) !== [],
                    )
                    ->color('warning')
                    ->icon('phosphor-warning-circle')
                    ->columnSpanFull(),
                FieldBinding::make('schema')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.schema'))
                    ->visible(fn (Get $get) => self::staticKey($get) === null)
                    ->options(
                        fn (Get $get) => self::sourceFields($resolveObject(
                            $get->string('connection_id', isNullable: true),
                            $get->string('source', isNullable: true),
                        )),
                    )
                    ->labels(
                        source: __('phpinnacle-ferry::resources.sync.fields.source_title'),
                        dest: __('phpinnacle-ferry::resources.sync.fields.target_column'),
                    )
                    ->simple(
                        TextInput::make('column')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.target_column'))
                            ->maxLength(255)
                            ->required(),
                    )
                    ->rule(
                        fn (FieldBinding $component) => function (string $attribute, mixed $value, Closure $fail) use (
                            $component,
                        ) {
                            if (!is_array($value)) {
                                return;
                            }

                            $rows = [];

                            foreach ($component->getSources() as $source) {
                                $binding = $value[$component->getBindingKey($source['id'])] ?? null;

                                if (is_array($binding)) {
                                    $rows[] = ['source' => $source['id'], 'column' => $binding['column'] ?? null];
                                }
                            }

                            new SyncSchema()->validate($attribute, $rows, $fail);
                        },
                    )
                    ->columnSpanFull(),
                FieldMapping::make('static_mapping')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.schema'))
                    ->visible(fn (Get $get) => self::staticKey($get) !== null)
                    ->required()
                    ->options(
                        source: fn (Get $get, StaticDestinationRegistry $destinations) => self::sourceFields(
                            $resolveObject(
                                $get->string('connection_id', isNullable: true),
                                $get->string('source', isNullable: true),
                            ),
                            self::destination($destinations, self::staticKey($get)),
                        ),
                        dest: fn (Get $get, StaticDestinationRegistry $destinations) => self::destinationFieldOptions(
                            self::destination($destinations, self::staticKey($get)),
                        ),
                    )
                    ->types(
                        source: fn (Get $get) => self::sourceTypes($resolveObject(
                            $get->string('connection_id', isNullable: true),
                            $get->string('source', isNullable: true),
                        )),
                        dest: fn (Get $get, StaticDestinationRegistry $destinations) => self::destinationFieldTypes(
                            self::destination($destinations, self::staticKey($get)),
                        ),
                    )
                    ->requiredTargets(
                        fn (Get $get, StaticDestinationRegistry $destinations) => self::requiredDestinationFields(
                            self::destination($destinations, self::staticKey($get)),
                        ),
                    )
                    ->labels(
                        source: __('phpinnacle-ferry::resources.sync.fields.source_title'),
                        dest: __('phpinnacle-ferry::resources.sync.fields.target_column'),
                    )
                    ->columnSpanFull(),
            ]);
    }

    /** @return list<string> */
    private static function brokenColumns(?Sync $record, SyncReviewer $reviewer): array
    {
        if (!$record instanceof Sync) {
            return [];
        }

        $object = $record->connection->publishedObject($record->source);

        if (!$object instanceof ConnectionMetadata) {
            return array_map(static fn ($mapping) => $mapping->column, $record->schema);
        }

        return $reviewer->brokenColumns($record, $object);
    }

    /** @return array<string, string> */
    private static function connectionOptions(): array
    {
        /** @var array<string, string> */
        return Connection::query()
            ->where('is_active', true)
            ->where('status', StructureStatus::Ready->value)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function destination(StaticDestinationRegistry $destinations, ?string $key): ?StaticDestination
    {
        return $key !== null ? $destinations->get($key) : null;
    }

    private static function staticKey(Get $get): ?string
    {
        $key = $get->string('static_destination', isNullable: true);

        return $key === '' ? null : $key;
    }

    private static function resetMapping(Set $set): void
    {
        $set('schema', []);
        $set('static_mapping', []);
    }

    /** @return array<string, string> */
    private static function sourceFields(?ConnectionMetadata $object, ?StaticDestination $destination = null): array
    {
        if (!$object instanceof ConnectionMetadata) {
            return [];
        }

        $fields = [];

        foreach ($object->mappableFields(withKey: $destination === null) as $field) {
            $fields[$field['source']] = sprintf('%s (%s)', $field['title'], $field['physical']);
        }

        return $fields;
    }

    /** @return array<string, string> */
    private static function sourceTypes(?ConnectionMetadata $object): array
    {
        if (!$object instanceof ConnectionMetadata) {
            return [];
        }

        $types = [];

        foreach ($object->mappableFields(withKey: false) as $field) {
            $types[$field['source']] = $field['type']->value;
        }

        return $types;
    }

    /** @return array<string, string> */
    private static function destinationFieldTypes(?StaticDestination $destination): array
    {
        if ($destination === null) {
            return [];
        }

        $types = [];

        foreach ($destination->fields() as $field) {
            $types[$field->id] = $field->type->value;
        }

        return $types;
    }

    /** @return list<string> */
    private static function requiredDestinationFields(?StaticDestination $destination): array
    {
        if ($destination === null) {
            return [];
        }

        $required = [];

        foreach ($destination->fields() as $field) {
            if ($field->required) {
                $required[] = $field->id;
            }
        }

        return $required;
    }

    /** @return array<string, string> */
    private static function sourceOptions(?string $connectionId): array
    {
        $connection = $connectionId !== null ? Connection::query()->find($connectionId) : null;

        if (!$connection instanceof Connection) {
            return [];
        }

        return $connection
            ->publishedMetadata()
            ->select(['external_id', 'kind', 'title', 'label', 'name'])
            ->get()
            ->sort(static function (ConnectionMetadata $a, ConnectionMetadata $b) {
                $byRank = self::sourceRank($a) <=> self::sourceRank($b);

                if ($byRank !== 0) {
                    return $byRank;
                }

                return strnatcmp(mb_strtolower($a->displayTitle()), mb_strtolower($b->displayTitle()));
            })
            ->mapWithKeys(static fn (ConnectionMetadata $object) => [$object->external_id => $object->displayTitle()])
            ->all();
    }

    private static function sourceRank(ConnectionMetadata $object): int
    {
        return match (true) {
            str_starts_with($object->displayTitle(), '(') => 2,
            $object->kind === MetadataKind::Reference => 0,
            default => 1,
        };
    }

    /** @return array<string, string> */
    private static function destinationOptions(StaticDestinationRegistry $destinations): array
    {
        return array_map(
            static fn (StaticDestination $destination) => $destination->getLabel(),
            $destinations->all(),
        );
    }

    /** @return array<string, string> */
    private static function destinationFieldOptions(?StaticDestination $destination): array
    {
        if ($destination === null) {
            return [];
        }

        $options = [];

        foreach ($destination->fields() as $field) {
            $options[$field->id] = $field->label;
        }

        return $options;
    }
}
