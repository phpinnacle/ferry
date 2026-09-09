<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;
use PHPinnacle\Ferry\Casts\PropertiesCast;
use PHPinnacle\Ferry\Casts\SystemCast;
use PHPinnacle\Ferry\Casts\ValuesCast;
use PHPinnacle\Ferry\Enums\ColumnType;
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

    public const string KEY_COLUMN = '_idrref';

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

    public function displayTitle(): string
    {
        return self::firstFilled($this->title, $this->label, $this->name);
    }

    public function field(string $source): ?Field
    {
        $field = $this->system[$source] ?? null;

        if ($field !== null) {
            return $field;
        }

        foreach ($this->properties as $property) {
            if ($property->id === $source) {
                return $property->field;
            }
        }

        return null;
    }

    /**
     * @return list<array{source: string, title: string, physical: string, type: ColumnType}>
     */
    public function mappableFields(bool $withKey = true): array
    {
        $fields = [];

        foreach ($this->system as $name => $field) {
            $type = ColumnType::fromField($field);

            if ($name === self::KEY_COLUMN && !$withKey || $type === null) {
                continue;
            }

            $fields[] = [
                'source' => $name,
                'title' => self::systemTitle($name),
                'physical' => $name,
                'type' => $type,
            ];
        }

        foreach ($this->properties as $property) {
            $type = ColumnType::fromField($property->field);

            if ($type === null) {
                continue;
            }

            $fields[] = [
                'source' => $property->id,
                'title' => self::firstFilled($property->title, $property->label, $property->name),
                'physical' => $property->name,
                'type' => $type,
            ];
        }

        return $fields;
    }

    public function physicalColumn(string $source): ?string
    {
        if (array_key_exists($source, $this->system)) {
            return $source;
        }

        foreach ($this->properties as $property) {
            if ($property->id === $source) {
                return $property->name;
            }
        }

        return null;
    }

    /** @return BelongsTo<ConnectionMetadata, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ConnectionMetadata::class, 'parent_id');
    }

    private static function firstFilled(string ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function systemTitle(string $name): string
    {
        $key = 'phpinnacle-ferry::resources.sync.system_fields.' . $name;

        return Lang::has($key) ? __($key) : $name;
    }
}
