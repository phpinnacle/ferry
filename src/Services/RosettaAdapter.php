<?php

namespace PHPinnacle\Ferry\Services;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use PHPinnacle\Ferry\Contracts\Adapter;
use PHPinnacle\Ferry\Data\CapturePlan;
use PHPinnacle\Ferry\Data\StructurePlan;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\Rosetta\StructureConverter;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Rosetta\Connections\PdoConnection;
use PHPinnacle\Rosetta\MetadataLoader;
use PHPinnacle\Rosetta\StorageMap;
use PHPinnacle\Rosetta\TypeMap;

class RosettaAdapter implements Adapter
{
    public function __construct(
        private readonly UpstreamFactory $factory,
        private readonly MetadataLoader $loader,
        private readonly TransformRegistry $transforms,
        private readonly DestinationFactory $destinations,
        private readonly ReplicaSchemaResolver $schemas,
    ) {}

    public function getKey(): string
    {
        return 'rosetta';
    }

    public function getLabel(): string
    {
        return '1C Rosetta';
    }

    public function form(): array
    {
        $settings = Section::make(__('phpinnacle-ferry::resources.upstream.sections.connection'))
            ->columns(2)
            ->footerActions([
                Action::make('test_upstream')
                    ->label(__('phpinnacle-ferry::resources.upstream.actions.test_upstream'))
                    ->icon('phosphor-plugs-connected')
                    ->color('gray')
                    ->disabled(fn (Get $get) => blank($get('driver')))
                    ->action(function (UpstreamFactory $tester, Section $component) {
                        $result = $tester->test($component->getContainer()->getState());
                        $notification = Notification::make()->title($result->message);

                        $result->success ? $notification->success() : $notification->danger();
                        $notification->send();
                    }),
            ])
            ->footerActionsAlignment(Alignment::End);

        return [
            $settings->schema(fn (Get $get) => [
                Select::make('driver')
                    ->label(__('phpinnacle-ferry::resources.upstream.fields.driver'))
                    ->options(Driver::class)
                    ->required()
                    ->disabledOn('edit')
                    ->dehydrated()
                    ->dehydrateStateUsing(
                        fn (Driver $state, ?Upstream $record) => $record?->config['driver'] ?? $state->value,
                    )
                    ->live()
                    ->afterStateUpdated(function (Select $component) use ($settings) {
                        $state = $component->getState();
                        $settings->clearCachedChildSchemas();
                        $settings->getChildSchemas()['default']->fill();
                        $component->state($state);
                    }),
                ...($get->enum('driver', Driver::class, isNullable: true)?->schema() ?? []),
            ]),
        ];
    }

    public function capture(Upstream $upstream): CapturePlan
    {
        /** @var array{driver: string, schema?: string|null, ...} $config */
        $config = $upstream->config;
        $replicas = $upstream->replicas()->get();
        $tables = [];
        $columns = [];
        $keys = [];
        $converterColumns = [];

        $objects = $upstream
            ->structure
            ->publishedItems()
            ->whereIn('external_id', $replicas->pluck('source')->all())
            ->get()
            ->keyBy('external_id');

        foreach ($replicas as $replica) {
            $object = $objects->get($replica->source);

            if (!$object instanceof StructureItem) {
                continue;
            }

            $table = sprintf('%s.%s', $config['schema'] ?? 'public', $object->name);

            $tables[$table] = true;

            $keyMap = $replica->mapping === []
                ? []
                : $replica->primaryKeyMap($this->destinations->resolve($replica)->getPrimaryKey());
            $sourceColumns = $this->schemas->keyPlan($replica, array_keys($keyMap))['sources'];

            if ($sourceColumns !== []) {
                $keys[$table] = sprintf('%s:%s', $table, implode(',', $sourceColumns));
            }

            foreach ($replica->mapping as $mapping) {
                if ($mapping->source !== null && $mapping->isUsed()) {
                    if ($object->field($mapping->source) !== null) {
                        $sourceColumns[] = $mapping->source;
                    }
                }
            }

            foreach ($replica->transforms as $setting) {
                foreach ($this->transforms
                    ->get($setting->type)
                    ->sourceFields($setting->data) as $column) {
                    if ($object->field($column) !== null) {
                        $sourceColumns[] = $column;
                    }
                }
            }

            foreach (array_unique($sourceColumns) as $column) {
                $qualifiedColumn = sprintf('%s.%s', $table, $column);
                $columns[$qualifiedColumn] = true;
                /** @var string|null $converter */
                $converter = $object->field($column)?->annotation('x-ferry-converter');

                if ($converter !== null) {
                    $converterColumns[$converter][$qualifiedColumn] = true;
                }
            }
        }

        /** @var string|null $schemaRegistry */
        $schemaRegistry = config('phpinnacle-ferry.schema_registry.base_uri');

        return new CapturePlan(
            $config,
            array_keys($tables),
            array_keys($columns),
            array_values($keys),
            $converterColumns,
            converters: $schemaRegistry
                ? [
                    'key.converter' => 'io.confluent.connect.avro.AvroConverter',
                    'key.converter.schema.registry.url' => $schemaRegistry,
                    'value.converter' => 'io.confluent.connect.avro.AvroConverter',
                    'value.converter.schema.registry.url' => $schemaRegistry,
                ] : [
                    'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
                    'key.converter.schemas.enable' => 'true',
                    'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
                    'value.converter.schemas.enable' => 'true',
                ],
        );
    }

    public function prepare(Upstream $upstream): StructurePlan
    {
        $source = $this->factory->make($upstream->config);

        try {
            $connection = new PdoConnection($source->getPdo());
            $storageMap = $this->loader->storageMap($connection);

            return new StructurePlan($this->loader->count($storageMap), [
                'storage_map' => $storageMap->toArray(),
                'type_map' => $this->loader->typeMap($connection)->toArray(),
            ]);
        } finally {
            $source->disconnect();
        }
    }

    /** @return list<StructureItem> */
    public function read(Upstream $upstream, StructurePlan $plan, int $offset, int $limit): array
    {
        /** @var array{storage_map: array<string, array<string, int>>, type_map: array<string, list<array<mixed>>>} $context */
        $context = $plan->context;
        $source = $this->factory->make($upstream->config);

        try {
            $objects = $this->loader->chunk(
                new PdoConnection($source->getPdo()),
                StorageMap::fromArray($context['storage_map']),
                TypeMap::fromArray($context['type_map']),
                $offset,
                $limit,
            );

            $items = array_map(new StructureConverter()->item(...), $objects);
            $schema = $source->getSchemaBuilder();

            foreach ($items as $item) {
                foreach ([$item, ...$item->children] as $object) {
                    $primaryKey = array_find(
                        $schema->getIndexes($object->name),
                        fn (array $index) => $index['primary'],
                    );
                    $object->primary_key = $primaryKey['columns'] ?? [];
                    $object->schema = Schema::fromArray([
                        ...$object->schema->keywords(),
                        'required' => array_values(array_unique([
                            ...$object->schema->effectiveRequired(),
                            ...$object->primary_key,
                        ])),
                        'x-ferry-primary' => $object->primary_key,
                    ]);
                }
            }

            return $items;
        } finally {
            $source->disconnect();
        }
    }
}
