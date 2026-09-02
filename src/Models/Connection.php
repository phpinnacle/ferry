<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Casts\StorageMapCast;
use PHPinnacle\Ferry\Casts\TypeMapCast;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Jobs\PrepareStructureJob;
use PHPinnacle\Ferry\Services\ConnectionTestResult;
use PHPinnacle\Ferry\Support\ConnectionErrorFormatter;
use PHPinnacle\Rosetta\StorageMap;
use PHPinnacle\Rosetta\TypeMap;

/**
 * @property string $id
 * @property string $name
 * @property string $code
 * @property Driver $driver
 * @property string $host
 * @property int $port
 * @property string $database
 * @property string $username
 * @property string $password
 * @property string|null $schema
 * @property string|null $ssl_mode
 * @property bool $is_active
 * @property CarbonImmutable|null $last_tested_at
 * @property bool|null $last_test_passed
 * @property StructureStatus $status
 * @property int $generation
 * @property string|null $run_id
 * @property int $processed
 * @property int $total
 * @property StorageMap|null $storage_map
 * @property TypeMap|null $type_map
 * @property CarbonImmutable|null $published_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $heartbeat_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, ConnectionMetadata> $metadata
 */
class Connection extends Model implements HasLabel
{
    use HasUuids;

    private const array CREDENTIAL_ATTRIBUTES = [
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'schema',
        'ssl_mode',
    ];

    public $timestamps = true;

    protected $table = 'connections';

    protected $attributes = [
        'is_active' => true,
        'status' => StructureStatus::Pending->value,
        'generation' => 0,
    ];

    protected $casts = [
        'driver' => Driver::class,
        'port' => 'integer',
        'password' => 'encrypted',
        'is_active' => 'bool',
        'last_tested_at' => 'immutable_datetime',
        'last_test_passed' => 'bool',
        'status' => StructureStatus::class,
        'generation' => 'integer',
        'processed' => 'integer',
        'total' => 'integer',
        'storage_map' => StorageMapCast::class,
        'type_map' => TypeMapCast::class,
        'published_at' => 'immutable_datetime',
        'heartbeat_at' => 'immutable_datetime',
    ];

    protected $fillable = [
        'name',
        'code',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'schema',
        'ssl_mode',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $connection) {
            $connection->beginStructureRun();
        });

        static::created(function (self $connection) {
            $connection->dispatchStructurePreparation();
        });

        static::updating(function (self $connection) {
            if (!$connection->isDirty(self::CREDENTIAL_ATTRIBUTES)) {
                return;
            }

            $connection->beginStructureRun();
        });

        static::updated(function (self $connection) {
            if ($connection->wasChanged(self::CREDENTIAL_ATTRIBUTES)) {
                $connection->discardDraftMetadata();
                $connection->dispatchStructurePreparation();
            }
        });
    }

    public function canPrepareStructure(): bool
    {
        return $this->status !== StructureStatus::Preparing || $this->structureIsStale();
    }

    /**
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        return [
            'driver' => $this->driver->value,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'schema' => $this->schema,
            'ssl_mode' => $this->ssl_mode,
        ];
    }

    public function draftRevision(): int
    {
        return $this->generation + 1;
    }

    public function failStructure(?string $error = null): bool
    {
        $failed = $this->updateCurrentRun([
            'status' => StructureStatus::Failed,
            'run_id' => null,
            'storage_map' => null,
            'type_map' => null,
            'last_error' => $error,
        ]);

        if (!$failed) {
            return false;
        }

        $this->discardDraftMetadata();

        return true;
    }

    public function getLabel(): string
    {
        return $this->name;
    }

    public function isInUse(): bool
    {
        return false;
    }

    /** @return HasMany<ConnectionMetadata, $this> */
    public function metadata(): HasMany
    {
        return $this->hasMany(ConnectionMetadata::class, 'connection_id');
    }

    public function planStructure(StorageMap $storageMap, TypeMap $typeMap, int $total): bool
    {
        return $this->updateCurrentRun([
            'storage_map' => $storageMap,
            'type_map' => $typeMap,
            'processed' => 0,
            'total' => $total,
        ]);
    }

    public function prepareStructure(): bool
    {
        if (!$this->canPrepareStructure()) {
            return false;
        }

        if ($this->status === StructureStatus::Preparing) {
            $this->failStructure(ConnectionErrorFormatter::stale());
        }

        $this->startStructure();
        $this->dispatchStructurePreparation();

        return true;
    }

    public function progressStructureTo(int $processed): bool
    {
        return $this->updateCurrentRun([
            'processed' => $processed,
        ]);
    }

    /** @return Builder<ConnectionMetadata> */
    public function publishedMetadata(): Builder
    {
        return $this
            ->metadata()
            ->getQuery()
            ->where('revision', $this->generation)
            ->whereNull('parent_id')
            ->orderBy('position');
    }

    public function publishStructure(): void
    {
        $revision = $this->draftRevision();

        $this->status = StructureStatus::Ready;
        $this->generation = $revision;
        $this->run_id = null;
        $this->storage_map = null;
        $this->type_map = null;
        $this->last_error = null;
        $this->published_at = CarbonImmutable::now();
        $this->heartbeat_at = CarbonImmutable::now();
        $this->save();

        $this
            ->metadata()
            ->where('revision', '!=', $revision)
            ->delete();
    }

    public function recordTestResult(ConnectionTestResult $result): void
    {
        $this->last_tested_at = CarbonImmutable::now();
        $this->last_test_passed = $result->success;
        $this->save();
    }

    public function toggleActive(): void
    {
        $this->is_active = !$this->is_active;
        $this->save();
    }

    private function beginStructureRun(): void
    {
        $this->status = StructureStatus::Preparing;
        $this->run_id = $this->newUniqueId();
        $this->processed = 0;
        $this->total = 0;
        $this->storage_map = null;
        $this->type_map = null;
        $this->heartbeat_at = CarbonImmutable::now();
    }

    /** @return Builder<self> */
    private function currentRun(): Builder
    {
        return self::query()
            ->whereKey($this->getKey())
            ->where('status', StructureStatus::Preparing)
            ->where('run_id', $this->run_id);
    }

    private function discardDraftMetadata(): void
    {
        $this
            ->metadata()
            ->where('revision', '>', $this->generation)
            ->delete();
    }

    private function dispatchStructurePreparation(): void
    {
        if ($this->run_id === null) {
            return;
        }

        PrepareStructureJob::dispatch($this->id, $this->run_id)->afterCommit();
    }

    private function startStructure(): void
    {
        $this->discardDraftMetadata();
        $this->beginStructureRun();
        $this->save();
    }

    private function structureIsStale(): bool
    {
        $heartbeat = $this->heartbeat_at;

        if ($heartbeat === null) {
            return true;
        }

        return $heartbeat->lte(CarbonImmutable::now()->subSeconds(
            Config::integer('phpinnacle-ferry.structure.stale_after'),
        ));
    }

    /**
     *
     * @param  array<string, mixed>  $attributes
     */
    private function updateCurrentRun(array $attributes): bool
    {
        $attributes['heartbeat_at'] = CarbonImmutable::now();

        $currentRun = $this->currentRun();

        $this->forceFill($attributes);

        $updated = $currentRun->update(
            array_intersect_key($this->getAttributes(), $attributes),
        );

        $this->refresh();

        return $updated === 1;
    }
}
