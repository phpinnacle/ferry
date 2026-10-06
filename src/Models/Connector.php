<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasOne;
use PHPinnacle\Ferry\Enums\ConnectorStatus;

/**
 * @property string $id
 * @property string $name
 * @property array<string, string>|null $config
 * @property ConnectorStatus|null $status
 * @property string|null $error
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Connection|null $connection
 * @property-read Sync|null $sync
 */
class Connector extends Model
{
    use HasUuids;

    protected $table = 'connectors';

    protected $casts = [
        'config' => 'encrypted:array',
        'status' => ConnectorStatus::class,
        'checked_at' => 'immutable_datetime',
    ];

    protected $fillable = [
        'name',
        'config',
    ];

    protected $hidden = [
        'config',
    ];

    /** @return HasOne<Connection, $this> */
    public function connection(): HasOne
    {
        return $this->hasOne(Connection::class, 'connector_id');
    }

    /** @param array<string, string> $config */
    public function recordConfig(array $config): void
    {
        $this->config = $config;
        $this->save();
    }

    public function recordStatus(ConnectorStatus $status, ?string $error = null): void
    {
        $this->status = $status;
        $this->error = $error;
        $this->checked_at = CarbonImmutable::now();
        $this->save();
    }

    /** @return HasOne<Sync, $this> */
    public function sync(): HasOne
    {
        return $this->hasOne(Sync::class, 'connector_id');
    }
}
