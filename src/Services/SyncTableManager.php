<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Schema\ColumnDefinition;
use LogicException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\StringField;

class SyncTableManager
{
    public const string ID_COLUMN = 'idrref';

    public const array RESERVED_COLUMNS = [
        self::ID_COLUMN,
    ];

    public function create(Sync $sync): void
    {
        $this->schema($sync)->create($sync->destination, function (Blueprint $table) use ($sync) {
            $this->technicalColumns($table);

            foreach ($sync->schema as $mapping) {
                $this->column($table, $mapping)->nullable();
            }
        });
    }

    public function drop(Sync $sync): void
    {
        $this->schema($sync)->dropIfExists($sync->destination);
    }

    /**
     * @param list<FieldMapping> $previousMappings
     * @param list<FieldMapping> $currentMappings
     */
    public function update(Sync $sync, array $previousMappings, array $currentMappings): void
    {
        $previous = [];

        foreach ($previousMappings as $mapping) {
            $previous[$mapping->source] = $mapping;
        }

        $current = [];

        foreach ($currentMappings as $mapping) {
            $current[$mapping->source] = $mapping;
        }

        $this->schema($sync)->table($sync->destination, function (Blueprint $table) use ($previous, $current) {
            foreach ($previous as $mapping) {
                $replacement = $current[$mapping->source] ?? null;

                if ($replacement === null || $replacement->type() !== $mapping->type()) {
                    $table->dropColumn($mapping->column);
                }
            }

            foreach ($current as $mapping) {
                $existing = $previous[$mapping->source] ?? null;

                if ($existing === null || $existing->type() !== $mapping->type()) {
                    $this->column($table, $mapping)->nullable();

                    continue;
                }

                if ($existing->column !== $mapping->column) {
                    $table->renameColumn($existing->column, $mapping->column);
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

    private function schema(Sync $sync): SchemaBuilder
    {
        return $sync->getConnection()->getSchemaBuilder();
    }

    private function technicalColumns(Blueprint $table): void
    {
        $table->string(self::ID_COLUMN)->primary();
    }
}
