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
use PHPinnacle\Ferry\Models\Concerns\HasConnector;
use PHPinnacle\Ferry\Observers\ReplicaObserver;

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

    /** @return array<string, string> */
    public function columnMap(): array
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

    /**
     * @param list<string> $sources
     * @return array<string, string>
     */
    public function primaryKeyMap(array $sources): array
    {
        $columns = $this->columnMap();
        $keys = [];
        foreach ($sources as $column) {
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
