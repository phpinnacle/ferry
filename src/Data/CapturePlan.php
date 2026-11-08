<?php

namespace PHPinnacle\Ferry\Data;

final readonly class CapturePlan
{
    /**
     * @param array{driver: string, ...} $config Laravel database connection settings.
     * @param list<string> $tables Debezium table selectors.
     * @param list<string>|null $columns Null captures every column.
     * @param list<string> $keys
     * @param array<string, array<string, true>>|null $converterColumns Null disables source converters.
     * @param array<string, string> $converters Key/value serialization options overriding source and sink defaults.
     */
    public function __construct(
        public array $config,
        public array $tables,
        public ?array $columns = null,
        public array $keys = [],
        public ?array $converterColumns = null,
        public array $converters = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }
}
