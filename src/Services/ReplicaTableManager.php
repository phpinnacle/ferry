<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use LogicException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Jason\Schema;

class ReplicaTableManager
{
    public function __construct(
        private readonly ReplicaSchemaResolver $schemas,
    ) {}

    public function create(Replica $replica): void
    {
        $primaryKey = array_values($replica->primaryKeyMap($this->schemas->primaryKeySources(
            $replica,
            $replica->sourceObject(),
        )));
        $replica
            ->getConnection()
            ->getSchemaBuilder()
            ->create($replica->destination, function (Blueprint $table) use ($replica, $primaryKey) {
                foreach ($replica->mapping as $mapping) {
                    if ($mapping->target !== null) {
                        $this->column(
                            $table,
                            $mapping,
                            $this->schemas->outputSchema($replica, $mapping),
                        )->nullable(!in_array(
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
        $changes = $this->mappingChanges($replica);

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
                            $this->schemas->outputSchema($replica, $change['mapping']),
                        )->nullable();
                    } else {
                        /** @var string $target */
                        $target = $change['mapping']->target;
                        $table->renameColumn($change['from'], $target);
                    }
                }

                foreach ($changes['alter'] as $mapping) {
                    $this
                        ->column($table, $mapping, $this->schemas->outputSchema($replica, $mapping))
                        ->nullable()
                        ->change();
                }
            });
    }

    /** @return array{drop: list<string>, columns: list<array{mapping: FieldMapping, from: string|null}>, alter: list<FieldMapping>} */
    public function mappingChanges(Replica $replica): array
    {
        $changes = ['drop' => [], 'columns' => [], 'alter' => []];

        if ($replica->type === DestinationType::Static) {
            return $changes;
        }

        /** @var list<FieldMapping> $original */
        $original = $replica->getOriginal('mapping');
        $originalSchema = $this->schemas->originalSchema($replica);
        $previous = [];
        foreach ($original as $mapping) {
            if ($mapping->target !== null) {
                $previous[$mapping->identity()] = $mapping;
            }
        }

        $next = [];
        foreach ($replica->mapping as $mapping) {
            if ($mapping->target !== null) {
                $next[$mapping->identity()] = $mapping;
            }
        }

        $nextSchema = $this->schemas->transformedSchema($replica);
        $previousOutputs = array_map(fn (FieldMapping $mapping) => $this->schemas->outputSchema(
            $replica,
            $mapping,
            $originalSchema,
        ), $previous);
        $nextOutputs = array_map(fn (FieldMapping $mapping) => $this->schemas->outputSchema(
            $replica,
            $mapping,
            $nextSchema,
        ), $next);
        foreach ($previous as $identity => $mapping) {
            if (
                !array_key_exists($identity, $next)
                || self::columnType($nextOutputs[$identity]) !== self::columnType(
                    $previousOutputs[$identity],
                )
            ) {
                /** @var string $target */
                $target = $mapping->target;
                $changes['drop'][] = $target;
            }
        }

        foreach ($next as $identity => $mapping) {
            $existing = $previous[$identity] ?? null;
            $previousOutput = $previousOutputs[$identity] ?? null;
            $nextOutput = $nextOutputs[$identity];
            $previousType = self::columnType($previousOutput);
            $nextType = self::columnType($nextOutput);

            if (
                $existing === null
                || $previousType !== $nextType
            ) {
                $changes['columns'][] = ['mapping' => $mapping, 'from' => null];
            } elseif ($existing->target !== $mapping->target) {
                $changes['columns'][] = ['mapping' => $mapping, 'from' => $existing->target];
            }

            if (
                $existing !== null
                && $previousType === 'string'
                && $nextType === 'string'
                && $previousOutput?->effectiveMaxLength() !== $nextOutput?->effectiveMaxLength()
            ) {
                $changes['alter'][] = $mapping;
            }
        }

        return $changes;
    }

    /** @return 'boolean'|'bigInteger'|'decimal'|'text'|'date'|'time'|'dateTime'|'string'|null */
    public static function columnType(?Schema $schema): ?string
    {
        return match ($schema?->singleType()) {
            'boolean' => 'boolean',
            'integer' => 'bigInteger',
            'number' => 'decimal',
            'string' => match (true) {
                $schema->annotation('x-ferry-reference') === true => 'text',
                $schema->effectiveFormat() === 'date' => 'date',
                $schema->effectiveFormat() === 'time' => 'time',
                $schema->effectiveFormat() === 'date-time' => 'dateTime',
                default => 'string',
            },
            default => null,
        };
    }

    private function column(Blueprint $table, FieldMapping $mapping, ?Schema $field): ColumnDefinition
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
        $length =
            $mapping->kind === FieldKind::Static && $mapping->transforms() === []
                ? null
                : $field?->effectiveMaxLength();

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
