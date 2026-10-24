<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use LogicException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Rosetta\Fields\NumberField;

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
                    if ($mapping->target !== null) {
                        $this->column($table, $mapping)->nullable();
                    }
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
                        /** @var string $target */
                        $target = $change['mapping']->target;
                        $table->renameColumn($change['from'], $target);
                    }
                }

                foreach ($changes['alter'] as $mapping) {
                    $this->column($table, $mapping)->nullable()->change();
                }
            });
    }

    private function column(Blueprint $table, FieldMapping $mapping): ColumnDefinition
    {
        /** @var string $target */
        $target = $mapping->target;
        $type = $mapping->type();

        if ($type === null) {
            throw new LogicException(__('phpinnacle-ferry::resources.sync.errors.unsupported_field_type', [
                'column' => $target,
            ]));
        }

        $field = $mapping->field;

        return match ($type) {
            ColumnType::Boolean => $table->boolean($target),
            ColumnType::DateTime => $table->dateTime($target),
            ColumnType::Integer => $table->bigInteger($target),
            ColumnType::Decimal => $table->decimal(
                $target,
                $field instanceof NumberField ? $field->precision ?? 15 : 15,
                $field instanceof NumberField ? $field->scale ?? 2 : 2,
            ),
            ColumnType::String => $table->addColumn('string', $target, ['length' => $mapping->stringLength()]),
            ColumnType::Reference => $table->text($target),
        };
    }
}
