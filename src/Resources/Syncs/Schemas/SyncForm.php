<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Schemas;

use Closure;
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
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\DestinationType;
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
 * @phpstan-type CreateState array{name: string, connection_id: string, code: string, source: string, destination: string, schema: array<string, string>}
 * @phpstan-type EditState array{name: string, schema: array<string, string>}
 * @phpstan-type FillState array{type: value-of<DestinationType>, destination: string, schema: list<array{source: string, column: string}>}
 */
class SyncForm
{
    /**
     * @param FillState $data
     * @return array<string, mixed>
     */
    public static function fill(array $data): array
    {
        $data['schema'] = array_column($data['schema'], 'column', 'source');
        $data['destination'] = $data['type'] === DestinationType::Static->value
            ? 'static:' . $data['destination']
            : DestinationType::Dynamic->value;

        return $data;
    }

    /**
     * @param CreateState $data
     * @return array{connection_id: string, name: string, code: string, source: string, type: DestinationType, destination: string|null, schema: list<SchemaMapping>}
     */
    public static function forCreate(array $data, DestinationFactory $destinations): array
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
            'schema' => SyncSchema::fromBindings($object, $data['schema'], $destination),
        ];
    }

    /**
     * @param EditState $data
     * @return array{name: string, schema: list<SchemaMapping>}
     */
    public static function forUpdate(array $data, Sync $record, DestinationFactory $destinations): array
    {
        $object = self::object($record->connection, $record->source, 'data.schema');
        $destination = $record->type === DestinationType::Static ? $destinations->get($record->destination) : null;

        if ($record->type === DestinationType::Static && $destination === null) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', [
                    'destination' => $record->destination,
                ]),
            ]);
        }

        return ['name' => $data['name'], 'schema' => SyncSchema::fromBindings($object, $data['schema'], $destination)];
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
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(self::resetMapping(...)),
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
            ->schema(function (Get $get, ?Sync $record, DestinationFactory $destinations) {
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
                    if (array_key_exists($mapping->column, $targetWarnings)) {
                        $sourceWarnings[$mapping->source] = $targetWarnings[$mapping->column];
                    }
                }

                return match ($selectedDestination) {
                    DestinationType::Dynamic->value => [self::dynamicMapping($object, $sourceWarnings)],
                    null => [],
                    default => [self::staticMapping($object, $destination, $sourceWarnings, $targetWarnings)],
                };
            });
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
                source: self::sourceTypes($object),
                dest: array_map(static fn (DestinationField $field) => $field->type->value, $fields),
            )
            ->requiredTargets(array_column(
                array_filter($fields, static fn (DestinationField $field) => $field->required),
                'id',
            ))
            ->labels(
                source: __('phpinnacle-ferry::resources.sync.fields.source_title'),
                dest: __('phpinnacle-ferry::resources.sync.fields.target_column'),
            )
            ->columnSpanFull();
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
