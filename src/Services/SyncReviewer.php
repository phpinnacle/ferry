<?php

namespace PHPinnacle\Ferry\Services;

use PHPinnacle\Ferry\Contracts\StaticDestination;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;

class SyncReviewer
{
    public function __construct(
        private readonly SyncDestinationResolver $destinations,
    ) {}

    /** @return list<string> */
    public function brokenColumns(Sync $sync, ConnectionMetadata $object): array
    {
        $destination = $this->destinations->find($sync->static_destination);

        if ($sync->static_destination !== null && $destination === null) {
            return array_map(static fn (FieldMapping $mapping) => $mapping->column, $sync->schema);
        }

        $columns = [];

        foreach ($sync->schema as $mapping) {
            $field = $object->field($mapping->source);

            if ($field === null || ColumnType::fromField($field) !== $mapping->type()) {
                $columns[] = $mapping->column;
            }
        }

        if ($destination !== null) {
            $columns = [...$columns, ...$this->destinationColumns($destination, $sync->schema)];
        }

        return array_values(array_unique($columns));
    }

    public function isValid(Sync $sync, ConnectionMetadata $object): bool
    {
        return $this->brokenColumns($sync, $object) === [];
    }

    public function review(Connection $connection): void
    {
        $objects = $connection->publishedMetadata()->get()->keyBy('external_id');

        foreach ($connection->syncs()->get() as $sync) {
            if ($sync->status === SyncStatus::Pause) {
                continue;
            }

            $object = $objects->get($sync->source);

            if (!$object instanceof ConnectionMetadata || !$this->isValid($sync, $object)) {
                if ($sync->sink_connector_status !== null) {
                    app(ConnectorManager::class)->pause($sync, manually: false);
                } else {
                    $sync->pause(manually: false);
                }
            }
        }
    }

    public function shouldResume(Sync $sync, ConnectionMetadata $object): bool
    {
        if ($sync->sink_connector_status === null) {
            return false;
        }

        if ($sync->status === SyncStatus::Active) {
            return true;
        }

        return $sync->status === SyncStatus::Pause && !$sync->is_paused && $this->isValid($sync, $object);
    }

    /**
     * @param list<FieldMapping> $mappings
     *
     * @return list<string>
     */
    private function destinationColumns(StaticDestination $destination, array $mappings): array
    {
        $fields = [];

        foreach ($destination->fields() as $field) {
            $fields[$field->id] = $field;
        }

        $columns = [];

        foreach ($mappings as $mapping) {
            if (($fields[$mapping->column] ?? null)?->type !== $mapping->type()) {
                $columns[] = $mapping->column;
            }

            unset($fields[$mapping->column]);
        }

        foreach ($fields as $field) {
            if ($field->required) {
                $columns[] = $field->id;
            }
        }

        return $columns;
    }
}
