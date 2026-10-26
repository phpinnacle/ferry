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
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
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

    public function outputSchema(FieldMapping $mapping, ?JsonSchema $schema = null): ?JsonSchema
    {
        return app(TransformRegistry::class)->mappingSchema($mapping, $schema ?? $this->schema);
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
        $previous = array_filter($original, static fn (FieldMapping $mapping) => $mapping->target !== null);
        $schema = array_filter($this->mapping, static fn (FieldMapping $mapping) => $mapping->target !== null);
        $next = array_combine(
            array_map(static fn (FieldMapping $mapping) => $mapping->identity(), $schema),
            $schema,
        );
        foreach ($previous as $mapping) {
            $replacement = $next[$mapping->identity()] ?? null;

            if (
                $replacement === null
                || ReplicaTableManager::columnType($this->outputSchema(
                    $replacement,
                )) !== ReplicaTableManager::columnType(
                    $this->outputSchema($mapping, $originalSchema),
                )
            ) {
                /** @var string $target */
                $target = $mapping->target;
                $changes['drop'][] = $target;
            }
        }

        $previous = array_combine(
            array_map(static fn (FieldMapping $mapping) => $mapping->identity(), $previous),
            $previous,
        );

        foreach ($schema as $mapping) {
            $existing = $previous[$mapping->identity()] ?? null;
            $previousOutput = $existing === null ? null : $this->outputSchema($existing, $originalSchema);
            $nextOutput = $this->outputSchema($mapping);
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
        return $this->schema->annotation('x-primary-key') ?? $object->primary_key;
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
