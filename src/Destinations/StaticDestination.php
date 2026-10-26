<?php

namespace PHPinnacle\Ferry\Destinations;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Jason\Schema;

final readonly class StaticDestination implements Destination
{
    /**
     * @param string|Htmlable|Closure(): (string|Htmlable|null)|null $label
     * @param Schema|Closure(): Schema $schema
     * @param non-empty-list<string> $primaryKey
     * @param array<string, string> $fixedValues
     * @param string|array<string>|null $color
     */
    public function __construct(
        public string $key,
        private string|Htmlable|Closure|null $label,
        public string $table,
        private array $primaryKey,
        private Schema|Closure $schema,
        private ?string $connection = null,
        private array $fixedValues = [],
        private string|BackedEnum|Htmlable|null $icon = null,
        private string|array|null $color = null,
    ) {}

    public function getLabel(): string|Htmlable|null
    {
        return $this->label instanceof Closure ? ($this->label)() : $this->label;
    }

    public function getSchema(): Schema
    {
        return $this->schema instanceof Closure ? ($this->schema)() : $this->schema;
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return $this->icon;
    }

    /** @return string|array<string>|null */
    public function getColor(): string|array|null
    {
        return $this->color;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getConnectionName(): ?string
    {
        return $this->connection;
    }

    /** @return non-empty-list<string> */
    public function getPrimaryKey(): array
    {
        return $this->primaryKey;
    }

    /** @return array<string, string> */
    public function getFixedValues(): array
    {
        return $this->fixedValues;
    }
}
