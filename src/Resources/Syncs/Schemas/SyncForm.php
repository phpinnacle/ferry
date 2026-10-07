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
use Illuminate\Validation\ValidationException;
use PHPinnacle\Common\Concerns\FormSlug;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Data\FieldMapping as SchemaMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Forms\FieldBinding;
use PHPinnacle\Ferry\Forms\FieldMapping;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Rosetta\Enums\MetadataKind;

/**
 * @phpstan-type MappingState array{schema: array<string, string>}|array{static_mapping: array<string, string>}
 * @phpstan-type CreateState array{name: string, connection_id: string, code: string, source: string, static_destination?: string|null, schema: array<string, string>}|array{name: string, connection_id: string, code: string, source: string, static_destination: string, static_mapping: array<string, string>}
 * @phpstan-type EditState array{name: string, schema: array<string, string>}|array{name: string, static_mapping: array<string, string>}
 */
class SyncForm
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function fill(array $data): array
    {
        /** @var list<array{source: string, column: string}> $schema */
        $schema = $data['schema'];
        $data['schema'] = array_column($schema, 'column', 'source');
        $data['static_mapping'] = $data['schema'];

        return $data;
    }

    /**
     * @param CreateState $data
     * @return array{connection_id: string, name: string, code: string, source: string, static_destination: string|null, schema: list<SchemaMapping>}
     */
    public static function forCreate(array $data, DestinationFactory $destinations): array
    {
        $object = self::object(Connection::query()->find($data['connection_id']), $data['source'], 'data.source');
        $staticKey = $data['static_destination'] ?? null;
        $staticKey = $staticKey !== '' ? $staticKey : null;
        $destination = $staticKey !== null ? $destinations->getOrFail($staticKey) : null;

        return [
            'connection_id' => $data['connection_id'],
            'name' => $data['name'],
            'code' => $data['code'],
            'source' => $data['source'],
            'static_destination' => $staticKey,
            'schema' => self::mappings($object, $data, $destination),
        ];
    }

    /**
     * @param EditState $data
     * @return array{name: string, schema: list<SchemaMapping>}
     */
    public static function forUpdate(array $data, Sync $record, DestinationFactory $destinations): array
    {
        $object = self::object($record->connection, $record->source, 'data.schema');
        $destination = $destinations->get($record->static_destination);

        if ($record->static_destination !== null && $destination === null) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', [
                    'destination' => $record->static_destination,
                ]),
            ]);
        }

        return ['name' => $data['name'], 'schema' => self::mappings($object, $data, $destination)];
    }

    private static function object(?Connection $connection, string $source, string $attribute): ConnectionMetadata
    {
        $object = $connection?->publishedObject($source);

        if ($object === null) {
            throw ValidationException::withMessages([
                $attribute => __('phpinnacle-ferry::resources.sync.errors.unknown_object'),
            ]);
        }

        return $object;
    }

    /**
     * @param MappingState $data
     * @return list<SchemaMapping>
     */
    private static function mappings(ConnectionMetadata $object, array $data, ?StaticDestination $destination): array
    {
        $bindings = array_key_exists('static_mapping', $data) ? $data['static_mapping'] : $data['schema'];

        return SyncSchema::fromBindings($object, $bindings, $destination);
    }

    public static function configure(Schema $schema): Schema
    {
        $objectCache = [];

        $resolveObject = function (Get $get) use (&$objectCache) {
            $connectionId = $get->string('connection_id', isNullable: true);
            $source = $get->string('source', isNullable: true);

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

        $resolveBrokenColumns = function (?Sync $record, DestinationFactory $destinations) use (&$brokenColumns) {
            return $brokenColumns ??= $record?->brokenColumns(
                $record->connection->publishedObject($record->source),
                $destinations->get($record->static_destination)?->fields,
            ) ?? [];
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
                            ->hint(function (?string $state, Get $get, DestinationFactory $destinations) {
                                $staticKey = self::staticKey($get);

                                if ($staticKey !== null) {
                                    $destination = $destinations->get($staticKey);

                                    return sprintf(
                                        '%s: %s',
                                        __('phpinnacle-ferry::resources.sync.fields.destination'),
                                        $destination->table ?? '',
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
     * @param Closure(?Sync, DestinationFactory): list<string> $resolveBrokenColumns
     * @param Closure(Get): ?ConnectionMetadata $resolveObject
     */
    private static function mappingSection(Closure $resolveBrokenColumns, Closure $resolveObject): Section
    {
        return Section::make(__('phpinnacle-ferry::resources.sync.sections.mapping'))
            ->schema([
                TextEntry::make('broken_mappings')
                    ->hiddenLabel()
                    ->state(fn (?Sync $record, DestinationFactory $destinations) => __(
                        'phpinnacle-ferry::resources.sync.values.broken_mappings',
                        ['columns' => implode(', ', $resolveBrokenColumns($record, $destinations))],
                    ))
                    ->visible(
                        fn (?Sync $record, DestinationFactory $destinations) => (
                            $resolveBrokenColumns($record, $destinations) !== []
                        ),
                    )
                    ->color('warning')
                    ->icon('phosphor-warning-circle')
                    ->columnSpanFull(),
                FieldBinding::make('schema')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.schema'))
                    ->visible(fn (Get $get) => self::staticKey($get) === null)
                    ->options(
                        fn (Get $get) => self::sourceFields($resolveObject($get)),
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
                    ->formatStateUsing(self::flipMapping(...))
                    ->dehydrateStateUsing(self::flipMapping(...))
                    ->label(__('phpinnacle-ferry::resources.sync.fields.schema'))
                    ->visible(fn (Get $get) => self::staticKey($get) !== null)
                    ->required()
                    ->options(
                        source: fn (Get $get, DestinationFactory $destinations) => self::sourceFields(
                            $resolveObject($get),
                            $destinations->get(self::staticKey($get)),
                        ),
                        dest: fn (Get $get, DestinationFactory $destinations) => self::destinationFieldOptions(
                            $destinations->get(self::staticKey($get)),
                        ),
                    )
                    ->types(
                        source: fn (Get $get) => self::sourceTypes($resolveObject($get)),
                        dest: fn (Get $get, DestinationFactory $destinations) => self::destinationFieldTypes(
                            $destinations->get(self::staticKey($get)),
                        ),
                    )
                    ->requiredTargets(
                        fn (Get $get, DestinationFactory $destinations) => self::requiredDestinationFields(
                            $destinations->get(self::staticKey($get)),
                        ),
                    )
                    ->labels(
                        source: __('phpinnacle-ferry::resources.sync.fields.source_title'),
                        dest: __('phpinnacle-ferry::resources.sync.fields.target_column'),
                    )
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @param array<string, string>|null $state
     * @return array<string, string>
     */
    private static function flipMapping(?array $state): array
    {
        return array_flip($state ?? []);
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

    private static function staticKey(Get $get): ?string
    {
        $key = $get->string('static_destination', isNullable: true);

        return $key !== '' ? $key : null;
    }

    private static function resetMapping(Set $set): void
    {
        $set('schema', []);
        $set('static_mapping', []);
    }

    /** @return array<string, string> */
    private static function sourceFields(?ConnectionMetadata $object, ?StaticDestination $destination = null): array
    {
        $fields = [];

        foreach ($object?->mappableFields(withKey: $destination === null) ?? [] as $field) {
            $fields[$field['source']] = sprintf('%s (%s)', $field['title'], $field['physical']);
        }

        return $fields;
    }

    /** @return array<string, string> */
    private static function sourceTypes(?ConnectionMetadata $object): array
    {
        return array_map(
            static fn (ColumnType $type) => $type->value,
            array_column($object?->mappableFields(withKey: false) ?? [], 'type', 'source'),
        );
    }

    /** @return array<string, string> */
    private static function destinationFieldTypes(?StaticDestination $destination): array
    {
        return array_map(
            static fn (DestinationField $field) => $field->type->value,
            array_column($destination->fields ?? [], null, 'id'),
        );
    }

    /** @return list<string> */
    private static function requiredDestinationFields(?StaticDestination $destination): array
    {
        return array_column(
            array_filter($destination->fields ?? [], static fn (DestinationField $field) => $field->required),
            'id',
        );
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

    /** @return array<string, string|\Illuminate\Contracts\Support\Htmlable|null> */
    private static function destinationOptions(DestinationFactory $destinations): array
    {
        return array_map(
            static fn (StaticDestination $destination) => $destination->getLabel(),
            $destinations->all(),
        );
    }

    /** @return array<string, string> */
    private static function destinationFieldOptions(?StaticDestination $destination): array
    {
        return array_column($destination->fields ?? [], 'label', 'id');
    }
}
