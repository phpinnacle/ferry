<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Schemas;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Common\Concerns\FormSlug;
use PHPinnacle\Ferry\Contracts\Transform;
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
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Rosetta\Enums\MetadataKind;

/**
 * @phpstan-type SchemaState array<string, string|array{value: string, type?: string}>
 * @phpstan-type SettingState array{key: string, params: array<string, string>}
 * @phpstan-type TransformRow array{key: string, params?: array<string, string>}
 * @phpstan-type TransformInput array{key?: string|null, params?: array<string, string>}
 * @phpstan-type CreateState array{name: string, connection_id: string, code: string, source: string, destination: string, schema: SchemaState, transforms?: array<array-key, TransformRow>, field_transforms?: array<string, array<array-key, TransformRow>>}
 * @phpstan-type EditState array{name: string, schema: SchemaState, transforms?: array<array-key, TransformRow>, field_transforms?: array<string, array<array-key, TransformRow>>}
 * @phpstan-type FillMapping array{source: string|null, column: string, transforms?: list<SettingState>}
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

        foreach ($data['schema'] as $mapping) {
            $source = $mapping['source'];
            $settings = $mapping['transforms'] ?? [];

            if ($source !== null) {
                $schema[$source] = $mapping['column'];

                if ($settings !== []) {
                    $fieldTransforms[$source] = $settings;
                }

                continue;
            }

            foreach ($settings as $setting) {
                $schema[$mapping['column']] = $data['type'] === DestinationType::Static->value
                    ? ['value' => $setting['params']['value']]
                    : $setting['params'];
            }
        }

        $data['schema'] = $schema;
        $data['field_transforms'] = $fieldTransforms;
        $data['destination'] = $data['type'] === DestinationType::Static->value
            ? 'static:' . $data['destination']
            : DestinationType::Dynamic->value;

        return $data;
    }

    /**
     * @param CreateState $data
     * @return array{connection_id: string, name: string, code: string, source: string, type: DestinationType, destination: string|null, schema: list<SchemaMapping>, transforms: list<TransformSetting>}
     */
    public static function forCreate(array $data, DestinationFactory $destinations, TransformFactory $transforms): array
    {
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
            'schema' => self::mappings($object, $data, $destination, $transforms),
            'transforms' => SyncSchema::fromTransforms(self::settings($data['transforms'] ?? []), $object, $transforms),
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
        TransformFactory $transforms,
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
            'schema' => self::mappings($object, $data, $destination, $transforms),
            'transforms' => SyncSchema::fromTransforms(self::settings($data['transforms'] ?? []), $object, $transforms),
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
        TransformFactory $transforms,
    ): array {
        $fields = array_column($destination->fields ?? [], null, 'id');
        $bindings = [];
        $constants = [];
        $fieldTransforms = [];

        foreach ($data['schema'] as $key => $value) {
            if (is_array($value)) {
                $constants[$key] = ['type' => $value['type'] ?? $fields[$key]->type->value, 'value' => $value['value']];

                continue;
            }

            $bindings[$key] = $value;
        }

        foreach ($data['field_transforms'] ?? [] as $source => $rows) {
            if (array_key_exists($source, $bindings)) {
                $fieldTransforms[$source] = self::settings($rows);
            }
        }

        return SyncSchema::fromBindings(
            $object,
            $bindings,
            $transforms,
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
            static fn (array $row) => new TransformSetting($row['key'], $row['params'] ?? []),
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
                TransformFactory $transforms,
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

                $mapping = match ($selectedDestination) {
                    DestinationType::Dynamic->value => [
                        self::dynamicMapping($object, $sourceWarnings)
                            ->extraSourceActions([self::fieldTransformsAction($object, $transforms)]),
                    ],
                    null => [],
                    default => [
                        self::staticMapping($object, $destination, $sourceWarnings, $targetWarnings)
                            ->extraSourceActions([self::fieldTransformsAction($object, $transforms)]),
                    ],
                };

                return (
                    $mapping === []
                        ? []
                        : [
                            self::transformsRepeater(TransformScope::Record, $object, $transforms)
                                ->label(__('phpinnacle-ferry::resources.sync.fields.transforms'))
                                ->columnSpanFull(),
                            Hidden::make('field_transforms')->default([]),
                            ...$mapping,
                        ]
                );
            });
    }

    private static function transformsRepeater(
        TransformScope $scope,
        ?ConnectionMetadata $object,
        TransformFactory $transforms,
    ): Repeater {
        $available = $transforms->forScope($scope);
        $single = count($available) === 1 ? array_values($available)[0] : null;

        $isField = $scope === TransformScope::Field;

        $repeater = Repeater::make('transforms')
            ->addActionLabel(__('phpinnacle-ferry::resources.sync.fields.add_transform'))
            ->maxItems($isField ? count($available) : null)
            ->schema([
                Select::make('key')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.transform'))
                    ->options(array_map(static fn (Transform $transform) => $transform->getLabel(), $available))
                    ->when(
                        $isField,
                        static fn (Select $select) => $select->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    )
                    ->hiddenJs($single !== null ? 'true' : null)
                    ->required()
                    ->live()
                    ->afterStateUpdated(
                        fn (Set $set, ?string $state) => $set(
                            'params',
                            self::emptyParams($transforms->get($state ?? ''), $object),
                        ),
                    ),
                Group::make()
                    ->statePath('params')
                    ->schema(
                        fn (Get $get) => (
                            $transforms->get($get->string('key', isNullable: true) ?? '')?->schema($object) ?? []
                        ),
                    ),
            ]);

        return $single === null
            ? $repeater
            : $repeater->afterStateUpdated(fn (Repeater $component) => self::preselect($component, $single, $object));
    }

    private static function preselect(Repeater $component, Transform $transform, ?ConnectionMetadata $object): void
    {
        /** @var array<array-key, array<string, mixed>> $rows */
        $rows = $component->getRawState();

        foreach ($rows as $item => $row) {
            if (blank($row['key'] ?? null)) {
                $rows[$item] = ['key' => $transform->key, 'params' => self::emptyParams($transform, $object)];
            }
        }

        $component->rawState($rows);
    }

    private static function fieldTransformsAction(?ConnectionMetadata $object, TransformFactory $transforms): Action
    {
        $fields = self::sourceFields($object);

        return Action::make('fieldTransforms')
            ->label(function (array $arguments, Get $get) {
                /** @var string|null $source */
                $source = is_string($arguments['source'] ?? null) ? $arguments['source'] : null;
                /** @var array<string, array<mixed>> $state */
                $state = $get->array('field_transforms', isNullable: true) ?? [];

                return __('phpinnacle-ferry::resources.sync.fields.field_transforms', [
                    'count' => count($source !== null && is_array($state[$source] ?? null) ? $state[$source] : []),
                ]);
            })
            ->icon('heroicon-m-adjustments-horizontal')
            ->color('gray')
            ->size(Size::Small)
            ->button()
            ->modalHeading(function (array $arguments) use ($fields) {
                /** @var string $source */
                $source = is_string($arguments['source'] ?? null) ? $arguments['source'] : '';

                return __('phpinnacle-ferry::resources.sync.fields.field_transforms_heading', [
                    'field' => $fields[$source] ?? $source,
                ]);
            })
            ->schema([self::transformsRepeater(TransformScope::Field, $object, $transforms)->hiddenLabel()])
            ->fillForm(function (array $arguments, Get $get) use ($fields) {
                /** @var array<string, array<mixed>> $state */
                $state = $get->array('field_transforms', isNullable: true) ?? [];

                return [
                    'transforms' => $state[self::fieldSource($arguments, $fields)] ?? [],
                ];
            })
            ->action(function (array $arguments, array $data, Get $get, Set $set) use ($fields) {
                $source = self::fieldSource($arguments, $fields);
                /** @var array<string, array<mixed>> $state */
                $state = $get->array('field_transforms', isNullable: true) ?? [];
                /** @var array<mixed> $transforms */
                $transforms = is_array($data['transforms'] ?? null) ? $data['transforms'] : [];
                $state[$source] = array_values($transforms);

                $set('field_transforms', array_filter($state));
            });
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<string, string> $fields
     */
    private static function fieldSource(array $arguments, array $fields): string
    {
        /** @var array{source: string} $validated */
        $validated = Validator::make($arguments, [
            'source' => ['required', 'string', Rule::in(array_keys($fields))],
        ])->validate();

        return $validated['source'];
    }

    /** @return array<string, mixed> */
    private static function emptyParams(?Transform $transform, ?ConnectionMetadata $object): array
    {
        $params = [];

        foreach ($transform?->schema($object) ?? [] as $field) {
            $params[$field->getName()] = $field->getDefaultState();
        }

        return $params;
    }

    /** @param array<string, string> $warnings */
    private static function dynamicMapping(?ConnectionMetadata $object, array $warnings): FieldBinding
    {
        return FieldBinding::make('schema')
            ->warnings($warnings)
            ->label(__('phpinnacle-ferry::resources.sync.fields.schema'))
            ->options(self::sourceFields($object))
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
            ->constants(fn () => [
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
            ])
            ->rule(
                fn (FieldBinding $component) => fn (
                    string $attribute,
                    mixed $value,
                    Closure $fail,
                ) => self::validateBindings($component, $attribute, $value, $fail),
            )
            ->columnSpanFull();
    }

    private static function validateBindings(
        FieldBinding $component,
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (!is_array($value)) {
            return;
        }

        $rows = [];

        foreach ($component->getSources() as $source) {
            $binding = $value[$component->getBindingKey($source['id'])] ?? null;

            if (is_array($binding)) {
                $rows[] = [
                    'source' => $source['id'],
                    'column' => $binding['column'] ?? null,
                ];
            }
        }

        foreach ($component->getConstants() as $key => $constant) {
            $binding = $value[$key] ?? null;

            if (is_array($binding)) {
                $rows[] = [
                    'source' => $key,
                    'column' => $binding['column'] ?? null,
                ];
            }
        }

        new SyncSchema()->validate($attribute, $rows, $fail);
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
            ->warnings(source: $sourceWarnings, dest: $targetWarnings)
            ->formatStateUsing(self::flipMapping(...))
            ->dehydrateStateUsing(self::flipMapping(...))
            ->label(__('phpinnacle-ferry::resources.sync.fields.schema'))
            ->required()
            ->options(
                source: self::sourceFields($object, withKey: $destination === null),
                dest: array_column($fields, 'label', 'id'),
            )
            ->types(
                source: function (Get $get, TransformFactory $transforms) use ($object) {
                    /** @var array<string, array<array-key, TransformInput>> $fieldTransforms */
                    $fieldTransforms = $get->array('field_transforms', isNullable: true) ?? [];

                    return self::resultTypes(self::sourceTypes($object), $fieldTransforms, $transforms);
                },
                dest: array_map(static fn (DestinationField $field) => $field->type->value, $fields),
            )
            ->requiredTargets(array_column(
                array_filter($fields, static fn (DestinationField $field) => $field->required),
                'id',
            ))
            ->constants(array_column(
                array_filter($fields, static fn (DestinationField $field) => $field->type->castType() !== null),
                'id',
            ))
            ->labels(
                source: __('phpinnacle-ferry::resources.sync.fields.source_title'),
                dest: __('phpinnacle-ferry::resources.sync.fields.target_column'),
            )
            ->columnSpanFull();
    }

    /**
     * @param array<string, string> $types
     * @param array<string, array<array-key, TransformInput>> $fieldTransforms
     * @return array<string, string>
     */
    private static function resultTypes(array $types, array $fieldTransforms, TransformFactory $transforms): array
    {
        foreach ($fieldTransforms as $source => $rows) {
            foreach ($rows as $row) {
                $params = $row['params'] ?? [];
                $transform = $transforms->get($row['key'] ?? '');

                if (
                    !array_key_exists($source, $types)
                    || $transform === null
                    || Validator::make($params, $transform->rules($params))->fails()
                ) {
                    continue;
                }

                $type = $transform->resultType(ColumnType::from($types[$source]), $params);

                if ($type === null) {
                    unset($types[$source]);

                    continue;
                }

                $types[$source] = $type->value;
            }
        }

        return $types;
    }

    /**
     * @param SchemaState|null $state
     * @return SchemaState
     */
    private static function flipMapping(?array $state): array
    {
        $flipped = [];

        foreach ($state ?? [] as $key => $value) {
            if (is_array($value)) {
                $flipped[$key] = $value;

                continue;
            }

            $flipped[$value] = $key;
        }

        return $flipped;
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
        $set('field_transforms', []);
        $component->getRootContainer()->clearCachedChildSchemas();
    }

    private static function resetSource(Set $set, Select $component): void
    {
        $set('transforms', []);
        self::resetMapping($set, $component);
    }

    /** @return array<string, string> */
    private static function sourceFields(?ConnectionMetadata $object, bool $withKey = true): array
    {
        $fields = [];

        foreach ($object?->mappableFields(withKey: $withKey) ?? [] as $field) {
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
