<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Schemas;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Common\Concerns\FormSlug;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Data\FieldMapping as SchemaMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Forms\FieldBinding;
use PHPinnacle\Ferry\Forms\FieldMapping;
use PHPinnacle\Ferry\Forms\FieldTransforms;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Rosetta\Enums\MetadataKind;

/**
 * @phpstan-import-type TransformInput from TransformRegistry
 *
 * @phpstan-type SchemaState array{sources: array<string, array{transforms?: list<TransformRow>}>, bindings: array<string, string|array{value: string, type: string}>}
 * @phpstan-type StaticState array{sources: array<string, array{transforms?: list<TransformRow>}>, targets: array<string, array{kind: 'source', source: string}|array{kind: 'value', value: string}>}
 * @phpstan-type SettingState array{key: string, params: array<string, string>}
 * @phpstan-type TransformRow array{key: string, params?: array<string, string>}
 * @phpstan-type CreateState array{name: string, connection_id: string, code: string, source: string, destination: string, schema: SchemaState|StaticState, transforms?: array<array-key, TransformRow>}
 * @phpstan-type EditState array{name: string, schema: SchemaState|StaticState, transforms?: array<array-key, TransformRow>}
 * @phpstan-type FillMapping array{source: string, column: string, transforms?: list<SettingState>}|array{source: null, column: string, constant: array{type: value-of<ColumnType>, value: string}}
 * @phpstan-type FillState array{type: value-of<DestinationType>, destination: string, schema: list<FillMapping>, transforms: list<SettingState>}
 */
class SyncForm
{
    /**
     * @param FillState $data
     * @return array<string, mixed>
     */
    public static function fill(array $data): array
    {
        $schema = [];
        $fieldTransforms = [];
        $isStatic = $data['type'] === DestinationType::Static->value;

        foreach ($data['schema'] as $mapping) {
            $settings = $mapping['transforms'] ?? [];
            if ($mapping['source'] !== null && $settings !== []) {
                $fieldTransforms[$mapping['source']] = $settings;
            }

            if ($isStatic) {
                $schema[$mapping['column']] = $mapping['source'] === null
                    ? ['kind' => 'value', 'value' => $mapping['constant']['value']]
                    : ['kind' => 'source', 'source' => $mapping['source']];
                continue;
            }

            if ($mapping['source'] === null) {
                $schema[$mapping['column']] = $mapping['constant'];

                continue;
            }
            $schema[$mapping['source']] = $mapping['column'];
        }

        $data['schema'] = [
            'sources' => array_map(static fn (array $settings) => ['transforms' => $settings], $fieldTransforms),
            $isStatic ? 'targets' : 'bindings' => $schema,
        ];
        $data['destination'] = $isStatic ? 'static:' . $data['destination'] : DestinationType::Dynamic->value;

        return $data;
    }

    /**
     * @param CreateState $data
     * @return array{connection_id: string, name: string, code: string, source: string, type: DestinationType, destination: string|null, schema: list<SchemaMapping>, transforms: list<TransformSetting>}
     */
    public static function forCreate(
        array $data,
        DestinationFactory $destinations,
        TransformRegistry $registry,
    ): array {
        $object = self::object(Connection::query()->find($data['connection_id']), $data['source'], 'data.source');
        $key = self::staticKey($data['destination']);
        $destination = $key !== null ? $destinations->getOrFail($key) : null;

        return [
            'connection_id' => $data['connection_id'],
            'name' => $data['name'],
            'code' => $data['code'],
            'source' => $data['source'],
            'type' => $destination === null ? DestinationType::Dynamic : DestinationType::Static,
            'destination' => $destination?->key,
            'schema' => self::mappings($object, $data, $destination, $registry),
            'transforms' => SyncSchema::fromTransforms(
                self::settings($data['transforms'] ?? []),
                $registry,
            ),
        ];
    }

    /**
     * @param EditState $data
     * @return array{name: string, schema: list<SchemaMapping>, transforms: list<TransformSetting>}
     */
    public static function forUpdate(
        array $data,
        Sync $record,
        DestinationFactory $destinations,
        TransformRegistry $registry,
    ): array {
        $object = self::object($record->connection, $record->source, 'data.schema');
        $destination = $record->type === DestinationType::Static ? $destinations->get($record->destination) : null;

        if ($record->type === DestinationType::Static && $destination === null) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', [
                    'destination' => $record->destination,
                ]),
            ]);
        }

        return [
            'name' => $data['name'],
            'schema' => self::mappings($object, $data, $destination, $registry),
            'transforms' => SyncSchema::fromTransforms(
                self::settings($data['transforms'] ?? []),
                $registry,
            ),
        ];
    }

    /**
     * @param CreateState|EditState $data
     * @return list<SchemaMapping>
     */
    private static function mappings(
        ConnectionMetadata $object,
        array $data,
        ?StaticDestination $destination,
        TransformRegistry $registry,
    ): array {
        $fields = array_column($destination->fields ?? [], null, 'id');
        $bindings = [];
        $constants = [];
        $fieldTransforms = [];

        if ($destination !== null) {
            /** @var StaticState $schema */
            $schema = $data['schema'];
            foreach ($schema['targets'] as $column => $entry) {
                if ($entry['kind'] === 'value') {
                    $constants[$column] = ['type' => $fields[$column]->type->value, 'value' => $entry['value']];

                    continue;
                }
                $bindings[$entry['source']] = $column;
            }
        } else {
            /** @var SchemaState $schema */
            $schema = $data['schema'];
            foreach ($schema['bindings'] as $key => $value) {
                if (is_array($value)) {
                    $constants[$key] = ['type' => $value['type'], 'value' => $value['value']];

                    continue;
                }
                $bindings[$key] = $value;
            }
        }
        foreach (array_keys($bindings) as $source) {
            $fieldTransforms[$source] = self::settings($schema['sources'][$source]['transforms'] ?? []);
        }

        return SyncSchema::fromBindings(
            $object,
            $bindings,
            $registry,
            $destination,
            ['fields' => $fieldTransforms, 'constants' => $constants],
        );
    }

    /**
     * @param array<array-key, TransformRow> $rows
     * @return list<TransformSetting>
     */
    private static function settings(array $rows): array
    {
        return array_values(array_map(
            TransformSetting::fromArray(...),
            $rows,
        ));
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

    public static function configure(Schema $schema): Schema
    {
        $sourceOptionsCache = [];

        $resolveSourceOptions = function (?string $connectionId) use (&$sourceOptionsCache) {
            return $sourceOptionsCache[$connectionId ?? ''] ??= self::sourceOptions($connectionId);
        };

        return $schema
            ->columns(1)
            ->components([
                Section::make(__('phpinnacle-ferry::resources.sync.sections.general'))
                    ->columns(3)
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
                            ->maxLength(255)
                            ->scopedUnique(ignoreRecord: true)
                            ->required()
                            ->live(onBlur: true)
                            ->disabledOn('edit')
                            ->hint(function (?string $state, Get $get, DestinationFactory $destinations) {
                                $key = self::staticKey($get->string('destination', isNullable: true));
                                $table = $key !== null
                                    ? $destinations->get($key)->table ?? ''
                                    : Config::string('phpinnacle-ferry.sync.table_prefix') . ($state ?? '');

                                return sprintf(
                                    '%s: %s',
                                    __('phpinnacle-ferry::resources.sync.fields.destination'),
                                    $table,
                                );
                            }),
                        Select::make('connection_id')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.connection'))
                            ->options(self::connectionOptions(...))
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(function (Select $component, Set $set) {
                                $set('source', null);
                                self::resetSource($set, $component);
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
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(self::resetSource(...)),
                        Select::make('destination')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.destination'))
                            ->options(self::destinationOptions(...))
                            ->default(DestinationType::Dynamic->value)
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(self::resetMapping(...)),
                    ]),
                self::mappingSection(),
            ]);
    }

    private static function mappingSection(): Group
    {
        return Group::make()
            ->schema(function (
                Get $get,
                ?Sync $record,
                DestinationFactory $destinations,
                TransformRegistry $registry,
            ) {
                $connectionId = $get->string('connection_id', isNullable: true);
                $source = $get->string('source', isNullable: true);
                $selectedDestination = $get->string('destination', isNullable: true);
                $object =
                    $connectionId !== null && $source !== null
                        ? Connection::query()->find($connectionId)?->publishedObject($source)
                        : null;
                $destination = $destinations->get(self::staticKey($selectedDestination));
                $brokenColumns = $record?->brokenColumns($object, $destination?->fields) ?? [];

                $targetWarnings = [];
                $sourceWarnings = [];

                foreach ($brokenColumns as $column) {
                    $targetWarnings[$column] = __(
                        'phpinnacle-ferry::resources.sync.values.broken_mappings',
                        ['columns' => $column],
                    );
                }

                foreach ($record->schema ?? [] as $mapping) {
                    if ($mapping->source !== null && array_key_exists($mapping->column, $targetWarnings)) {
                        $sourceWarnings[$mapping->source] = $targetWarnings[$mapping->column];
                    }
                }

                $sourceSchema = self::sourceSchema($object);

                $mapping = match ($selectedDestination) {
                    DestinationType::Dynamic->value => self::dynamicMapping($object, $sourceWarnings),
                    null => null,
                    default => self::staticMapping($object, $destination, $sourceWarnings, $targetWarnings),
                };

                if ($mapping === null) {
                    return [];
                }

                $mapping->sourceSettings(fn (string $source) => [
                    FieldTransforms::make('transforms')
                        ->label($sourceSchema['properties'][$source]['title'])
                        ->object($object)
                        ->inputSchema($sourceSchema['properties'][$source]),
                ]);

                return [
                    TransformsBuilder::make(TransformScope::Record, $object, $registry)
                        ->label(__('phpinnacle-ferry::resources.sync.fields.transforms'))
                        ->columnSpanFull(),
                    $mapping,
                ];
            });
    }

    /** @param array<string, string> $warnings */
    private static function dynamicMapping(?ConnectionMetadata $object, array $warnings): FieldBinding
    {
        return FieldBinding::make('schema')
            ->warnings($warnings)
            ->label(__('phpinnacle-ferry::resources.sync.fields.schema'))
            ->jsonSchema(self::sourceSchema($object))
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
            ->constants(self::constantFields(...))
            ->rule(fn (FieldBinding $component) => function (string $attribute, mixed $value, Closure $fail) use (
                $component,
            ) {
                $bindings = $component->getBindingValues();
                SyncSchema::validateColumns(array_map(
                    static fn (mixed $binding, int|string $source) => is_array($binding) ? $source : $binding,
                    $bindings,
                    array_keys($bindings),
                ), $fail);
            })
            ->columnSpanFull();
    }

    /** @return list<Grid> */
    private static function constantFields(): array
    {
        return [
            Grid::make(3)->schema([
                TextInput::make('column')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.target_column'))
                    ->maxLength(255)
                    ->required(),
                Select::make('type')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.constant_type'))
                    ->options(ColumnType::castableOptions())
                    ->default(ColumnType::String->value)
                    ->required(),
                TextInput::make('value')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                    ->required(),
            ]),
        ];
    }

    /**
     * @param array<string, string> $sourceWarnings
     * @param array<string, string> $targetWarnings
     */
    private static function staticMapping(
        ?ConnectionMetadata $object,
        ?StaticDestination $destination,
        array $sourceWarnings,
        array $targetWarnings,
    ): FieldMapping {
        $fields = array_column($destination->fields ?? [], null, 'id');

        return FieldMapping::make('schema')
            ->canConnectUsing(
                fn (JsonSchema $source, JsonSchema $target) => (
                    $source->annotation('format') === $target->annotation('format')
                ),
            )
            ->warnings(source: $sourceWarnings, dest: $targetWarnings)
            ->label(__('phpinnacle-ferry::resources.sync.fields.schema'))
            ->required()
            ->jsonSchema(
                source: self::sourceSchema($object, withKey: $destination === null),
                dest: [
                    'type' => 'object',
                    'properties' => array_map(static fn (DestinationField $field) => [
                        'title' => $field->label,
                        ...$field->type->jsonSchema(),
                    ], $fields),
                    'required' => array_column(
                        array_filter($fields, static fn (DestinationField $field) => $field->required),
                        'id',
                    ),
                ],
            )
            ->outputSchema(function (FieldMapping $component, TransformRegistry $registry) use ($object, $destination) {
                /** @var array<string, array<array-key, TransformInput>> $fieldTransforms */
                $fieldTransforms = array_map(
                    static fn (array $settings) => $settings['transforms'] ?? [],
                    $component->getSourceSettings(),
                );
                $schema = self::sourceSchema($object, withKey: $destination === null);
                foreach ($schema['properties'] as $source => $property) {
                    $schema['properties'][$source] = $registry
                        ->resultSchema(
                            JsonSchema::fromArray($property),
                            $fieldTransforms[$source] ?? [],
                        )
                        ?->toArray() ?? [];
                }

                return $schema;
            })
            ->labels(
                source: __('phpinnacle-ferry::resources.sync.fields.source_title'),
                dest: __('phpinnacle-ferry::resources.sync.fields.target_column'),
            )
            ->columnSpanFull();
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

    private static function staticKey(?string $destination): ?string
    {
        return $destination === null || $destination === DestinationType::Dynamic->value
            ? null
            : substr($destination, strlen('static:'));
    }

    private static function resetMapping(Set $set, Select $component): void
    {
        $set('schema', []);
        $component->getRootContainer()->clearCachedChildSchemas();
    }

    private static function resetSource(Set $set, Select $component): void
    {
        $set('transforms', []);
        self::resetMapping($set, $component);
    }

    /** @return array{type: 'object', properties: array<string, array{title: string, type: string, format?: string}>} */
    private static function sourceSchema(?ConnectionMetadata $object, bool $withKey = true): array
    {
        $properties = [];
        foreach ($object?->mappableFields(withKey: $withKey) ?? [] as $field) {
            $properties[$field['source']] = [
                'title' => sprintf('%s (%s)', $field['title'], $field['physical']),
                ...$field['type']->jsonSchema(),
            ];
        }

        return ['type' => 'object', 'properties' => $properties];
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
        $options = [DestinationType::Dynamic->value => DestinationType::Dynamic->getLabel()];

        foreach ($destinations->all() as $destination) {
            $options['static:' . $destination->key] = $destination->getLabel();
        }

        return $options;
    }
}
