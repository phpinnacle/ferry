<?php

namespace PHPinnacle\Ferry\Forms;

use InvalidArgumentException;
use PHPinnacle\Jason\Schema;
use stdClass;

/**
 * Type and presentation projections for inline JSON Schema field definitions.
 *
 * @phpstan-type Badge array{label: string, color: string}
 * @phpstan-type MappingItem array{
 *     id: string,
 *     name: string,
 *     label: string,
 *     icon: string|null,
 *     color: string|null,
 *     description: string|null,
 *     type: string,
 *     required: bool,
 *     badges: list<Badge>,
 *     presentation: Presentation
 * }
 * @phpstan-type Definition array{
 *     type?: string|list<string>, properties?: array<string, array<string, mixed>>,
 *     required?: list<string>, items?: array<string, mixed>,
 *     allOf?: list<array<string, mixed>>, anyOf?: list<array<string, mixed>>, oneOf?: list<array<string, mixed>>,
 *     title?: string, description?: string, format?: string, minLength?: int, maxLength?: int,
 *     minimum?: int|float, maximum?: int|float, exclusiveMinimum?: int|float, exclusiveMaximum?: int|float,
 *     minItems?: int, maxItems?: int, uniqueItems?: bool, multipleOf?: int|float, pattern?: string, enum?: list<mixed>,
 *     x-icon?: string, x-color?: string, x-badges?: list<Badge>, ...
 * }
 * @phpstan-type Constraint array{kind: string, value: string, scope: list<string>}
 * @phpstan-type ValuePresentation array{type: string, nullable: bool, constraints: list<Constraint>, enum: list<string>}
 * @phpstan-type ItemPresentation array{scope: list<string>, type: string, nullable: bool, constraints: list<Constraint>, enum: list<string>}
 * @phpstan-type Presentation array{type: string, nullable: bool, constraints: list<Constraint>, enum: list<string>, items: list<ItemPresentation>}
 */
final readonly class JsonSchema
{
    /** @var Definition */
    private array $definition;

    /** @param array<string, mixed> $definition */
    private function __construct(
        array $definition,
        private Schema $resolved,
    ) {
        // PHPStan cannot express recursive definitions at the serialization boundary.
        /** @var Definition $definition */
        $this->definition = $definition;
    }

    /** @param array<string, mixed> $definition Valid inline mapping schema. */
    public static function fromArray(array $definition): self
    {
        return new self($definition, Schema::fromArray($definition)->resolveReferences());
    }

    private static function fromSchema(Schema $schema): self
    {
        $definition = $schema->toArray();
        if (is_bool($definition)) {
            throw new InvalidArgumentException('Boolean schemas cannot be used as mapping fields.');
        }

        return new self($definition, $schema);
    }

    /**
     * @param 'allOf'|'anyOf'|'oneOf' $keyword
     * @return list<self>
     */
    private function branches(string $keyword): array
    {
        return array_map(self::fromSchema(...), $this->resolved->branches($keyword));
    }

    /** @return list<MappingItem> */
    public function toMappingItems(): array
    {
        $items = [];
        $required = $this->required();
        foreach ($this->properties() as $name => $property) {
            $name = (string) $name;
            $items[] = [
                'id' => $name,
                'name' => $name,
                'label' => $property->annotation('title') ?? $name,
                'icon' => $property->annotation('x-icon'),
                'color' => $property->annotation('x-color'),
                'description' => $property->annotation('description'),
                'type' => $property->label(),
                'required' => in_array($name, $required, true),
                'badges' => $property->badges(),
                'presentation' => $property->presentation(),
            ];
        }

        return $items;
    }

    /** @return array<string, self> */
    public function properties(): array
    {
        $properties = array_map(self::fromSchema(...), $this->resolved->properties());
        foreach ($this->branches('allOf') as $branch) {
            foreach ($branch->properties() as $name => $property) {
                $properties[$name] = array_key_exists($name, $properties)
                    ? self::compose('allOf', [$properties[$name], $property])
                    : $property;
            }
        }

        return $properties;
    }

    /** @return list<string> */
    public function required(): array
    {
        $required = $this->resolved->keyword('required') ?? [];
        foreach ($this->branches('allOf') as $branch) {
            $required = array_merge($required, $branch->required());
        }

        return array_values(array_unique($required));
    }

    public function items(): ?self
    {
        if (array_values(array_diff($this->types(), ['null'])) !== ['array']) {
            return null;
        }

        return $this->itemSchema() ?? self::fromArray([]);
    }

    private function itemSchema(): ?self
    {
        $items = $this->resolved->itemSchema();
        $constraints = $items === null ? [] : [self::fromSchema($items)];
        foreach ($this->branches('allOf') as $branch) {
            $items = $branch->itemSchema();
            if ($items !== null) {
                $constraints[] = $items;
            }
        }
        foreach ([$this->branches('anyOf'), $this->branches('oneOf')] as $branches) {
            $items = [];
            foreach ($branches as $branch) {
                if ($branch->types() === [] || in_array('array', $branch->types(), true)) {
                    $items[] = $branch->itemSchema() ?? self::fromArray([]);
                }
            }
            if ($items !== []) {
                $constraints[] = self::compose('anyOf', $items);
            }
        }

        return $constraints === [] ? null : self::compose('allOf', $constraints);
    }

    /**
     * @param 'allOf'|'anyOf' $keyword
     * @param non-empty-list<self> $schemas
     */
    private static function compose(string $keyword, array $schemas): self
    {
        if (count($schemas) === 1) {
            return $schemas[0];
        }

        return self::fromArray([$keyword => array_map(fn (self $schema) => $schema->resolved->keywords(), $schemas)]);
    }

    public function label(bool $formatted = false): string
    {
        $types = $this->types();
        if ($formatted) {
            if (($this->resolved->keyword('enum') ?? null) !== null) {
                return 'enum';
            }
            $alternatives = $this->alternatives();
            if ($alternatives !== []) {
                $labels = array_unique(array_map(fn (self $branch) => $branch->label(true), $alternatives));

                $label = implode(' | ', array_diff($labels, ['null']));

                return $label ? $label : 'null';
            }
            $format = $this->annotation('format');
            if ($format !== null) {
                return $format;
            }
            $types = array_values(array_diff($types, ['null']));
        }
        $items = $this->items();
        if ($items !== null) {
            $label = $items->label($formatted);

            return (
                (str_contains($label, ' | ') ? '(' . $label . ')' : $label)
                . '[]'
                . (!$formatted && in_array('null', $types, true) ? ' | null' : '')
            );
        }

        if ($types !== []) {
            return implode(' | ', $types);
        }

        return in_array('null', $this->types(), true) ? 'null' : 'any';
    }

    /** @return Presentation */
    public function presentation(): array
    {
        return [...$this->valuePresentation(), 'items' => $this->itemPresentations()];
    }

    /** @return ValuePresentation */
    private function valuePresentation(): array
    {
        return [
            'type' => $this->label(true),
            'nullable' => count($this->types()) > 1 && in_array('null', $this->types(), true),
            'constraints' => $this->constraints(),
            'enum' => array_map(
                fn (mixed $value) => json_encode(
                    $value,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
                $this->resolved->keyword('enum') ?? [],
            ),
        ];
    }

    /**
     * @param list<string> $scope
     * @return list<ItemPresentation>
     */
    private function itemPresentations(array $scope = []): array
    {
        $items = $this->items();
        if ($items === null) {
            return [];
        }
        $scope = [...$scope, 'items'];

        return [
            ['scope' => $scope, ...$items->valuePresentation()],
            ...$items->itemPresentations($scope),
        ];
    }

    /**
     * @param list<string> $scope
     * @return list<Constraint>
     */
    private function constraints(array $scope = []): array
    {
        $constraints = [];
        foreach ([
            ['length', 'minLength', 'maxLength'],
            ['items',  'minItems',  'maxItems'],
        ] as [$kind, $minimum, $maximum]) {
            $min = $this->resolved->keyword($minimum);
            $max = $this->resolved->keyword($maximum);
            if ($min !== null || $max !== null) {
                $constraints[] = [
                    'kind' => $kind,
                    'value' => match (true) {
                        $min === $max => (string) $min,
                        $min !== null && $max !== null => "{$min}–{$max}",
                        $min !== null => "≥ {$min}",
                        default => "≤ {$max}",
                    },
                    'scope' => $scope,
                ];
            }
        }
        $range = [];
        foreach ([
            'minimum' => '≥',
            'maximum' => '≤',
            'exclusiveMinimum' => '>',
            'exclusiveMaximum' => '<',
        ] as $key => $symbol) {
            if (($value = $this->resolved->keyword($key)) !== null) {
                $range[] = $symbol . ' ' . $value;
            }
        }
        if ($range !== []) {
            $constraints[] = ['kind' => 'range', 'value' => implode(' · ', $range), 'scope' => $scope];
        }
        if ($this->resolved->hasKeyword('pattern')) {
            $constraints[] = [
                'kind' => 'pattern',
                'value' => '/' . $this->resolved->keyword('pattern') . '/',
                'scope' => $scope,
            ];
        }
        if ($this->resolved->hasKeyword('multipleOf')) {
            $constraints[] = [
                'kind' => 'multiple',
                'value' => '× ' . $this->resolved->keyword('multipleOf'),
                'scope' => $scope,
            ];
        }
        if ($this->resolved->keyword('uniqueItems') ?? false) {
            $constraints[] = ['kind' => 'unique', 'value' => '', 'scope' => $scope];
        }
        foreach ([
            'allOf' => $this->branches('allOf'),
            'anyOf' => $this->branches('anyOf'),
            'oneOf' => $this->branches('oneOf'),
        ] as $keyword => $branches) {
            foreach ($branches as $index => $branch) {
                $constraints = [...$constraints, ...$branch->constraints([...$scope, $keyword . ' ' . ($index + 1)])];
            }
        }

        return $constraints;
    }

    /** @return list<string> */
    public function types(): array
    {
        $types = (array) ($this->resolved->keyword('type') ?? []);
        foreach ([...$this->branches('anyOf'), ...$this->branches('oneOf')] as $branch) {
            $types = array_merge($types, $branch->types());
        }
        foreach ($this->branches('allOf') as $branch) {
            $branchTypes = $branch->types();
            if ($branchTypes !== []) {
                if (in_array('number', $types, true) && in_array('integer', $branchTypes, true)) {
                    $types[] = 'integer';
                }
                if (in_array('integer', $types, true) && in_array('number', $branchTypes, true)) {
                    $branchTypes[] = 'integer';
                }
                $types = $types === [] ? $branchTypes : array_intersect($types, $branchTypes);
            }
        }

        return array_values(array_unique($types));
    }

    public function singleType(): ?string
    {
        $types = array_values(array_diff($this->types(), ['null']));

        if (count($types) === 1) {
            return $types[0];
        }

        return $this->types() === ['null'] ? 'null' : null;
    }

    /**
     * @return ($name is 'title'|'description'|'format'|'x-icon'|'x-color' ? string|null : ($name is 'x-badges' ? list<Badge>|null : mixed))
     */
    public function annotation(string $name): mixed
    {
        if ($this->resolved->hasKeyword($name)) {
            return $this->resolved->keyword($name);
        }
        foreach ([...$this->branches('allOf'), ...$this->branches('anyOf'), ...$this->branches('oneOf')] as $branch) {
            $value = $branch->annotation($name);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<Badge> */
    public function badges(): array
    {
        /** @var list<Badge> $badges */
        $badges = $this->resolved->keyword('x-badges') ?? [];
        foreach ($this->branches('allOf') as $branch) {
            $badges = [...$badges, ...$branch->badges()];
        }

        return $badges;
    }

    /** @return Definition */
    public function toArray(): array
    {
        return $this->definition;
    }

    public function compatible(self $target, ?SchemaCompatibility $compatibility = null): bool
    {
        $compatibility ??= SchemaCompatibility::make();
        $alternatives = $this->alternatives();
        if ($alternatives !== []) {
            return array_all($alternatives, fn (self $branch) => $branch->compatible($target, $compatibility));
        }
        $alternatives = $target->alternatives();
        if ($alternatives !== []) {
            return array_any($alternatives, fn (self $branch) => $this->compatible($branch, $compatibility));
        }

        $sourceTypes = $this->types();
        $targetTypes = $target->types();
        $acceptedTypes = in_array('number', $targetTypes, true) ? [...$targetTypes, 'integer'] : $targetTypes;
        if ($sourceTypes !== [] && $targetTypes !== [] && array_diff($sourceTypes, $acceptedTypes) !== []) {
            return false;
        }
        $targetValues = $target->enumValues();
        if ($targetValues !== null) {
            $sourceValues = $this->enumValues();
            if (
                $sourceValues === null
                || !array_all($sourceValues, fn (mixed $value) => array_any(
                    $targetValues,
                    fn (mixed $allowed) => self::sameEnumValue($value, $allowed),
                ))
            ) {
                return false;
            }
        }
        if (
            ($sourceTypes === []
            || in_array('string', $sourceTypes, true))
            && !$this->stringsFit($target, $compatibility)
        ) {
            return false;
        }
        if (in_array('array', $sourceTypes, true)) {
            return ($this->itemSchema() ?? self::fromArray([]))->compatible(
                $target->itemSchema() ?? self::fromArray([]),
                $compatibility,
            );
        }

        return true;
    }

    /** @return list<mixed>|null */
    private function enumValues(): ?array
    {
        $values = $this->resolved->keyword('enum') ?? null;
        foreach ($this->branches('allOf') as $branch) {
            $allowed = $branch->enumValues();
            if ($allowed !== null) {
                $values = $values === null
                    ? $allowed
                    : array_values(array_filter(
                        $values,
                        fn (mixed $value) => array_any($allowed, fn (mixed $candidate) => self::sameEnumValue(
                            $value,
                            $candidate,
                        )),
                    ));
            }
        }

        return $values;
    }

    private static function sameEnumValue(mixed $left, mixed $right): bool
    {
        return match (true) {
            is_int($left) && is_float($right) => $left === (int) $right && (float) $left === $right,
            is_float($left) && is_int($right) => self::sameEnumValue($right, $left),
            (is_array($left) || $left instanceof stdClass) && (is_array($right) || $right instanceof stdClass)
                => self::sameEnumStructure($left, $right),
            default => $left === $right,
        };
    }

    /**
     * @param array<array-key, mixed>|stdClass $left
     * @param array<array-key, mixed>|stdClass $right
     */
    private static function sameEnumStructure(array|stdClass $left, array|stdClass $right): bool
    {
        $leftIsList = is_array($left) && array_is_list($left);
        $rightIsList = is_array($right) && array_is_list($right);
        $left = (array) $left;
        $right = (array) $right;
        if ($leftIsList !== $rightIsList || count($left) !== count($right)) {
            return false;
        }

        return array_all(
            $left,
            fn (mixed $value, int|string $key) => (
                array_key_exists($key, $right) && self::sameEnumValue($value, $right[$key])
            ),
        );
    }

    private function stringsFit(self $target, SchemaCompatibility $compatibility): bool
    {
        if (
            $compatibility->checkFormats
            && $target->annotation('format') !== null
            && $this->annotation('format') !== $target->annotation('format')
        ) {
            return false;
        }
        if (!$compatibility->checkStringLengths) {
            return true;
        }

        $maximum = $target->lengthBound('maxLength');
        $sourceMaximum = $this->lengthBound('maxLength');

        return (
            ($this->lengthBound('minLength') ?? 0) >= ($target->lengthBound('minLength') ?? 0)
            && ($maximum === null || $sourceMaximum !== null && $sourceMaximum <= $maximum)
        );
    }

    public function maxLength(): ?int
    {
        $alternatives = $this->alternatives();
        if ($alternatives === []) {
            return $this->lengthBound('maxLength');
        }

        $bounds = [];
        foreach ($alternatives as $branch) {
            if ($branch->types() !== [] && !in_array('string', $branch->types(), true)) {
                continue;
            }
            $bound = $branch->maxLength();
            if ($bound === null) {
                return null;
            }
            $bounds[] = $bound;
        }

        return $bounds === [] ? null : max($bounds);
    }

    /** @param 'minLength'|'maxLength' $keyword */
    private function lengthBound(string $keyword): ?int
    {
        $bound = $this->resolved->keyword($keyword) ?? null;
        foreach ($this->branches('allOf') as $branch) {
            $value = $branch->lengthBound($keyword);
            if ($value === null) {
                continue;
            }
            if ($bound === null) {
                $bound = $value;

                continue;
            }
            $bound = $keyword === 'minLength' ? max($bound, $value) : min($bound, $value);
        }

        return $bound;
    }

    /** @return list<self> */
    private function alternatives(): array
    {
        $branches = $this->branches('anyOf');
        $keyword = $branches !== [] ? 'anyOf' : 'oneOf';
        $branches = $branches !== [] ? $branches : $this->branches('oneOf');
        $siblings = $this->resolved->keywords();
        unset($siblings[$keyword]);

        if ($branches === []) {
            foreach ($this->branches('allOf') as $index => $branch) {
                $alternatives = $branch->alternatives();
                if ($alternatives !== []) {
                    $allOf = $this->resolved->branches('allOf');
                    unset($allOf[$index]);
                    $siblings['allOf'] = array_values(array_map(fn (Schema $schema) => $schema->toArray(), $allOf));

                    $branches = $alternatives;

                    break;
                }
            }
        }

        if ($branches === []) {
            return [];
        }

        $siblings = self::fromArray($siblings);

        return array_map(fn (self $branch) => self::compose('allOf', [$siblings, $branch]), $branches);
    }
}
