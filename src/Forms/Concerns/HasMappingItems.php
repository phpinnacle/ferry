<?php

namespace PHPinnacle\Ferry\Forms\Concerns;

use BackedEnum;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Stringable;
use UnitEnum;

/**
 * @phpstan-type MappingBadge array{label: string, color: string}
 * @phpstan-type MappingItem array{
 *     id: string,
 *     label: string|Htmlable,
 *     icon: string|BackedEnum|Htmlable|null,
 *     description: string|Htmlable|null,
 *     type: string|null,
 *     required: bool,
 *     badges: list<MappingBadge>
 * }
 */
trait HasMappingItems
{
    /**
     * @param  array<array-key, string|object>  $items
     * @param  array<array-key, string>  $types
     * @param  array<array-key, list<string|array{label: string, color?: string}>>  $badges
     * @param  list<string>  $required
     * @return list<MappingItem>
     */
    protected function normalizeItems(array $items, array $types, array $badges, array $required = []): array
    {
        $isList = array_is_list($items);
        $normalized = [];
        foreach ($items as $key => $item) {
            $id = (string) match (true) {
                !$isList => $key,
                is_string($item) => $item,
                $item instanceof BackedEnum => $item->value,
                $item instanceof UnitEnum => $item->name,
                default => $key,
            };

            $itemBadges = [];

            foreach ($badges[$id] ?? [] as $badge) {
                $itemBadges[] = is_string($badge)
                    ? ['label' => $badge, 'color' => 'gray']
                    : [
                        'label' => $badge['label'],
                        'color' => $badge['color'] ?? 'gray',
                    ];
            }
            $fallbackLabel = match (true) {
                is_string($item) => $item,
                $item instanceof UnitEnum => $item->name,
                $item instanceof Stringable => (string) $item,
                default => $id,
            };

            $normalized[] = [
                'id' => $id,
                'label' => $item instanceof HasLabel ? $item->getLabel() ?? $fallbackLabel : $fallbackLabel,
                'icon' => $item instanceof HasIcon ? $item->getIcon() : null,
                'description' => $item instanceof HasDescription ? $item->getDescription() : null,
                'type' => $types[$id] ?? null,
                'required' => in_array($id, $required, true),
                'badges' => $itemBadges,
            ];
        }

        return $normalized;
    }
}
