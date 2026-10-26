<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Casts\JsonSchemaCast;

/**
 * @phpstan-import-type Definition from JsonSchema
 *
 * @property string $id
 * @property string $upstream_id
 * @property string|null $parent_id
 * @property string $external_id
 * @property string|null $reference_id
 * @property string $name
 * @property int|null $code
 * @property string|null $kind
 * @property string $label
 * @property string $title
 * @property list<string> $primary_key
 * @property int $priority
 * @property JsonSchema $schema
 * @property list<array{id: string, label: string, title: string}> $values
 * @property int $position
 * @property int $revision
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Structure $structure
 * @property-read Upstream $upstream
 * @property-read StructureItem|null $parent
 * @property-read Collection<int, StructureItem> $children
 */
class StructureItem extends Model
{
    use HasUuids;

    protected $table = 'structure_items';

    protected $attributes = ['primary_key' => '[]', 'priority' => 1, 'label' => '', 'title' => '', 'values' => '[]'];

    protected $casts = [
        'code' => 'integer',
        'priority' => 'integer',
        'primary_key' => 'array',
        'schema' => JsonSchemaCast::class,
        'values' => 'array',
        'position' => 'integer',
        'revision' => 'integer',
    ];

    protected $fillable = [
        'upstream_id',
        'parent_id',
        'external_id',
        'reference_id',
        'name',
        'code',
        'kind',
        'label',
        'title',
        'primary_key',
        'priority',
        'schema',
        'values',
        'position',
        'revision',
    ];

    private ?JsonSchema $cachedSchema = null;

    /** @var array<string, JsonSchema> */
    private array $cachedProperties = [];

    /** @return HasMany<StructureItem, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(StructureItem::class, 'parent_id');
    }

    /** @return BelongsTo<Upstream, $this> */
    public function upstream(): BelongsTo
    {
        return $this->belongsTo(Upstream::class, 'upstream_id');
    }

    /** @return BelongsTo<Structure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'upstream_id', 'upstream_id');
    }

    public function displayTitle(): string
    {
        return match (true) {
            $this->title !== '' => $this->title,
            $this->label !== '' => $this->label,
            default => $this->name,
        };
    }

    public function field(string $source): ?JsonSchema
    {
        $field = $this->properties()[$source] ?? null;

        if ($field === null) {
            return null;
        }
        /** @var array<string, mixed> $definition */
        $definition = array_diff_key($field->toArray(), [
            'title' => true,
            'description' => true,
            'x-ferry-trans' => true,
        ]);

        return JsonSchema::fromArray($definition);
    }

    /** @return array<string, Definition&array{title: string}> */
    public function mappableFields(bool $withKey = true): array
    {
        $fields = [];
        foreach ($this->properties() as $source => $field) {
            $source = (string) $source;
            if (
                in_array($source, $this->primary_key, true)
                && !$withKey
                || !in_array($field->singleType(), ['string', 'integer', 'number', 'boolean'], true)
            ) {
                continue;
            }
            /** @var string $title */
            $title = $field->annotation('title') ?? $source;
            /** @var string|null $translation */
            $translation = $field->annotation('x-ferry-trans');
            if ($translation !== null) {
                $title = Lang::get($translation);
            }
            $definition = $field->toArray();
            unset($definition['x-ferry-trans']);
            $definition['title'] = $title;
            $fields[$source] = $definition;
        }

        return $fields;
    }

    /** @return array<string, JsonSchema> */
    private function properties(): array
    {
        $schema = $this->schema;
        if ($this->cachedSchema !== $schema) {
            $this->cachedSchema = $schema;
            $this->cachedProperties = $schema->properties();
        }

        return $this->cachedProperties;
    }

    /** @return BelongsTo<StructureItem, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(StructureItem::class, 'parent_id');
    }
}
