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
use PHPinnacle\Ferry\Data\SourceField;
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

    /** @var array{string, string, string}|null */
    private ?array $sourceFieldsCacheKey = null;

    /** @var array<string, SourceField> */
    private array $cachedSourceFields = [];

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
        return $this->sourceFields()[$source]->field ?? null;
    }

    /** @return list<array{source: string, title: string, description: string|null, physical: string, type: ColumnType}> */
    public function mappableFields(bool $withKey = true): array
    {
        $fields = [];
        foreach ($this->sourceFields() as $field) {
            $type = ColumnType::fromField($field->field);
            if ($field->source === self::KEY_COLUMN && !$withKey || $type === null) {
                continue;
            }
            $fields[] = [
                'source' => $field->source,
                'title' => $field->title,
                'description' => $field->description,
                'physical' => $field->physical,
                'type' => $type,
            ];
        }

        return $fields;
    }

    public function physicalColumn(string $source): ?string
    {
        return $this->sourceFields()[$source]->physical ?? null;
    }

    /** @return array<string, SourceField> */
    private function sourceFields(): array
    {
        /** @var array{system: string, properties: string} $attributes */
        $attributes = $this->getAttributes();
        $cacheKey = [$attributes['system'], $attributes['properties'], Lang::getLocale()];
        if ($this->sourceFieldsCacheKey === $cacheKey) {
            return $this->cachedSourceFields;
        }

        $fields = [];
        foreach ($this->system as $name => $field) {
            $fields[$name] = new SourceField($name, $name, self::systemTitle($name), $field);
        }
        foreach ($this->properties as $property) {
            foreach (SourceField::fromProperty($property) as $field) {
                $fields[$field->source] = $field;
            }
        }

        $this->sourceFieldsCacheKey = $cacheKey;

        return $this->cachedSourceFields = $fields;
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
