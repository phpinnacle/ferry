<?php

namespace PHPinnacle\Ferry\Forms\Concerns;

use Closure;
use PHPinnacle\Ferry\Forms\JsonSchema;

/**
 * @phpstan-import-type Definition from JsonSchema
 * @phpstan-import-type Presentation from JsonSchema
 * @phpstan-type MappingBadge array{label: string, color: string}
 * @phpstan-type MappingItem array{
 *     id: string,
 *     label: string,
 *     icon: string|null,
 *     color: string|null,
 *     description: string|null,
 *     type: string,
 *     required: bool,
 *     badges: list<MappingBadge>,
 *     presentation: Presentation
 * }
 */
trait HasMappingItems
{
    /** @var bool|Closure(): bool */
    protected bool|Closure $isSearchable = false;

    /** @param bool|Closure(): bool $condition */
    public function searchable(bool|Closure $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    public function isSearchable(): bool
    {
        return $this->evaluate($this->isSearchable);
    }

    /**
     * @param Definition $schema
     * @return list<MappingItem>
     */
    protected function schemaItems(array $schema): array
    {
        $items = [];
        $schema = JsonSchema::fromArray($schema);
        $required = $schema->required();
        foreach ($schema->properties() as $name => $property) {
            $items[] = [
                'id' => (string) $name,
                'label' => $property->annotation('title') ?? (string) $name,
                'icon' => $property->annotation('x-icon'),
                'color' => $property->annotation('x-color'),
                'description' => $property->annotation('description'),
                'type' => $property->label(),
                'required' => in_array((string) $name, $required, true),
                'badges' => $property->badges(),
                'presentation' => $property->presentation(),
            ];
        }

        return $items;
    }
}
