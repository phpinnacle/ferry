<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Casts\JsonSchemaCast;
use PHPinnacle\Ferry\Casts\MappingCast;
use PHPinnacle\Ferry\Casts\TransformsCast;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\Concerns\HasConnector;
use PHPinnacle\Ferry\Observers\ReplicaObserver;
use PHPinnacle\Ferry\Services\ReplicaTableManager;
use PHPinnacle\Ferry\Services\TransformRegistry;

/**
 * @property string $id
 * @property string|null $connector_id
 * @property string $upstream_id
 * @property string $name
 * @property string $code
 * @property ReplicaStatus $status
 * @property DestinationType $type
 * @property string $source
 * @property string $destination
 * @property JsonSchema $schema
 * @property list<FieldMapping> $mapping
 * @property list<TransformSetting> $transforms
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Upstream $upstream
 * @property-read Connector|null $connector
 */
#[ObservedBy(ReplicaObserver::class)]
class Replica extends Model
{
    use HasConnector;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'replicas';

    protected $attributes = [
        'status' => ReplicaStatus::Pending->value,
        'type' => DestinationType::Dynamic->value,
        'transforms' => '[]',
        'mapping' => '[]',
    ];

    protected $casts = [
        'status' => ReplicaStatus::class,
        'type' => DestinationType::class,
        'mapping' => MappingCast::class,
        'schema' => JsonSchemaCast::class,
        'transforms' => TransformsCast::class,
    ];

    protected $fillable = [
        'upstream_id',
        'name',
        'code',
        'type',
        'destination',
        'source',
        'mapping',
        'schema',
        'transforms',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $replica) {
            if (!array_key_exists('schema', $replica->getAttributes())) {
                $replica->schema = $replica->sourceObject()->schema;
            }
            if ($replica->type === DestinationType::Dynamic) {
                $replica->destination = Config::string('phpinnacle-ferry.replica.table_prefix') . $replica->code;
            }
        });
    }

    /** @param array{touch?: bool|null} $options */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options) {
            Upstream::withTrashed()->whereKey($this->upstream_id)->lockForUpdate()->first();

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function () {
            Upstream::withTrashed()->whereKey($this->upstream_id)->lockForUpdate()->first();

            return parent::delete();
        });
    }

    public function isRunning(): bool
    {
        return $this->status === ReplicaStatus::Active && $this->connector?->status === ConnectorStatus::Running;
    }

    /** @internal  */
    public function activate(): void
    {
        $this->status = ReplicaStatus::Active;

        if ($this->isDirty()) {
            $this->save();
        }
    }

    /** @return BelongsTo<Upstream, $this> */
    public function upstream(): BelongsTo
    {
        return $this->belongsTo(Upstream::class, 'upstream_id');
    }

    /** @internal */
    public function pause(): void
    {
        $this->status = ReplicaStatus::Pause;

        if ($this->isDirty()) {
            $this->save();
        }
    }

    public function transformedSchema(): ?JsonSchema
    {
        return app(TransformRegistry::class)->resultSchema(
            $this->schema,
            $this->transforms,
            null,
            TransformScope::Record,
        );
    }

    public function outputSchema(FieldMapping $mapping, ?JsonSchema $schema = null): ?JsonSchema
    {
        $schema ??= $this->transformedSchema();

        return $schema === null ? null : app(TransformRegistry::class)->mappingSchema($mapping, $schema);
    }

    /** @return list<string> */
    public function droppedColumns(): array
    {
        return $this->mappingChanges()['drop'];
    }

    /** @return array{drop: list<string>, columns: list<array{mapping: FieldMapping, from: string|null}>, alter: list<FieldMapping>} */
    public function mappingChanges(): array
    {
        $changes = ['drop' => [], 'columns' => [], 'alter' => []];

        if ($this->type === DestinationType::Static) {
            return $changes;
        }

        /** @var list<FieldMapping> $original */
        $original = $this->getOriginal('mapping');
        /** @var JsonSchema $originalSchema */
        $originalSchema = $this->getOriginal('schema');
        /** @var list<TransformSetting> $originalTransforms */
        $originalTransforms = $this->getOriginal('transforms');
        $originalSchema = app(TransformRegistry::class)->resultSchema(
            $originalSchema,
            $originalTransforms,
            null,
            TransformScope::Record,
        );
        $previous = [];
        foreach ($original as $mapping) {
            if ($mapping->target !== null) {
                $previous[$mapping->identity()] = $mapping;
            }
        }
        $next = [];
        foreach ($this->mapping as $mapping) {
            if ($mapping->target !== null) {
                $next[$mapping->identity()] = $mapping;
            }
        }
        $nextSchema = $this->transformedSchema();
        $previousOutputs = array_map(fn (FieldMapping $mapping) => $this->outputSchema(
            $mapping,
            $originalSchema,
        ), $previous);
        $nextOutputs = array_map(fn (FieldMapping $mapping) => $this->outputSchema($mapping, $nextSchema), $next);
        foreach ($previous as $identity => $mapping) {
            if (
                !array_key_exists($identity, $next)
                || ReplicaTableManager::columnType($nextOutputs[$identity]) !== ReplicaTableManager::columnType(
                    $previousOutputs[$identity],
                )
            ) {
                /** @var string $target */
                $target = $mapping->target;
                $changes['drop'][] = $target;
            }
        }

        foreach ($next as $identity => $mapping) {
            $existing = $previous[$identity] ?? null;
            $previousOutput = $previousOutputs[$identity] ?? null;
            $nextOutput = $nextOutputs[$identity];
            $previousType = ReplicaTableManager::columnType($previousOutput);
            $nextType = ReplicaTableManager::columnType($nextOutput);

            if (
                $existing === null
                || $previousType !== $nextType
            ) {
                $changes['columns'][] = ['mapping' => $mapping, 'from' => null];
            } elseif ($existing->target !== $mapping->target) {
                $changes['columns'][] = ['mapping' => $mapping, 'from' => $existing->target];
            }

            if (
                $existing !== null
                && $previousType === 'string'
                && $nextType === 'string'
                && $previousOutput?->maxLength() !== $nextOutput?->maxLength()
            ) {
                $changes['alter'][] = $mapping;
            }
        }

        return $changes;
    }

    /** @return array<string, string> */
    public function columnMap(StructureItem $object): array
    {
        $columns = [];

        foreach ($this->mapping as $mapping) {
            if (
                $mapping->target !== null
                && $mapping->source !== null
            ) {
                $columns[$mapping->source] = $mapping->target;
            }
        }

        return $columns;
    }

    public function sourceObject(): StructureItem
    {
        return $this->upstream->structure->publishedItems()->where('external_id', $this->source)->firstOrFail();
    }

    /** @return list<string> */
    public function primaryKeySources(StructureItem $object): array
    {
        /** @var list<string> */
        return $this->transformedSchema()?->annotation('x-primary-key') ?? $object->primary_key;
    }

    /** @return array<string, string> */
    public function primaryKeyMap(StructureItem $object): array
    {
        $columns = $this->columnMap($object);
        $keys = [];
        foreach ($this->primaryKeySources($object) as $column) {
            $keys[$column] = $columns[$column];
        }

        return $keys;
    }

    /** @return list<string> */
    public function constantColumns(): array
    {
        $columns = [];

        foreach ($this->mapping as $mapping) {
            if ($mapping->source === null && $mapping->target !== null) {
                /** @var string $target */
                $target = $mapping->target;
                $columns[] = $target;
            }
        }

        return $columns;
    }
}
