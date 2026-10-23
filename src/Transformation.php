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
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

/** @phpstan-import-type Definition from JsonSchema */
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

    /** @var Definition|Closure(JsonSchema): bool */
    private array|Closure $inputSchema = [];

    /** @var Definition|Closure(JsonSchema, array<string, string>): (Definition|null)|null */
    private array|Closure|null $outputSchema = null;

    private function __construct(
        private readonly string $key,
    ) {
        $this->label = $key;
    }

    public static function make(string $key): self
    {
        return new self($key);
    }

    public function key(): string
    {
        return $this->key;
    }

    /** @param Definition|Closure(JsonSchema): bool $schema */
    public function inputSchema(array|Closure $schema): self
    {
        $transformation = clone $this;
        $transformation->inputSchema = $schema;

        return $transformation;
    }

    public function accepts(JsonSchema $input): bool
    {
        return $this->inputSchema instanceof Closure
            ? ($this->inputSchema)($input)
            : $input->compatible(JsonSchema::fromArray($this->inputSchema));
    }

    /** @param Definition|Closure(JsonSchema, array<string, string>): (Definition|null) $schema */
    public function outputSchema(array|Closure $schema): self
    {
        $transformation = clone $this;
        $transformation->outputSchema = $schema;

        return $transformation;
    }

    /** @param array<string, string> $params */
    public function getOutputSchema(JsonSchema $input, array $params): ?JsonSchema
    {
        if ($this->outputSchema === null) {
            return $input;
        }

        $schema = $this->outputSchema instanceof Closure
            ? ($this->outputSchema)($input, $params)
            : $this->outputSchema;

        return $schema === null ? null : JsonSchema::fromArray($schema);
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
    public function getSchema(?ConnectionMetadata $object, ?JsonSchema $input = null): array
    {
        /** @var array<Component> $schema */
        $schema = $this->evaluate($this->schema, ['object' => $object, 'input' => $input]);

        return array_map(static fn (Component $component) => clone $component, $schema);
    }
}
