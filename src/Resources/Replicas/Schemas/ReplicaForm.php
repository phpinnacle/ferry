<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Schemas;

use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Common\Concerns\FormSlug;
use PHPinnacle\Eidos\Forms\BuiltinStaticForms;
use PHPinnacle\Eidos\Forms\FieldBinding;
use PHPinnacle\Eidos\Forms\FieldMapping;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping as SchemaMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Forms\FieldTransforms;
use PHPinnacle\Ferry\Forms\RecordTransforms;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Rules\ReplicaSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;

/**
 * @phpstan-import-type TransformData from TransformSetting
 *
 * @phpstan-import-type Definition from JsonSchema
 *
 * @phpstan-import-type MappingData from SchemaMapping
 * @phpstan-type SchemaState list<MappingData>
 * @phpstan-type FormState list<MappingData|array{kind: 'static', target: string, static: null}>
 * @phpstan-type CreateState array{name: string, upstream_id: string, code: string, source: string, destination: string}
 * @phpstan-type EditState array{name: string, mapping: FormState, schema: Definition, transforms?: list<TransformData>}
 * @phpstan-type FillState array{upstream_id: string, source: string, type: value-of<DestinationType>, destination: string, mapping: SchemaState, schema: Definition, transforms: list<TransformData>}
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
     * @return array{upstream_id: string, name: string, code: string, source: string, type: DestinationType, destination: string|null, mapping: SchemaState}
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
            'mapping' => [],
        ];
    }

    /**
     * @param EditState $data
     * @return array{name: string, mapping: SchemaState, schema: JsonSchema, transforms: list<TransformSetting>}
     */
    public static function forUpdate(
        array $data,
        Replica $record,
        DestinationFactory $destinations,
    ): array {
        $object = self::object($record->upstream, $record->source, 'data.mapping');
        $destination = $record->type === DestinationType::Static ? $destinations->get($record->destination) : null;

        if ($record->type === DestinationType::Static && $destination === null) {
            throw ValidationException::withMessages([
                'data.mapping' => __('phpinnacle-ferry::resources.replica.errors.static_destination_missing', [
                    'destination' => $record->destination,
                ]),
            ]);
        }

        $mappings = [];
        foreach ($data['mapping'] as $mapping) {
            if ($mapping['kind'] === 'source') {
                $mappings[] = $mapping;
            } elseif ($mapping['target'] !== null && $mapping['static'] !== null) {
                $mappings[] = $mapping;
            }
        }
        $schema = JsonSchema::fromArray($data['schema']);
        $transforms = array_map(TransformSetting::fromArray(...), $data['transforms'] ?? []);
        $effectiveSchema = app(TransformRegistry::class)->resultSchema(
            $schema,
            $transforms,
            null,
            TransformScope::Record,
        );
        if ($effectiveSchema === null) {
            throw ValidationException::withMessages([
                'data.transforms' => __('phpinnacle-ferry::validation.replica_transforms'),
            ]);
        }
        /** @var list<string> $primaryKey */
        $primaryKey = $effectiveSchema->annotation('x-primary-key') ?? $object->primary_key;
        ReplicaSchema::assertValid(
            array_map(SchemaMapping::fromArray(...), $mappings),
            $destination,
            $primaryKey,
            $effectiveSchema,
            $object->primary_key,
        );

        return [
            'name' => $data['name'],
            'mapping' => $mappings,
            'schema' => $schema,
            'transforms' => $transforms,
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
        return $schema
            ->columns(1)
            ->components([
                Callout::make(__('phpinnacle-ferry::resources.replica.messages.connector_failed'))
                    ->key('connectorError')
                    ->danger()
                    ->description(fn (?Replica $record) => $record?->connector?->error)
                    ->visible(
                        fn (?Replica $record) => (
                            $record?->connector?->status === ConnectorStatus::Failed
                            && filled($record->connector->error)
                        ),
                    )
                    ->extraAttributes(['role' => 'alert']),
                self::generalSection(),
                Hidden::make('schema')
                    ->afterStateHydrated(function (Hidden $component, Get $get, ?array $state) {
                        if ($state === null) {
                            $upstream = Upstream::query()->find($get->string('upstream_id', isNullable: true));
                            $source = $get->string('source', isNullable: true);
                            $component->state(self::sourceSchema(
                                $source !== null ? $upstream?->structure->publishedItem($source) : null,
                            ));
                        }
                    }),
                self::mappingSection(),
            ]);
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
                    ->native(false)
                    ->preload()
                    ->optionsLimit(0)
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
                        self::resetMapping($set, $component, $get);
                    }),
                Select::make('source')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.source'))
                    ->options(
                        fn (Get $get) => $resolveSourceOptions($get->string(
                            'upstream_id',
                            isNullable: true,
                        )),
                    )
                    ->getSearchResultsUsing(fn (string $search, Get $get) => self::sourceOptions(
                        $get->string('upstream_id', isNullable: true),
                        $search,
                    ))
                    ->getOptionLabelUsing(
                        fn (string $value, Get $get) => Upstream::query()
                            ->find($get->string('upstream_id', isNullable: true))
                            ?->structure
                            ->publishedItem($value)
                            ?->displayTitle(),
                    )
                    ->placeholder(__('phpinnacle-ferry::resources.replica.values.no_objects'))
                    ->default(fn (Select $component) => array_key_first($component->getOptions()))
                    ->native(false)
                    ->preload()
                    ->searchable()
                    ->optionsLimit(20)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->afterStateUpdated(self::resetMapping(...)),
                Select::make('destination')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.destination'))
                    ->options(self::destinationOptions(...))
                    ->default(DestinationType::Dynamic->value)
                    ->native(false)
                    ->preload()
                    ->optionsLimit(0)
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
                /** @var Definition $inputSchema */
                $inputSchema = $get('schema') ?? self::sourceSchema($object);
                /** @var array<string, TransformData> $settings */
                $settings = $get->array('transforms', isNullable: true) ?? [];
                $input = $registry->previewRecordSchema(
                    JsonSchema::fromArray($inputSchema),
                    array_map(TransformSetting::fromArray(...), array_values($settings)),
                );
                $sourceSchema = $input->toArray();
                foreach ($input->properties() as $name => $property) {
                    /** @var string|null $translation */
                    $translation = $property->annotation('x-ferry-trans');
                    if ($translation !== null) {
                        $sourceSchema['properties'][$name]['title'] = __($translation);
                    }
                }
                /** @var list<string> $primaryKey */
                $primaryKey = $input->annotation('x-primary-key') ?? $object->primary_key ?? [];
                $sourceSchema['required'] = array_values(array_unique([...$input->required(), ...$primaryKey]));

                $mapping = match ($selectedDestination) {
                    DestinationType::Dynamic->value => self::dynamicMapping($sourceSchema),
                    null => null,
                    default => self::staticMapping($sourceSchema, $destination),
                };

                if ($mapping === null) {
                    return [];
                }

                $mapping->sourceSettings(
                    function (FieldMapping|FieldBinding $component, string $source, ?Closure $inputSchema = null) use (
                        $object,
                    ) {
                        $sourceSchema = $component->getSourceSchema();
                        $static = $inputSchema !== null;
                        /** @var list<string> $primaryKey */
                        $primaryKey = $sourceSchema['x-primary-key'] ?? [];
                        $sourceIsKey =
                            !$static
                            && (
                                in_array($source, $primaryKey, true)
                                || in_array($source, $object->primary_key ?? [], true)
                            );
                        $inputSchema ??= fn () => JsonSchema::fromArray($sourceSchema)->properties()[$source];
                        /** @var string $label */
                        $label = $sourceSchema['properties'][$source]['title'] ?? $source;

                        /** @var Closure(): JsonSchema $inputSchema */

                        return [
                            FieldTransforms::make('transforms')
                                ->label(
                                    $static
                                        ? __('phpinnacle-ferry::resources.replica.fields.constant_value')
                                        : $label,
                                )
                                ->object($object)
                                ->target($sourceIsKey ? TransformTarget::Key : TransformTarget::Value)
                                ->addable(!$sourceIsKey)
                                ->rule('max:0', $sourceIsKey)
                                ->validationMessages([
                                    'max' => __('phpinnacle-ferry::validation.primary_key_transforms_prohibited'),
                                ])
                                ->inputSchema(fn () => $inputSchema()->toArray())
                                ->dehydrated(
                                    fn (FieldTransforms $component) => !$static || $component->getItems() !== [],
                                ),
                        ];
                    },
                );

                return [
                    RecordTransforms::make('transforms')
                        ->label(__('phpinnacle-ferry::resources.replica.fields.record_transforms'))
                        ->object($object)
                        ->inputSchema($inputSchema)
                        ->afterStateUpdated(self::syncMapping(...)),
                    $mapping,
                ];
            });
    }

    private static function syncMapping(RecordTransforms $component): void
    {
        /** @var FieldMapping|FieldBinding $mapping */
        $mapping = $component->getContainer()->getComponentByStatePath('mapping');
        $sourceSchema = $component->getInputSchema()->toArray();
        $properties = $sourceSchema['properties'] ?? [];
        /** @var FormState $rows */
        $rows = $mapping->getRawState() ?? [];
        $rows = array_values(array_filter(
            $rows,
            fn (array $row) => $row['kind'] === 'static' || array_key_exists($row['source'], $properties),
        ));
        if ($mapping instanceof FieldMapping) {
            $mapping->jsonSchema(source: $sourceSchema);
        } else {
            $mapping->jsonSchema($sourceSchema);
        }
        $mapping->state($rows);
        $defaultState = null;
        $mapping->hydrateState($defaultState);
    }

    /** @param Definition $sourceSchema */
    private static function dynamicMapping(array $sourceSchema): FieldBinding
    {
        return FieldBinding::make('mapping')
            ->dense()
            ->compact()
            ->label(__('phpinnacle-ferry::resources.replica.fields.schema'))
            ->jsonSchema($sourceSchema)
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
            ) {
                /** @var list<array{kind: 'source'|'static', source?: string, target: string|null}> $bindings */
                $bindings = $component->getBindingValues();
                ReplicaSchema::validateColumns(array_column(
                    array_filter($bindings, static fn (array $entry) => $entry['target'] !== null),
                    'target',
                ), $fail);
                /** @var list<string> $primaryKey */
                $primaryKey = $component->getSourceSchema()['x-primary-key'] ?? [];
                ReplicaSchema::validatePrimaryKey(
                    $primaryKey,
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

    /** @param Definition $sourceSchema */
    private static function staticMapping(
        array $sourceSchema,
        ?StaticDestination $destination,
    ): FieldMapping {
        $mapping = FieldMapping::make('mapping');

        return $mapping
            ->dense()
            ->compact()
            ->staticForms(fn (FieldMapping $component, string $target, JsonSchema $property) => in_array(
                $target,
                $destination?->getPrimaryKey() ?? [],
                true,
            )
                    ? null
                    : BuiltinStaticForms::make($property, in_array($target, $component->getRequiredTargets(), true)))
            ->label(__('phpinnacle-ferry::resources.replica.fields.schema'))
            ->required()
            ->jsonSchema(
                source: $sourceSchema,
                dest: $destination !== null
                    ? JsonSchema::fromArray($destination->getSchema()->keywords())->toArray()
                    : [],
            )
            ->canConnectUsing(function (
                JsonSchema $source,
                JsonSchema $target,
                string $sourceName,
                string $targetName,
            ) use ($mapping, $destination) {
                /** @var list<string> $primaryKey */
                $primaryKey = $mapping->getSourceSchema()['x-primary-key'] ?? [];

                return (
                    in_array($targetName, $destination?->getPrimaryKey() ?? [], true) === in_array(
                        $sourceName,
                        $primaryKey,
                        true,
                    )
                );
            })
            ->rule(fn (FieldMapping $component) => function (string $attribute, mixed $value, Closure $fail) use (
                $component,
            ) {
                /** @var list<string> $primaryKey */
                $primaryKey = $component->getSourceSchema()['x-primary-key'] ?? [];
                ReplicaSchema::validatePrimaryKey(
                    $primaryKey,
                    array_values(array_filter($primaryKey, $component->isSourceUsed(...))),
                    $fail,
                );
            })
            ->outputSchema(function (FieldMapping $component, TransformRegistry $registry) {
                /** @var array<string, list<TransformData>> $fieldTransforms */
                $fieldTransforms = array_map(
                    static fn (array $settings) => $settings['transforms'] ?? [],
                    $component->getSourceSettings(),
                );
                $schema = $component->getSourceSchema();
                /** @var list<string> $primaryKey */
                $primaryKey = $schema['x-primary-key'] ?? [];
                $properties = $schema['properties'] ?? [];
                foreach ($properties as $source => $property) {
                    $properties[$source] = $registry
                        ->resultSchema(
                            JsonSchema::fromArray($property),
                            array_map(TransformSetting::fromArray(...), $fieldTransforms[$source] ?? []),
                            in_array($source, $primaryKey, true)
                                ? TransformTarget::Key
                                : TransformTarget::Value,
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

    private static function resetMapping(Set $set, Select $component, Get $get): void
    {
        $upstream = Upstream::query()->find($get->string('upstream_id', isNullable: true));
        $source = $get->string('source', isNullable: true);
        $set('schema', self::sourceSchema($source !== null ? $upstream?->structure->publishedItem($source) : null));
        $set('mapping', []);
        $set('transforms', []);
        $container = $component->getRootContainer();
        $container->clearCachedChildSchemas();
        $defaultState = null;
        $container->getComponentByStatePath('mapping')?->hydrateState($defaultState);
    }

    /** @return Definition */
    private static function sourceSchema(?StructureItem $object): array
    {
        return [
            ...($object?->schema->toArray() ?? ['type' => 'object', 'properties' => []]),
            'x-primary-key' => $object->primary_key ?? [],
            'required' => array_values(array_unique([
                ...($object?->schema->required() ?? []),
                ...($object->primary_key ?? []),
            ])),
        ];
    }

    /** @return array<string, string> */
    private static function sourceOptions(?string $upstreamId, string $search = ''): array
    {
        return Upstream::query()->find($upstreamId)?->structure->sourceOptions($search) ?? [];
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
