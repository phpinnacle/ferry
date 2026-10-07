<?php

namespace PHPinnacle\Ferry\Destinations;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\JdbcConnectorConfig;

final readonly class StaticDestination implements Destination, HasLabel
{
    /**
     * @param list<DestinationField> $fields
     * @param array<string, string> $fixedValues
     */
    public function __construct(
        public string $key,
        private string|Htmlable|null $label,
        public string $table,
        private string $primaryKey,
        public array $fields,
        private ?string $connection = null,
        private array $fixedValues = [],
    ) {}

    public function getLabel(): string|Htmlable|null
    {
        return $this->label;
    }

    public function connector(Sync $sync, ConnectionMetadata $object, string $topic): array
    {
        return new JdbcConnectorConfig(
            table: $this->table,
            topic: $topic,
            columns: $sync->columnMap($object, $this->primaryKey),
            connection: $this->connection,
            fixedValues: $this->fixedValues,
        )->toArray();
    }
}
