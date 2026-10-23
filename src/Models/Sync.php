<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PHPinnacle\Ferry\Casts\SchemaCast;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Concerns\HasConnector;
use PHPinnacle\Ferry\Observers\SyncObserver;

/**
 * @property string $id
 * @property string|null $connector_id
 * @property string $connection_id
 * @property string $name
 * @property string $code
 * @property SyncStatus $status
 * @property bool $is_paused
 * @property DestinationType $type
 * @property string $source
 * @property string $destination
 * @property list<FieldMapping> $schema
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Connection $connection
 * @property-read Connector|null $connector
 */
#[ObservedBy(SyncObserver::class)]
class Sync extends Model
{
    use HasConnector;
    use HasUuids;

    public const string ID_COLUMN = 'idrref';

    public const array RESERVED_COLUMNS = [self::ID_COLUMN];

    protected $table = 'syncs';

    protected $attributes = [
        'status' => SyncStatus::Pending->value,
        'type' => DestinationType::Dynamic->value,
    ];

    protected $casts = [
        'status' => SyncStatus::class,
        'type' => DestinationType::class,
        'is_paused' => 'bool',
        'schema' => SchemaCast::class,
    ];

    protected $fillable = [
        'connection_id',
        'name',
        'code',
        'type',
        'destination',
        'source',
        'schema',
    ];

    /** @param array{touch?: bool|null} $options */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    /** @internal  */
    public function activate(): void
    {
        $this->status = SyncStatus::Active;
        $this->is_paused = false;

        if ($this->isDirty()) {
            $this->save();
        }
    }

    /** @return BelongsTo<Connection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /** @internal */
    public function pause(bool $manually = true): void
    {
        $this->status = SyncStatus::Pause;
        $this->is_paused = $manually;

        if ($this->isDirty()) {
            $this->save();
        }
    }

    /**
     * @param list<DestinationField>|null $destinationFields
     * @return list<string>
     */
    public function brokenColumns(?ConnectionMetadata $object, ?array $destinationFields = null): array
    {
        if ($object === null || $this->type === DestinationType::Static && $destinationFields === null) {
            /** @var list<string> $columns */
            $columns = array_map(
                static fn (FieldMapping $mapping) => $mapping->target ?? $mapping->source,
                array_values(array_filter($this->schema, static fn (FieldMapping $mapping) => $mapping->isUsed())),
            );

            return $columns;
        }

        $fields = array_column($destinationFields ?? [], null, 'id');
        $columns = [];
        $destinationColumns = [];

        foreach ($this->schema as $mapping) {
            if (!$mapping->isUsed()) {
                continue;
            }

            $type = $mapping->type();

            if ($mapping->source !== null) {
                $field = $object->field($mapping->source);

                if ($field === null || ColumnType::fromField($field) !== $mapping->sourceType()) {
                    $columns[] = $mapping->target ?? $mapping->source;
                }
            }

            if (
                $mapping->target !== null
                && $destinationFields !== null
                && ($fields[$mapping->target] ?? null)?->type !== $type
            ) {
                $destinationColumns[] = $mapping->target;
            }

            if ($mapping->target !== null) {
                unset($fields[$mapping->target]);
            }
        }

        foreach ($fields as $field) {
            if ($field->required) {
                $destinationColumns[] = $field->id;
            }
        }

        return array_values(array_unique([...$columns, ...$destinationColumns]));
    }

    /** @param list<DestinationField>|null $destinationFields */
    public function hasValidSchema(ConnectionMetadata $object, ?array $destinationFields = null): bool
    {
        return $this->brokenColumns($object, $destinationFields) === [];
    }

    /** @return list<string> */
    public function droppedColumns(): array
    {
        return $this->schemaChanges()['drop'];
    }

    /** @return array{drop: list<string>, columns: list<array{mapping: FieldMapping, from: string|null}>} */
    public function schemaChanges(): array
    {
        $changes = ['drop' => [], 'columns' => []];

        if ($this->type === DestinationType::Static) {
            return $changes;
        }

        /** @var list<FieldMapping> $original */
        $original = $this->getOriginal('schema');
        $previous = array_values(array_filter(
            $original,
            static fn (FieldMapping $mapping) => $mapping->target !== null,
        ));
        $schema = array_values(array_filter(
            $this->schema,
            static fn (FieldMapping $mapping) => $mapping->target !== null,
        ));
        $next = array_combine(
            array_map(static fn (FieldMapping $mapping) => $mapping->identity(), $schema),
            $schema,
        );
        foreach ($previous as $mapping) {
            $replacement = $next[$mapping->identity()] ?? null;

            if ($replacement === null || $replacement->type() !== $mapping->type()) {
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

            if ($existing === null || $existing->type() !== $mapping->type()) {
                $changes['columns'][] = ['mapping' => $mapping, 'from' => null];
            } elseif ($existing->target !== $mapping->target) {
                $changes['columns'][] = ['mapping' => $mapping, 'from' => $existing->target];
            }
        }

        return $changes;
    }

    /** @param list<DestinationField>|null $destinationFields */
    public function shouldResume(ConnectionMetadata $object, ?array $destinationFields = null): bool
    {
        if ($this->connector === null) {
            return false;
        }

        if ($this->status === SyncStatus::Active) {
            return true;
        }

        return (
            $this->status === SyncStatus::Pause
            && !$this->is_paused
            && $this->hasValidSchema($object, $destinationFields)
        );
    }

    /** @return array<string, string> */
    public function columnMap(ConnectionMetadata $object, string $keyColumn = self::ID_COLUMN): array
    {
        $columns = [ConnectionMetadata::KEY_COLUMN => $keyColumn];

        foreach ($this->schema as $mapping) {
            $column = $mapping->source !== null ? $object->physicalColumn($mapping->source) : null;

            if ($mapping->target !== null && $column !== null && $column !== ConnectionMetadata::KEY_COLUMN) {
                $columns[$column] = $mapping->target;
            }
        }

        return $columns;
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
