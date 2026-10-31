<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Schemas;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPinnacle\Common\Concerns\FormSlug;
use PHPinnacle\Eidos\Forms\BuiltinStaticForms;
use PHPinnacle\Eidos\Forms\FieldBinding;
use PHPinnacle\Eidos\Forms\FieldMapping;
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
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Rules\ReplicaSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Jason\Schema;

/**
 * @phpstan-import-type TransformData from TransformSetting
 *
 * @phpstan-import-type MappingData from SchemaMapping
 * @phpstan-type SchemaState list<MappingData>
 * @phpstan-type FormState list<MappingData|array{kind: 'static', target: string, static: null}>
 * @phpstan-type EditState array{mapping: SchemaState, transforms: list<TransformData>}
 */
class ReplicaForm
{
    public static function creation(FilamentSchema $schema): FilamentSchema
    {
        return $schema->columns(1)->components([self::creationSection()]);
    }

    public static function configure(FilamentSchema $schema): FilamentSchema
    {
        return $schema
            ->columns(1)
            ->components(fn (Replica $record, DestinationFactory $destinations) => [
                Callout::make(__('phpinnacle-ferry::resources.replica.messages.connector_failed'))
                    ->key('connectorError')
                    ->danger()
                    ->description($record->connector?->error)
                    ->visible(
                        $record->connector?->status === ConnectorStatus::Failed && filled($record->connector->error),
                    )
                    ->extraAttributes(['role' => 'alert']),
                Section::make(__('phpinnacle-ferry::resources.replica.sections.general'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('phpinnacle-ferry::resources.replica.fields.name'))
                            ->columnSpan(2)
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('code')
                            ->label(__('phpinnacle-ferry::resources.replica.fields.code'))
                            ->disabled(),
                        Select::make('upstream_id')
                            ->label(__('phpinnacle-ferry::resources.replica.fields.upstream'))
                            ->options([$record->upstream_id => $record->upstream->name])
                            ->native(false)
                            ->disabled()
                            ->validatedWhenNotDehydrated(false),
                        Select::make('source')
                            ->label(__('phpinnacle-ferry::resources.replica.fields.source'))
                            ->options([$record->source => $record->sourceObject()->displayTitle()])
                            ->native(false)
                            ->disabled()
                            ->validatedWhenNotDehydrated(false),
                        Select::make('destination')
                            ->label(__('phpinnacle-ferry::resources.replica.fields.destination'))
                            ->options(fn () => [
                                $record->destination =>
                                    $destinations->staticFor($record)?->getLabel() ?? $record->destination,
                            ])
                            ->native(false)
                            ->disabled()
                            ->validatedWhenNotDehydrated(false),
                    ]),
                self::mappingSection($record, $destinations),
            ]);
    }

    private static function creationSection(): Section
    {
        return Section::make()
            ->contained(false)
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
                    ->required(),
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
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
                        'source',
                        array_key_first(self::sourceOptions($state)),
                    )),
                Select::make('source')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.source'))
                    ->options(
                        fn (Get $get) => self::sourceOptions($get->string(
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
                    ->required(),
                Select::make('type')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.type'))
                    ->options(DestinationType::class)
                    ->default(DestinationType::Dynamic)
                    ->required()
                    ->native(false)
                    ->preload()
                    ->optionsLimit(0)
                    ->selectablePlaceholder(false)
                    ->live(),
                Select::make('destination')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.destination'))
                    ->options(fn (DestinationFactory $destinations) => array_map(
                        fn (StaticDestination $destination) => $destination->getLabel(),
                        $destinations->all(),
                    ))
                    ->native(false)
                    ->preload()
                    ->optionsLimit(0)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->visible(fn (Get $get) => $get->enum('type', DestinationType::class) === DestinationType::Static),
            ]);
    }

    private static function validateMapping(
        Group $component,
        Replica $record,
        DestinationFactory $destinations,
        ReplicaSchemaResolver $schemas,
        ReplicaSchema $rules,
    ): void {
        /** @var EditState $data */
        $data = $component->getChildSchemas()['default']->getState();
        $object = $record->sourceObject();
        $schema = $schemas->resultSchema(
            $record->schema,
            array_map(TransformSetting::fromArray(...), $data['transforms']),
            null,
            TransformScope::Record,
        );
        /** @var list<string> $primaryKey */
        $primaryKey = $schema?->annotation('x-primary-key') ?? $object->primary_key;
        $rules->assertValid(
            array_map(SchemaMapping::fromArray(...), $data['mapping']),
            $destinations->staticFor($record),
            $primaryKey,
            $schema,
        );
    }

    private static function mappingSection(Replica $record, DestinationFactory $destinations): Group
    {
        $object = $record->sourceObject();
        $inputSchema = $record->schema->keywords();
        $inputSchema['required'] = array_values(array_unique([
            ...$record->schema->effectiveRequired(),
            ...$object->primary_key,
        ]));
        $transforms = RecordTransforms::make('transforms')
            ->label(__('phpinnacle-ferry::resources.replica.fields.record_transforms'))
            ->inputSchema(Schema::fromArray($inputSchema))
            ->afterStateUpdated(self::syncMapping(...));
        /** @var array{state: mixed, locale: string, schema: Schema}|null $preview */
        $preview = null;
        $sourceSchema = function () use ($transforms, $object, &$preview) {
            $state = $transforms->getRawState();
            $locale = app()->getLocale();
            if ($preview !== null && $preview['state'] === $state && $preview['locale'] === $locale) {
                return $preview['schema'];
            }

            $input = $transforms->getInputSchema();
            $sourceSchema = $input->keywords();
            foreach ($input->resolveReferences()->effectiveProperties() as $name => $property) {
                /** @var string|null $translation */
                $translation = $property->annotation('x-ferry-trans');
                if ($translation !== null) {
                    $sourceSchema['properties'][$name] = [...$property->keywords(), 'title' => __($translation)];
                }
            }

            /** @var list<string> $primaryKey */
            $primaryKey = $input->annotation('x-primary-key') ?? $object->primary_key;
            $sourceSchema['required'] = array_values(array_unique([
                ...$input->effectiveRequired(),
                ...$primaryKey,
            ]));
            $preview = ['state' => $state, 'locale' => $locale, 'schema' => Schema::fromArray($sourceSchema)];

            return $preview['schema'];
        };

        $mapping = match ($record->type) {
            DestinationType::Dynamic => self::fieldBinding($sourceSchema),
            DestinationType::Static => self::fieldMapping(
                $sourceSchema,
                $destinations->staticFor($record),
                $record->destination,
            ),
        };

        $mapping->sourceSettings(
            function (FieldMapping|FieldBinding $component, string $source, ?Closure $inputSchema = null) use (
                $object,
            ) {
                $sourceSchema = $component->getSourceSchema();
                $static = $inputSchema !== null;
                /** @var list<string> $primaryKey */
                $primaryKey = $sourceSchema->annotation('x-primary-key') ?? [];
                $sourceIsKey =
                    !$static && (in_array($source, $primaryKey, true) || in_array($source, $object->primary_key, true));
                $inputSchema ??= fn () => $sourceSchema->resolveReferences()->effectiveProperties()[$source];
                /** @var string $label */
                $label =
                    ($sourceSchema->resolveReferences()->effectiveProperties()[$source] ?? null)?->annotation('title')
                    ?? $source;

                /** @var Closure(): Schema $inputSchema */

                return [
                    FieldTransforms::make('transforms')
                        ->label(
                            $static
                                ? __('phpinnacle-ferry::resources.replica.fields.constant_value')
                                : $label,
                        )
                        ->target($sourceIsKey ? TransformTarget::Key : TransformTarget::Value)
                        ->addable(!$sourceIsKey)
                        ->rule('max:0', $sourceIsKey)
                        ->validationMessages([
                            'max' => __('phpinnacle-ferry::validation.primary_key_transforms_prohibited'),
                        ])
                        ->inputSchema($inputSchema)
                        ->dehydrated(
                            fn (FieldTransforms $component) => !$static || $component->getItems() !== [],
                        ),
                ];
            },
        );

        return Group::make()
            ->beforeStateDehydrated(self::validateMapping(...))
            ->schema([
                $transforms,
                $mapping,
            ]);
    }

    private static function syncMapping(RecordTransforms $component): void
    {
        /** @var FieldMapping|FieldBinding $mapping */
        $mapping = $component->getContainer()->getComponentByStatePath('mapping');
        $sourceSchema = $mapping->getSourceSchema();
        $properties = $sourceSchema->resolveReferences()->effectiveProperties();
        /** @var FormState $rows */
        $rows = $mapping->getRawState() ?? [];
        $rows = array_values(array_filter(
            $rows,
            fn (array $row) => $row['kind'] === 'static' || array_key_exists($row['source'], $properties),
        ));
        $mapping->state($rows);
        $defaultState = null;
        $mapping->hydrateState($defaultState);
    }

    /** @param Closure(): Schema $sourceSchema */
    private static function fieldBinding(Closure $sourceSchema): FieldBinding
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
                $primaryKey = $component->getSourceSchema()->annotation('x-primary-key') ?? [];
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

    /** @param Closure(): Schema $sourceSchema */
    private static function fieldMapping(
        Closure $sourceSchema,
        ?StaticDestination $destination,
        ?string $destinationKey,
    ): FieldMapping {
        $mapping = FieldMapping::make('mapping');

        return $mapping
            ->dense()
            ->compact()
            ->staticForms(fn (FieldMapping $component, string $target, Schema $property) => in_array(
                $target,
                $destination?->getPrimaryKey() ?? [],
                true,
            )
                    ? null
                    : BuiltinStaticForms::make($property, in_array($target, $component->getRequiredTargets(), true))
                        ?->dehydrated(fn (mixed $state) => $state !== null))
            ->label(__('phpinnacle-ferry::resources.replica.fields.schema'))
            ->required()
            ->jsonSchema(
                source: $sourceSchema,
                target: $destination !== null
                    ? $destination->getSchema()
                    : Schema::fromArray([]),
            )
            ->canConnectUsing(function (
                Schema $source,
                Schema $target,
                string $sourceName,
                string $targetName,
            ) use ($mapping, $destination) {
                /** @var list<string> $primaryKey */
                $primaryKey = $mapping->getSourceSchema()->annotation('x-primary-key') ?? [];

                return (
                    in_array($targetName, $destination?->getPrimaryKey() ?? [], true) === in_array(
                        $sourceName,
                        $primaryKey,
                        true,
                    )
                );
            })
            ->rule(
                fn (FieldMapping $component) => fn (
                    string $attribute,
                    mixed $value,
                    Closure $fail,
                ) => self::validateFieldMapping($component, $destination, $destinationKey, $fail),
            )
            ->outputSchema(function (FieldMapping $component, ReplicaSchemaResolver $schemas) {
                /** @var array<string, list<TransformData>> $fieldTransforms */
                $fieldTransforms = array_map(
                    static fn (array $settings) => $settings['transforms'] ?? [],
                    $component->getSourceSettings(),
                );
                $schema = $component->getSourceSchema();
                /** @var list<string> $primaryKey */
                $primaryKey = $schema->annotation('x-primary-key') ?? [];
                $properties = [];
                foreach ($schema->resolveReferences()->effectiveProperties() as $source => $property) {
                    $properties[$source] = $schemas
                        ->resultSchema(
                            $property,
                            array_map(TransformSetting::fromArray(...), $fieldTransforms[$source] ?? []),
                            in_array($source, $primaryKey, true)
                                ? TransformTarget::Key
                                : TransformTarget::Value,
                        )
                        ?->keywords() ?? [];
                }

                return Schema::fromArray([...$schema->keywords(), 'properties' => $properties]);
            })
            ->labels(
                source: __('phpinnacle-ferry::resources.replica.fields.source_title'),
                dest: __('phpinnacle-ferry::resources.replica.fields.target_column'),
            )
            ->columnSpanFull();
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private static function validateFieldMapping(
        FieldMapping $component,
        ?StaticDestination $destination,
        ?string $destinationKey,
        Closure $fail,
    ): void {
        if ($destination === null) {
            $fail('phpinnacle-ferry::resources.replica.errors.static_destination_missing')->translate([
                'destination' => $destinationKey,
            ]);

            return;
        }

        /** @var list<string> $primaryKey */
        $primaryKey = $component->getSourceSchema()->annotation('x-primary-key') ?? [];
        ReplicaSchema::validatePrimaryKey(
            $primaryKey,
            array_values(array_filter($primaryKey, $component->isSourceUsed(...))),
            $fail,
        );
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

    /** @return array<string, string> */
    private static function sourceOptions(?string $upstreamId, string $search = ''): array
    {
        return Upstream::query()->find($upstreamId)?->structure->sourceOptions($search) ?? [];
    }
}
