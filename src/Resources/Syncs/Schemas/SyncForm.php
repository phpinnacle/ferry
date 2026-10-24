<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Schemas;

use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
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
 * @phpstan-import-type MappingData from SchemaMapping
 * @phpstan-type SchemaState list<MappingData>
 * @phpstan-type CreateState array{name: string, connection_id: string, code: string, source: string, destination: string, schema: SchemaState}
 * @phpstan-type EditState array{name: string, schema: SchemaState}
 * @phpstan-type FillState array{connection_id: string, source: string, type: value-of<DestinationType>, destination: string, schema: SchemaState}
 */
class SyncForm
{
    /**
     * @param FillState $data
     * @return array<string, mixed>
     */
    public static function fill(array $data): array
    {
        $isStatic = $data['type'] === DestinationType::Static->value;
        $data['destination'] = $isStatic ? 'static:' . $data['destination'] : DestinationType::Dynamic->value;
        $object = Connection::query()->find($data['connection_id'])?->publishedObject($data['source']);
        $data['schema'] = self::mapSources(
            $data['schema'],
            array_column($object?->mappableFields() ?? [], 'physical', 'source'),
        );

        return $data;
    }

    /**
     * @param CreateState $data
     * @return array{connection_id: string, name: string, code: string, source: string, type: DestinationType, destination: string|null, schema: SchemaState}
     */
    public static function forCreate(
        array $data,
        DestinationFactory $destinations,
    ): array {
        $object = self::object(Connection::query()->find($data['connection_id']), $data['source'], 'data.source');
        $key = self::staticKey($data['destination']);
        $destination = $key !== null ? $destinations->getOrFail($key) : null;

        $mappings = self::mapSources($data['schema'], array_column($object->mappableFields(), 'source', 'physical'));
        SyncSchema::assertValid(array_map(SchemaMapping::fromArray(...), $mappings), $destination);

        return [
            'connection_id' => $data['connection_id'],
            'name' => $data['name'],
            'code' => $data['code'],
            'source' => $data['source'],
            'type' => $destination === null ? DestinationType::Dynamic : DestinationType::Static,
            'destination' => $destination?->key,
            'schema' => $mappings,
        ];
    }

    /**
     * @param EditState $data
     * @return array{name: string, schema: SchemaState}
     */
    public static function forUpdate(
        array $data,
        Sync $record,
        DestinationFactory $destinations,
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

        $mappings = self::mapSources($data['schema'], array_column($object->mappableFields(), 'source', 'physical'));
        SyncSchema::assertValid(array_map(SchemaMapping::fromArray(...), $mappings), $destination);

        return [
            'name' => $data['name'],
            'schema' => $mappings,
        ];
    }

    /**
     * @param SchemaState $mappings
     * @param array<string, string> $sources
     * @return SchemaState
     */
    private static function mapSources(array $mappings, array $sources): array
    {
        foreach ($mappings as &$mapping) {
            if ($mapping['kind'] === 'source') {
                $mapping['source'] = $sources[$mapping['source']] ?? $mapping['source'];
            }
        }

        return $mappings;
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
                            ->default(fn (Select $component) => array_key_first($component->getOptions()))
                            ->native()
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(function (Select $component, Get $get, Set $set) use (
                                $resolveSourceOptions,
                            ) {
                                $set(
                                    'source',
                                    array_key_first($resolveSourceOptions($get->string(
                                        'connection_id',
                                        isNullable: true,
                                    ))),
                                );
                                self::resetMapping($set, $component);
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
                            ->default(fn (Select $component) => array_key_first($component->getOptions()))
                            ->native()
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(self::resetMapping(...)),
                        Select::make('destination')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.destination'))
                            ->options(self::destinationOptions(...))
                            ->default(DestinationType::Dynamic->value)
                            ->native()
                            ->selectablePlaceholder(false)
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
                    if ($mapping->source !== null) {
                        $warning = $mapping->target ?? $mapping->source;
                        if (array_key_exists($warning, $targetWarnings)) {
                            $sourceWarnings[$object?->physicalColumn($mapping->source) ?? $mapping->source] =
                                $targetWarnings[$warning];
                        }
                    }
                }

                $sourceSchema = self::sourceSchema($object);
                $sourceIds = array_column($object?->mappableFields() ?? [], 'source', 'physical');

                $mapping = match ($selectedDestination) {
                    DestinationType::Dynamic->value => self::dynamicMapping($object, $sourceWarnings),
                    null => null,
                    default => self::staticMapping($object, $destination, $sourceWarnings, $targetWarnings),
                };

                if ($mapping === null) {
                    return [];
                }

                $mapping->sourceSettings(
                    function (string $source, ?Closure $inputSchema = null) use (
                        $registry,
                        $sourceSchema,
                        $sourceIds,
                        $object,
                    ) {
                        $static = $inputSchema !== null;
                        $inputSchema ??= fn () => JsonSchema::fromArray($sourceSchema['properties'][$source]);

                        /** @var Closure(): JsonSchema $inputSchema */

                        return [
                            Hidden::make('field')
                                ->dehydrated(!$static)
                                ->dehydrateStateUsing(fn () => $object?->field($sourceIds[$source])?->jsonSerialize()),
                            Hidden::make('result')
                                ->dehydrated(fn (Get $get) => !$static || filled($get('transforms')))
                                ->dehydrateStateUsing(function (Get $get) use ($registry, $inputSchema) {
                                    /** @var list<TransformInput> $settings */
                                    $settings = $get('transforms');
                                    $schema = $registry->resultSchema($inputSchema(), $settings);

                                    return $schema?->toArray();
                                }),
                            FieldTransforms::make('transforms')
                                ->label(
                                    $static
                                        ? __('phpinnacle-ferry::resources.sync.fields.constant_value')
                                        : $sourceSchema['properties'][$source]['title'],
                                )
                                ->object($object)
                                ->inputSchema(fn () => $inputSchema()->toArray())
                                ->dehydrated(
                                    fn (FieldTransforms $component) => !$static || $component->getSettings() !== [],
                                ),
                        ];
                    },
                );

                return [$mapping];
            });
    }

    /** @param array<string, string> $warnings */
    private static function dynamicMapping(?ConnectionMetadata $object, array $warnings): FieldBinding
    {
        return FieldBinding::make('schema')
            ->dense()
            ->compact()
            ->warnings($warnings)
            ->label(__('phpinnacle-ferry::resources.sync.fields.schema'))
            ->jsonSchema(self::sourceSchema($object))
            ->labels(
                source: __('phpinnacle-ferry::resources.sync.fields.source_title'),
                dest: __('phpinnacle-ferry::resources.sync.fields.target_column'),
            )
            ->simple(
                TextInput::make('target')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.target_column'))
                    ->maxLength(255)
                    ->required(),
            )
            ->staticValues()
            ->rule(fn (FieldBinding $component) => function (string $attribute, mixed $value, Closure $fail) use (
                $component,
            ) {
                /** @var list<array{target: string|null}> $bindings */
                $bindings = $component->getBindingValues();
                SyncSchema::validateColumns(array_column(
                    array_filter($bindings, static fn (array $entry) => $entry['target'] !== null),
                    'target',
                ), $fail);
            })
            ->columnSpanFull();
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
            ->dense()
            ->compact()
            ->staticValues()
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
            ->outputSchema(function (FieldMapping $component, TransformRegistry $registry) {
                /** @var array<string, array<array-key, TransformInput>> $fieldTransforms */
                $fieldTransforms = array_map(
                    static fn (array $settings) => $settings['transforms'] ?? [],
                    $component->getSourceSettings(),
                );
                $schema = $component->getSourceSchema();
                $properties = $schema['properties'] ?? [];
                foreach ($properties as $source => $property) {
                    $properties[$source] = $registry
                        ->resultSchema(
                            JsonSchema::fromArray($property),
                            $fieldTransforms[$source] ?? [],
                        )
                        ?->toArray() ?? [];
                }

                return [...$schema, 'properties' => $properties];
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
        $container = $component->getRootContainer();
        $container->clearCachedChildSchemas();
        $defaultState = null;
        $container->getComponentByStatePath('schema')?->hydrateState($defaultState);
    }

    /** @return array{type: 'object', properties: array<string, array{title: string, type: string, format?: string, minLength?: int, maxLength?: int, minimum?: int, description?: string}>} */
    private static function sourceSchema(?ConnectionMetadata $object, bool $withKey = true): array
    {
        $properties = [];
        foreach ($object?->mappableFields(withKey: $withKey) ?? [] as $field) {
            $properties[$field['physical']] = [
                'title' => $field['title'],
                ...$field['type']->jsonSchema($object?->field($field['source'])),
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
