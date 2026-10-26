<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Observers\ConnectorObserver;

/**
 * @property string $id
 * @property string $name
 * @property string $origin
 * @property string $upstream_id
 * @property array<string, mixed>|null $config
 * @property array<string, mixed>|null $applied_config
 * @property ConnectorStatus|null $status
 * @property string|null $error
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[ObservedBy(ConnectorObserver::class)]
class Connector extends Model
{
    use HasUuids;

    protected $table = 'connectors';

    protected $casts = [
        'config' => 'encrypted:array',
        'applied_config' => 'encrypted:array',
        'status' => ConnectorStatus::class,
        'checked_at' => 'immutable_datetime',
    ];

    protected $fillable = [
        'name',
        'origin',
        'config',
    ];

    protected $hidden = [
        'config',
        'applied_config',
    ];

    /** @param array{touch?: bool|null} $options */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    /** @param array<string, mixed> $config */
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

    public function markApplied(): void
    {
        $this->applied_config = $this->config;
        $this->saveQuietly();
    }
}
