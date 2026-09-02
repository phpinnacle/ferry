<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PHPinnacle\Ferry\Casts\PropertiesCast;
use PHPinnacle\Ferry\Casts\SystemCast;
use PHPinnacle\Ferry\Casts\ValuesCast;
use PHPinnacle\Rosetta\Contracts\Field;
use PHPinnacle\Rosetta\Data\EnumerationValue;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\MetadataKind;

/**
 * @property string $id
 * @property string $connection_id
 * @property string|null $parent_id
 * @property string $external_id
 * @property string|null $reference_id
 * @property string $name
 * @property int $code
 * @property MetadataKind $kind
 * @property string $label
 * @property string $title
 * @property array<string, Field> $system
 * @property list<MetadataProperty> $properties
 * @property list<EnumerationValue> $values
 * @property int $position
 * @property int $revision
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Connection $connection
 * @property-read ConnectionMetadata|null $parent
 * @property-read Collection<int, ConnectionMetadata> $children
 */
class ConnectionMetadata extends Model
{
    use HasUuids;

    protected $table = 'connection_metadata';

    protected $casts = [
        'code' => 'integer',
        'kind' => MetadataKind::class,
        'system' => SystemCast::class,
        'properties' => PropertiesCast::class,
        'values' => ValuesCast::class,
        'position' => 'integer',
        'revision' => 'integer',
    ];

    protected $fillable = [
        'connection_id',
        'parent_id',
        'external_id',
        'reference_id',
        'name',
        'code',
        'kind',
        'label',
        'title',
        'system',
        'properties',
        'values',
        'position',
        'revision',
    ];

    /** @return HasMany<ConnectionMetadata, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(ConnectionMetadata::class, 'parent_id');
    }

    /** @return BelongsTo<Connection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /** @return BelongsTo<ConnectionMetadata, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ConnectionMetadata::class, 'parent_id');
    }
}
