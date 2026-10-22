<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use LogicException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\StringField;

class SyncTableManager
{
    public function create(Sync $sync): void
    {
        $sync
            ->getConnection()
            ->getSchemaBuilder()
            ->create($sync->destination, function (Blueprint $table) use ($sync) {
                $table->string(Sync::ID_COLUMN)->primary();

                foreach ($sync->schema as $mapping) {
                    $this->column($table, $mapping)->nullable();
                }
            });
    }

    public function drop(Sync $sync): void
    {
        $sync->getConnection()->getSchemaBuilder()->dropIfExists($sync->destination);
    }

    public function update(Sync $sync): void
    {
        $changes = $sync->schemaChanges();

        $sync
            ->getConnection()
            ->getSchemaBuilder()
            ->table($sync->destination, function (Blueprint $table) use ($changes) {
                foreach ($changes['drop'] as $column) {
                    $table->dropColumn($column);
                }

                foreach ($changes['columns'] as $change) {
                    if ($change['from'] === null) {
                        $this->column($table, $change['mapping'])->nullable();
                    } else {
                        $table->renameColumn($change['from'], $change['mapping']->column);
                    }
                }
            });
    }

    private function column(Blueprint $table, FieldMapping $mapping): ColumnDefinition
    {
        $type = $mapping->type();

        if ($type === null) {
            throw new LogicException(__('phpinnacle-ferry::resources.sync.errors.unsupported_field_type', [
                'column' => $mapping->column,
            ]));
        }

        $field = $mapping->field;

        return match ($type) {
            ColumnType::Boolean => $table->boolean($mapping->column),
            ColumnType::DateTime => $table->dateTime($mapping->column),
            ColumnType::Integer => $table->bigInteger($mapping->column),
            ColumnType::Decimal => $table->decimal(
                $mapping->column,
                $field instanceof NumberField ? $field->precision ?? 15 : 15,
                $field instanceof NumberField ? $field->scale ?? 2 : 2,
            ),
            ColumnType::String => $field instanceof StringField && $field->length !== null
                ? $table->string($mapping->column, $field->length)
                : $table->text($mapping->column),
            ColumnType::Reference => $table->text($mapping->column),
        };
    }
}
