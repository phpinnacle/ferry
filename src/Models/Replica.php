<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Casts\SchemaCast;
use PHPinnacle\Ferry\Casts\TransformsCast;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Models\Concerns\HasConnector;
use PHPinnacle\Ferry\Observers\ReplicaObserver;
use PHPinnacle\Ferry\Services\ReplicaTableManager;

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
 * @property list<FieldMapping> $schema
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
    ];

    protected $casts = [
        'status' => ReplicaStatus::class,
        'type' => DestinationType::class,
        'schema' => SchemaCast::class,
        'transforms' => TransformsCast::class,
    ];

    protected $fillable = [
        'upstream_id',
        'name',
        'code',
        'type',
        'destination',
        'source',
        'schema',
        'transforms',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $replica) {
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

    /** @return list<string> */
    public function brokenColumns(?StructureItem $object, ?StaticDestination $destination = null): array
    {
        if ($object === null || $this->type === DestinationType::Static && $destination === null) {
            /** @var list<string> */
            return array_map(
                static fn (FieldMapping $mapping) => $mapping->target ?? $mapping->source,
                array_values(array_filter($this->schema, static fn (FieldMapping $mapping) => $mapping->isUsed())),
            );
        }

        $schema = JsonSchema::fromArray($destination?->getSchema()->keywords() ?? []);
        $fields = $schema->properties();
        $columns = [];
        $destinationColumns = [];

        foreach ($this->schema as $mapping) {
            if (!$mapping->isUsed()) {
                continue;
            }

            if ($mapping->source !== null) {
                $field = $object->field($mapping->source);

                if ($field === null || !$mapping->sourceSchema()?->equals($field)) {
                    $columns[] = $mapping->target ?? $mapping->source;
                }
            }

            if ($mapping->target !== null && $destination !== null) {
                $destinationField = $fields[$mapping->target] ?? null;
                if (
                    $destinationField === null
                    || !$mapping->canMapTo($destinationField)
                    || in_array($mapping->target, $destination->getPrimaryKey(), true)
                    && !in_array($mapping->source, $object->primary_key, true)
                ) {
                    $destinationColumns[] = $mapping->target;
                }
            }

            if ($mapping->target !== null) {
                unset($fields[$mapping->target]);
            }
        }

        foreach ($schema->required() as $column) {
            if (array_key_exists($column, $fields)) {
                $destinationColumns[] = $column;
            }
        }

        if ($this->type === DestinationType::Dynamic) {
            $columns = [...$columns, ...array_diff($object->primary_key, array_keys($this->columnMap($object)))];
        }

        return array_values(array_unique([...$columns, ...$destinationColumns]));
    }

    public function hasValidSchema(StructureItem $object, ?StaticDestination $destination = null): bool
    {
        return (
            ($this->type !== DestinationType::Dynamic || $object->primary_key !== [])
            && $this->brokenColumns($object, $destination) === []
        );
    }

    /** @return list<string> */
    public function droppedColumns(): array
    {
        return $this->schemaChanges()['drop'];
    }

    /** @return array{drop: list<string>, columns: list<array{mapping: FieldMapping, from: string|null}>, alter: list<FieldMapping>} */
    public function schemaChanges(): array
    {
        $changes = ['drop' => [], 'columns' => [], 'alter' => []];

        if ($this->type === DestinationType::Static) {
            return $changes;
        }

        /** @var list<FieldMapping> $original */
        $original = $this->getOriginal('schema');
        $previous = array_filter($original, static fn (FieldMapping $mapping) => $mapping->target !== null);
        $schema = array_filter($this->schema, static fn (FieldMapping $mapping) => $mapping->target !== null);
        $next = array_combine(
            array_map(static fn (FieldMapping $mapping) => $mapping->identity(), $schema),
            $schema,
        );
        foreach ($previous as $mapping) {
            $replacement = $next[$mapping->identity()] ?? null;

            if (
                $replacement === null
                || ReplicaTableManager::columnType($replacement->outputSchema()) !== ReplicaTableManager::columnType(
                    $mapping->outputSchema(),
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
            $previousType = ReplicaTableManager::columnType($existing?->outputSchema());
            $nextType = ReplicaTableManager::columnType($mapping->outputSchema());

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
                && $existing->stringLength() !== $mapping->stringLength()
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

        foreach ($this->schema as $mapping) {
            if (
                $mapping->target !== null
                && $mapping->source !== null
                && $object->field($mapping->source) !== null
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

    /** @return array<string, string> */
    public function primaryKeyMap(StructureItem $object): array
    {
        $columns = $this->columnMap($object);
        $keys = [];
        foreach ($object->primary_key as $column) {
            $keys[$column] = $columns[$column];
        }

        return $keys;
    }

    /** @return list<string> */
    public function constantColumns(): array
    {
        $columns = [];

        foreach ($this->schema as $mapping) {
            if ($mapping->source === null) {
                /** @var string $target */
                $target = $mapping->target;
                $columns[] = $target;
            }
        }

        return $columns;
    }
}
