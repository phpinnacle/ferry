<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Models\Concerns\HasConnector;
use PHPinnacle\Ferry\Observers\UpstreamObserver;

/**
 * @property string $id
 * @property string|null $connector_id
 * @property string $name
 * @property string $code
 * @property string $origin
 * @property string|null $adapter
 * @property Driver $driver
 * @property array<string, mixed> $config
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Structure $structure
 * @property-read Collection<int, Replica> $replicas
 * @property-read Connector|null $connector
 */
#[ObservedBy(UpstreamObserver::class)]
class Upstream extends Model implements HasLabel
{
    use HasConnector;
    use HasUuids;
    use SoftDeletes;

    private const array CREDENTIAL_ATTRIBUTES = ['driver', 'config'];

    protected $table = 'upstreams';

    protected $attributes = [
        'is_active' => true,
        'adapter' => null,
    ];

    protected $casts = [
        'driver' => Driver::class,
        'config' => 'encrypted:array',
        'is_active' => 'bool',
    ];

    protected $hidden = ['config'];

    protected $fillable = [
        'name',
        'code',
        'origin',
        'adapter',
        'driver',
        'config',
        'is_active',
    ];

    /** @return Collection<int, Replica> */
    public function trackedReplicas(): Collection
    {
        return $this->replicas()->get();
    }

    /** @param array{touch?: bool|null} $options */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options) {
            if ($this->exists) {
                self::withTrashed()->whereKey($this->id)->lockForUpdate()->first();
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function () {
            self::withTrashed()->whereKey($this->id)->lockForUpdate()->first();

            return parent::delete();
        });
    }

    public function credentialsChanged(): bool
    {
        return $this->wasChanged(self::CREDENTIAL_ATTRIBUTES);
    }

    /**
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        return [...$this->config, 'driver' => $this->driver->value];
    }

    public function getLabel(): string
    {
        return $this->name;
    }

    public function isInUse(): bool
    {
        return $this->replicas()->exists();
    }

    /** @return HasMany<Replica, $this> */
    public function replicas(): HasMany
    {
        return $this->hasMany(Replica::class, 'upstream_id');
    }

    public function toggleActive(): void
    {
        $this->is_active = !$this->is_active;
        $this->save();
    }

    /** @return HasOne<Structure, $this> */
    public function structure(): HasOne
    {
        return $this->hasOne(Structure::class, 'upstream_id');
    }
}
