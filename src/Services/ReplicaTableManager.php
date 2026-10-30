<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use LogicException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Models\Replica;

class ReplicaTableManager
{
    public function create(Replica $replica): void
    {
        $primaryKey = array_values($replica->primaryKeyMap($replica->sourceObject()));
        $replica
            ->getConnection()
            ->getSchemaBuilder()
            ->create($replica->destination, function (Blueprint $table) use ($replica, $primaryKey) {
                foreach ($replica->mapping as $mapping) {
                    if ($mapping->target !== null) {
                        $this->column($table, $mapping, $replica->outputSchema($mapping))->nullable(!in_array(
                            $mapping->target,
                            $primaryKey,
                            true,
                        ));
                    }
                }

                $table->primary($primaryKey);
            });
    }

    public function drop(Replica $replica): void
    {
        $replica->getConnection()->getSchemaBuilder()->dropIfExists($replica->destination);
    }

    public function update(Replica $replica): void
    {
        $changes = $replica->mappingChanges();

        $replica
            ->getConnection()
            ->getSchemaBuilder()
            ->table($replica->destination, function (Blueprint $table) use ($changes, $replica) {
                foreach ($changes['drop'] as $column) {
                    $table->dropColumn($column);
                }

                foreach ($changes['columns'] as $change) {
                    if ($change['from'] === null) {
                        $this->column(
                            $table,
                            $change['mapping'],
                            $replica->outputSchema($change['mapping']),
                        )->nullable();
                    } else {
                        /** @var string $target */
                        $target = $change['mapping']->target;
                        $table->renameColumn($change['from'], $target);
                    }
                }

                foreach ($changes['alter'] as $mapping) {
                    $this->column($table, $mapping, $replica->outputSchema($mapping))->nullable()->change();
                }
            });
    }

    /** @return 'boolean'|'bigInteger'|'decimal'|'text'|'date'|'time'|'dateTime'|'string'|null */
    public static function columnType(?JsonSchema $schema): ?string
    {
        return match ($schema?->singleType()) {
            'boolean' => 'boolean',
            'integer' => 'bigInteger',
            'number' => 'decimal',
            'string' => match (true) {
                $schema->annotation('x-ferry-reference') === true => 'text',
                $schema->format() === 'date' => 'date',
                $schema->format() === 'time' => 'time',
                $schema->format() === 'date-time' => 'dateTime',
                default => 'string',
            },
            default => null,
        };
    }

    private function column(Blueprint $table, FieldMapping $mapping, ?JsonSchema $field): ColumnDefinition
    {
        /** @var string $target */
        $target = $mapping->target;
        $type = self::columnType($field);

        if ($type === null) {
            throw new LogicException(__('phpinnacle-ferry::resources.replica.errors.unsupported_field_type', [
                'column' => $target,
            ]));
        }

        /** @var int $precision */
        $precision = $field?->annotation('x-ferry-precision') ?? 15;
        /** @var int $scale */
        $scale = $field?->annotation('x-ferry-scale') ?? 2;
        $length = $mapping->kind === FieldKind::Static && $mapping->transforms() === [] ? null : $field?->maxLength();

        return match ($type) {
            'boolean' => $table->boolean($target),
            'dateTime' => $table->dateTime($target),
            'date' => $table->date($target),
            'time' => $table->time($target),
            'bigInteger' => $table->bigInteger($target),
            'decimal' => $table->decimal(
                $target,
                $precision,
                $scale,
            ),
            'string' => $table->addColumn('string', $target, ['length' => $length > 0 ? $length : null]),
            'text' => $table->text($target),
        };
    }
}
