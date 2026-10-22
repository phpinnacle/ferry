<?php

namespace PHPinnacle\Ferry;

use BackedEnum;
use Closure;
use Filament\Schemas\Components\Component;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class Transformation implements HasColor, HasDescription, HasIcon, HasLabel
{
    use EvaluatesClosures;

    private string|Closure $label;

    private string|Htmlable|Closure|null $description = null;

    private string|BackedEnum|Htmlable|Closure|null $icon = null;

    /** @var string|array<string>|Closure|null */
    private string|array|Closure|null $color = 'primary';

    /** @var array<Component>|Closure */
    private array|Closure $schema = [];

    /** @var list<ColumnType>|null */
    private ?array $inputTypes = null;

    /** @var ColumnType|Closure(?ColumnType, array<string, string>): ?ColumnType|null */
    private ColumnType|Closure|null $outputType = null;

    private function __construct(
        private readonly string $key,
        private readonly TransformScope $scope,
    ) {
        $this->label = $key;
    }

    public static function make(string $key, TransformScope $scope): self
    {
        return new self($key, $scope);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function scope(): TransformScope
    {
        return $this->scope;
    }

    /** @param list<ColumnType> $types */
    public function inputTypes(array $types): self
    {
        $transformation = clone $this;
        $transformation->inputTypes = $types;

        return $transformation;
    }

    public function accepts(?ColumnType $input): bool
    {
        return $this->inputTypes === null || in_array($input, $this->inputTypes, true);
    }

    /** @param ColumnType|Closure(?ColumnType, array<string, string>): ?ColumnType $type */
    public function outputType(ColumnType|Closure $type): self
    {
        $transformation = clone $this;
        $transformation->outputType = $type;

        return $transformation;
    }

    /** @param array<string, string> $params */
    public function getOutputType(?ColumnType $input, array $params): ?ColumnType
    {
        if ($this->outputType instanceof Closure) {
            return ($this->outputType)($input, $params);
        }

        return $this->outputType ?? $input;
    }

    public function label(string|Closure $label): self
    {
        $transformation = clone $this;
        $transformation->label = $label;

        return $transformation;
    }

    public function getLabel(): string
    {
        /** @var string */
        return $this->evaluate($this->label);
    }

    public function description(string|Htmlable|Closure|null $description): self
    {
        $transformation = clone $this;
        $transformation->description = $description;

        return $transformation;
    }

    public function getDescription(): string|Htmlable|null
    {
        /** @var string|Htmlable|null */
        return $this->evaluate($this->description);
    }

    public function icon(string|BackedEnum|Htmlable|Closure|null $icon): self
    {
        $transformation = clone $this;
        $transformation->icon = $icon;

        return $transformation;
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        /** @var string|BackedEnum|Htmlable|null */
        return $this->evaluate($this->icon);
    }

    /** @param string|array<string>|Closure|null $color */
    public function color(string|array|Closure|null $color): self
    {
        $transformation = clone $this;
        $transformation->color = $color;

        return $transformation;
    }

    /** @return string|array<string>|null */
    public function getColor(): string|array|null
    {
        /** @var string|array<string>|null */
        return $this->evaluate($this->color);
    }

    /** @param array<Component>|Closure $schema */
    public function schema(array|Closure $schema): self
    {
        $transformation = clone $this;
        $transformation->schema = $schema;

        return $transformation;
    }

    /** @return array<Component> */
    public function getSchema(?ConnectionMetadata $object): array
    {
        /** @var array<Component> $schema */
        $schema = $this->evaluate($this->schema, ['object' => $object]);

        return array_map(static fn (Component $component) => clone $component, $schema);
    }
}
