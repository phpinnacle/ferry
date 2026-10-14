<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PHPinnacle\Ferry\Casts\SchemaCast;
use PHPinnacle\Ferry\Casts\TransformsCast;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\SyncStatus;
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
 * @property list<TransformSetting> $transforms
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Connection $connection
 * @property-read Connector|null $connector
 */
#[ObservedBy(SyncObserver::class)]
class Sync extends Model
{
    use HasUuids;

    public const string ID_COLUMN = 'idrref';

    public const array RESERVED_COLUMNS = [self::ID_COLUMN];

    protected $table = 'syncs';

    protected $attributes = [
        'status' => SyncStatus::Pending->value,
        'type' => DestinationType::Dynamic->value,
        'transforms' => '[]',
    ];

    protected $casts = [
        'status' => SyncStatus::class,
        'type' => DestinationType::class,
        'is_paused' => 'bool',
        'schema' => SchemaCast::class,
        'transforms' => TransformsCast::class,
    ];

    protected $fillable = [
        'connection_id',
        'name',
        'code',
        'type',
        'destination',
        'source',
        'schema',
        'transforms',
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

    public function recordConnector(Connector $connector): void
    {
        $this->connector()->associate($connector);

        if ($this->isDirty('connector_id')) {
            $this->saveQuietly();
        }
    }

    /** @return BelongsTo<Connector, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class, 'connector_id');
    }

    /**
     * @param list<DestinationField>|null $destinationFields
     * @return list<string>
     */
    public function brokenColumns(?ConnectionMetadata $object, ?array $destinationFields = null): array
    {
        if ($object === null || $this->type === DestinationType::Static && $destinationFields === null) {
            return array_map(static fn (FieldMapping $mapping) => $mapping->column, $this->schema);
        }

        $fields = array_column($destinationFields ?? [], null, 'id');
        $columns = [];
        $destinationColumns = [];

        foreach ($this->schema as $mapping) {
            $type = $mapping->type();

            if ($mapping->source !== null) {
                $field = $object->field($mapping->source);

                if ($field === null || ColumnType::fromField($field) !== $mapping->sourceType()) {
                    $columns[] = $mapping->column;
                }
            }

            if ($destinationFields !== null && ($fields[$mapping->column] ?? null)?->type !== $type) {
                $destinationColumns[] = $mapping->column;
            }

            unset($fields[$mapping->column]);
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
        if ($this->type === DestinationType::Static) {
            return [];
        }

        /** @var list<FieldMapping> $previous */
        $previous = $this->getOriginal('schema');
        $next = array_combine(
            array_map(static fn (FieldMapping $mapping) => $mapping->identity(), $this->schema),
            $this->schema,
        );
        $columns = [];

        foreach ($previous as $mapping) {
            $replacement = $next[$mapping->identity()] ?? null;

            if ($replacement === null || $replacement->type() !== $mapping->type()) {
                $columns[] = $mapping->column;
            }
        }

        return $columns;
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

            if ($column !== null && $column !== ConnectionMetadata::KEY_COLUMN) {
                $columns[$column] = $mapping->column;
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
                $columns[] = $mapping->column;
            }
        }

        return $columns;
    }
}
