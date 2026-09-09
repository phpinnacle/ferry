<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PHPinnacle\Ferry\Casts\SchemaCast;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Observers\SyncConnectorObserver;
use PHPinnacle\Ferry\Observers\SyncDestinationObserver;
use PHPinnacle\Ferry\Observers\SyncTableObserver;

/**
 * @property string $id
 * @property string $connection_id
 * @property string $name
 * @property string $code
 * @property SyncStatus $status
 * @property bool $is_paused
 * @property string|null $static_destination
 * @property string $source
 * @property string $destination
 * @property list<FieldMapping> $schema
 * @property ConnectorStatus|null $sink_connector_status
 * @property string|null $sink_connector_error
 * @property CarbonImmutable|null $sink_connector_checked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Connection $connection
 */
#[ObservedBy([SyncConnectorObserver::class, SyncDestinationObserver::class, SyncTableObserver::class])]
class Sync extends Model
{
    use HasUuids;

    protected $table = 'syncs';

    protected $attributes = [
        'status' => SyncStatus::Pending->value,
    ];

    protected $casts = [
        'status' => SyncStatus::class,
        'is_paused' => 'bool',
        'schema' => SchemaCast::class,
        'sink_connector_status' => ConnectorStatus::class,
        'sink_connector_checked_at' => 'immutable_datetime',
    ];

    protected $fillable = [
        'connection_id',
        'name',
        'code',
        'static_destination',
        'source',
        'schema',
    ];

    /** @internal  */
    public function activate(): void
    {
        $this->status = SyncStatus::Active;
        $this->is_paused = false;
        $this->save();
    }

    /** @return BelongsTo<Connection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    public function destinationType(): DestinationType
    {
        return $this->static_destination === null ? DestinationType::Dynamic : DestinationType::Static;
    }

    /** @internal */
    public function pause(bool $manually = true): void
    {
        $this->status = SyncStatus::Pause;
        $this->is_paused = $manually;
        $this->save();
    }

    public function recordSinkConnectorStatus(ConnectorStatus $status, ?string $error = null): void
    {
        $this->sink_connector_status = $status;
        $this->sink_connector_error = $error;
        $this->sink_connector_checked_at = CarbonImmutable::now();
        $this->save();
    }
}
