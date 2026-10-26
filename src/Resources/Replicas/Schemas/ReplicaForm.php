<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Schemas;

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
use PHPinnacle\Eidos\Forms\FieldBinding;
use PHPinnacle\Eidos\Forms\FieldMapping;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping as SchemaMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Forms\FieldTransforms;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Rules\ReplicaSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;

/**
 * @phpstan-import-type TransformInput from TransformRegistry
 *
 * @phpstan-import-type Definition from JsonSchema
 *
 * @phpstan-import-type MappingData from SchemaMapping
 * @phpstan-type SchemaState list<MappingData>
 * @phpstan-type FormState list<MappingData|array{kind: 'static', target: string, static: null}>
 * @phpstan-type CreateState array{name: string, upstream_id: string, code: string, source: string, destination: string}
 * @phpstan-type EditState array{name: string, schema: FormState}
 * @phpstan-type FillState array{upstream_id: string, source: string, type: value-of<DestinationType>, destination: string, schema: SchemaState}
 */
class ReplicaForm
{
    /**
     * @param FillState $data
     * @return array<string, mixed>
     */
    public static function fill(array $data): array
    {
        $isStatic = $data['type'] === DestinationType::Static->value;
        $data['destination'] = $isStatic ? 'static:' . $data['destination'] : DestinationType::Dynamic->value;

        return $data;
    }

    /**
     * @param CreateState $data
     * @return array{upstream_id: string, name: string, code: string, source: string, type: DestinationType, destination: string|null, schema: SchemaState}
     */
    public static function forCreate(
        array $data,
        DestinationFactory $destinations,
    ): array {
        self::object(Upstream::query()->find($data['upstream_id']), $data['source'], 'data.source');
        $key = self::staticKey($data['destination']);
        $destination = $key !== null ? $destinations->getOrFail($key) : null;

        return [
            'upstream_id' => $data['upstream_id'],
            'name' => $data['name'],
            'code' => $data['code'],
            'source' => $data['source'],
            'type' => $destination === null ? DestinationType::Dynamic : DestinationType::Static,
            'destination' => $destination?->key,
            'schema' => [],
        ];
    }

    /**
     * @param EditState $data
     * @return array{name: string, schema: SchemaState}
     */
    public static function forUpdate(
        array $data,
        Replica $record,
        DestinationFactory $destinations,
    ): array {
        self::object($record->upstream, $record->source, 'data.schema');
        $destination = $record->type === DestinationType::Static ? $destinations->get($record->destination) : null;

        if ($record->type === DestinationType::Static && $destination === null) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.replica.errors.static_destination_missing', [
                    'destination' => $record->destination,
                ]),
            ]);
        }

        $mappings = [];
        foreach ($data['schema'] as $mapping) {
            if ($mapping['kind'] === 'source') {
                $mappings[] = $mapping;
            } elseif ($mapping['static'] !== null) {
                $mappings[] = $mapping;
            }
        }
        ReplicaSchema::assertValid(array_map(SchemaMapping::fromArray(...), $mappings), $destination);

        return [
            'name' => $data['name'],
            'schema' => $mappings,
        ];
    }

    private static function object(?Upstream $upstream, string $source, string $attribute): StructureItem
    {
        $object = $upstream?->structure->publishedItem($source);

        if ($object === null) {
            throw ValidationException::withMessages([
                $attribute => __('phpinnacle-ferry::resources.replica.errors.unknown_object'),
            ]);
        }

        return $object;
    }

    public static function creation(Schema $schema): Schema
    {
        return $schema->columns(1)->components([self::generalSection()->heading(null)->contained(false)]);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([self::generalSection(), self::mappingSection()]);
    }

    private static function generalSection(): Section
    {
        $sourceOptionsCache = [];

        $resolveSourceOptions = function (?string $upstreamId) use (&$sourceOptionsCache) {
            return $sourceOptionsCache[$upstreamId ?? ''] ??= self::sourceOptions($upstreamId);
        };

        return Section::make(__('phpinnacle-ferry::resources.replica.sections.general'))
            ->columns(3)
            ->schema([
                TextInput::make('name')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.name'))
                    ->columnSpan(2)
                    ->maxLength(255)
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(FormSlug::make('code')),
                TextInput::make('code')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.code'))
                    ->maxLength(255)
                    ->scopedUnique(ignoreRecord: true)
                    ->required()
                    ->live(onBlur: true)
                    ->disabledOn('edit')
                    ->hint(function (?string $state, Get $get, DestinationFactory $destinations) {
                        $key = self::staticKey($get->string('destination', isNullable: true));
                        $table = $key !== null
                            ? $destinations->get($key)->table ?? ''
                            : Config::string('phpinnacle-ferry.replica.table_prefix') . ($state ?? '');

                        return sprintf(
                            '%s: %s',
                            __('phpinnacle-ferry::resources.replica.fields.destination'),
                            $table,
                        );
                    }),
                Select::make('upstream_id')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.upstream'))
                    ->options(self::upstreamOptions(...))
                    ->default(fn (Select $component) => array_key_first($component->getOptions()))
                    ->native()
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->afterStateUpdated(function (Select $component, Get $get, Set $set) use ($resolveSourceOptions) {
                        $set(
                            'source',
                            array_key_first($resolveSourceOptions($get->string(
                                'upstream_id',
                                isNullable: true,
                            ))),
                        );
                        self::resetMapping($set, $component);
                    }),
                Select::make('source')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.source'))
                    ->options(
                        fn (Get $get) => $resolveSourceOptions($get->string(
                            'upstream_id',
                            isNullable: true,
                        )),
                    )
                    ->placeholder(__('phpinnacle-ferry::resources.replica.values.no_objects'))
                    ->default(fn (Select $component) => array_key_first($component->getOptions()))
                    ->native()
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->afterStateUpdated(self::resetMapping(...)),
                Select::make('destination')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.destination'))
                    ->options(self::destinationOptions(...))
                    ->default(DestinationType::Dynamic->value)
                    ->native()
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->afterStateUpdated(self::resetMapping(...)),
            ]);
    }

    private static function mappingSection(): Group
    {
        return Group::make()
            ->schema(function (
                Get $get,
                ?Replica $record,
                DestinationFactory $destinations,
                TransformRegistry $registry,
            ) {
                $upstreamId = $get->string('upstream_id', isNullable: true);
                $source = $get->string('source', isNullable: true);
                $selectedDestination = $get->string('destination', isNullable: true);
                $object =
                    $upstreamId !== null && $source !== null
                        ? Upstream::query()->find($upstreamId)?->structure->publishedItem($source)
                        : null;
                $destination = $destinations->get(self::staticKey($selectedDestination));
                $brokenColumns =
                    $record !== null && $record->schema !== []
                        ? $record->brokenColumns($object, $destination?->getSchema())
                        : [];

                $targetWarnings = [];
                $sourceWarnings = [];

                foreach ($brokenColumns as $column) {
                    $targetWarnings[$column] = __(
                        'phpinnacle-ferry::resources.replica.values.broken_mappings',
                        ['columns' => $column],
                    );
                }

                foreach ($record->schema ?? [] as $mapping) {
                    if ($mapping->source !== null) {
                        $warning = $mapping->target ?? $mapping->source;
                        if (array_key_exists($warning, $targetWarnings)) {
                            $sourceWarnings[$mapping->source] = $targetWarnings[$warning];
                        }
                    }
                }

                $sourceSchema = self::sourceSchema($object);

                $mapping = match ($selectedDestination) {
                    DestinationType::Dynamic->value => self::dynamicMapping($object, $sourceWarnings),
                    null => null,
                    default => self::staticMapping($object, $destination, $sourceWarnings, $targetWarnings, $record),
                };

                if ($mapping === null) {
                    return [];
                }

                $mapping->sourceSettings(
                    function (string $source, ?Closure $inputSchema = null) use ($registry, $sourceSchema, $object) {
                        $static = $inputSchema !== null;
                        $inputSchema ??= fn () => JsonSchema::fromArray($sourceSchema['properties'][$source]);

                        /** @var Closure(): JsonSchema $inputSchema */

                        return [
                            Hidden::make('field')
                                ->dehydrated(!$static)
                                ->dehydrateStateUsing(fn () => $object?->field($source)?->toArray()),
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
                                        ? __('phpinnacle-ferry::resources.replica.fields.constant_value')
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
    private static function dynamicMapping(?StructureItem $object, array $warnings): FieldBinding
    {
        return FieldBinding::make('schema')
            ->dense()
            ->compact()
            ->warnings($warnings)
            ->label(__('phpinnacle-ferry::resources.replica.fields.schema'))
            ->jsonSchema([...self::sourceSchema($object), 'required' => $object->primary_key ?? []])
            ->labels(
                source: __('phpinnacle-ferry::resources.replica.fields.source_title'),
                dest: __('phpinnacle-ferry::resources.replica.fields.target_column'),
            )
            ->simple(
                TextInput::make('target')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.target_column'))
                    ->maxLength(255)
                    ->required(),
            )
            ->staticValues()
            ->rule(fn (FieldBinding $component) => function (string $attribute, mixed $value, Closure $fail) use (
                $component,
                $object,
            ) {
                /** @var list<array{kind: 'source'|'static', source?: string, target: string|null}> $bindings */
                $bindings = $component->getBindingValues();
                ReplicaSchema::validateColumns(array_column(
                    array_filter($bindings, static fn (array $entry) => $entry['target'] !== null),
                    'target',
                ), $fail);
                ReplicaSchema::validatePrimaryKey(
                    $object->primary_key ?? [],
                    array_column(
                        array_filter(
                            $bindings,
                            static fn (array $entry) => $entry['kind'] === 'source' && $entry['target'] !== null,
                        ),
                        'source',
                    ),
                    $fail,
                );
            })
            ->columnSpanFull();
    }

    /**
     * @param array<string, string> $sourceWarnings
     * @param array<string, string> $targetWarnings
     */
    private static function staticMapping(
        ?StructureItem $object,
        ?StaticDestination $destination,
        array $sourceWarnings,
        array $targetWarnings,
        ?Replica $record,
    ): FieldMapping {
        return FieldMapping::make('schema')
            ->dense()
            ->compact()
            ->withBuiltinStaticForms()
            ->staticValues(array_any(
                $record->schema ?? [],
                static fn (SchemaMapping $mapping) => (
                    $mapping->kind === FieldKind::Static
                    && $mapping->transforms !== []
                ),
            ))
            ->warnings(source: $sourceWarnings, dest: $targetWarnings)
            ->label(__('phpinnacle-ferry::resources.replica.fields.schema'))
            ->required()
            ->jsonSchema(
                source: self::sourceSchema($object),
                dest: $destination !== null
                    ? JsonSchema::fromArray($destination->getSchema()->keywords())->toArray()
                    : [],
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
                source: __('phpinnacle-ferry::resources.replica.fields.source_title'),
                dest: __('phpinnacle-ferry::resources.replica.fields.target_column'),
            )
            ->columnSpanFull();
    }

    /** @return array<string, string> */
    private static function upstreamOptions(): array
    {
        /** @var array<string, string> */
        return Upstream::availableForReplication()
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

    /** @return array{type: 'object', properties: array<string, Definition&array{title: string}>} */
    private static function sourceSchema(?StructureItem $object): array
    {
        return ['type' => 'object', 'properties' => $object?->mappableFields() ?? []];
    }

    /** @return array<string, string> */
    private static function sourceOptions(?string $upstreamId): array
    {
        $upstream = $upstreamId !== null ? Upstream::query()->find($upstreamId) : null;

        if (!$upstream instanceof Upstream) {
            return [];
        }

        return $upstream
            ->structure
            ->publishedItems()
            ->select(['external_id', 'priority', 'title', 'label', 'name'])
            ->get()
            ->sort(static function (StructureItem $a, StructureItem $b) {
                $byRank = $a->priority <=> $b->priority;

                if ($byRank !== 0) {
                    return $byRank;
                }

                return strnatcmp(mb_strtolower($a->displayTitle()), mb_strtolower($b->displayTitle()));
            })
            ->mapWithKeys(static fn (StructureItem $object) => [$object->external_id => $object->displayTitle()])
            ->all();
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
