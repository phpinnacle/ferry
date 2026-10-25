<?php

namespace PHPinnacle\Ferry\Destinations;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\JdbcConnectorConfig;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Jason\Schema;

final readonly class StaticDestination implements Destination
{
    /**
     * @param string|Htmlable|Closure(): (string|Htmlable|null)|null $label
     * @param Schema|Closure(): Schema $schema
     * @param array<string, string> $fixedValues
     * @param string|array<string>|null $color
     */
    public function __construct(
        public string $key,
        private string|Htmlable|Closure|null $label,
        public string $table,
        private string $primaryKey,
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

    public function connector(
        Sync $sync,
        ConnectionMetadata $object,
        string $topic,
        TransformFactory $transforms,
    ): array {
        return new JdbcConnectorConfig(
            table: $this->table,
            topic: $topic,
            columns: [
                ...array_values($sync->columnMap($object, keyColumn: null)),
                ...$sync->constantColumns(),
                ...array_keys($this->fixedValues),
            ],
            transforms: $transforms->pipeline(
                $sync,
                $object,
                keyColumn: null,
                fixedValues: $this->fixedValues,
            ),
            connection: $this->connection,
            primaryKey: $this->primaryKey,
        )->toArray();
    }
}
