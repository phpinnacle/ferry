<?php

namespace PHPinnacle\Ferry\Origins\Kafka;

use Illuminate\Container\Attributes\Singleton;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Jason\Schema;

#[Singleton]
class TransformFactory
{
    public function __construct(
        private readonly TransformRegistry $transforms,
        private readonly ReplicaSchemaResolver $schemas,
    ) {}

    /**
     * @param array<string, string> $keyMap
     * @param array<string, string> $fixedValues
     * @return array<string, string>
     */
    public function pipeline(
        Replica $replica,
        StructureItem $object,
        array $keyMap = [],
        array $fixedValues = [],
    ): array {
        $pipeline = [];
        $columns = $replica->columnMap();
        $reserved = array_fill_keys([
            ...array_keys($object->schema->resolveReferences()->effectiveProperties()),
            ...array_values($columns),
            ...$replica->constantColumns(),
            ...array_keys($fixedValues),
        ], true);
        foreach ($replica->mapping as $mapping) {
            if ($mapping->source !== null) {
                $reserved[$mapping->source] = true;
            }
        }

        foreach ($replica->transforms as $index => $setting) {
            $pipeline['replica_' . $index] = $this->transforms->get($setting->type)->connector($setting->data);
        }

        foreach ($replica->mapping as $index => $mapping) {
            if ($mapping->kind === FieldKind::Static) {
                if ($mapping->target === null) {
                    continue;
                }

                $source = self::temporaryField('__ferry_static_' . $index, $reserved);
                $columns[$source] = $mapping->target;
                $pipeline += self::constant('field_' . $index . '_0', $source, $mapping);
                $offset = 1;
            } else {
                /** @var string $source */
                $source = $mapping->source;
                $offset = 0;
            }

            foreach ($mapping->transforms() as $position => $setting) {
                $name = sprintf('field_%d_%d', $index, $position + $offset);
                $pipeline[$name] = $this->transforms->get($setting->type)->connector(['field' => $source]
                + $setting->data);
            }
        }

        foreach ($fixedValues as $field => $value) {
            $source = self::temporaryField('__ferry_fixed_' . $field, $reserved);
            $columns[$source] = $field;
            $pipeline['insert_' . $field] = [
                'type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
                'static.field' => $source,
                'static.value' => $value,
            ];
            // Destination-owned values take precedence over mapped values.
            foreach ($columns as $column => $target) {
                if ($column !== $source && $target === $field) {
                    unset($columns[$column]);
                }
            }
        }

        if ($keyMap !== []) {
            $pipeline['rename_keys'] = [
                'type' => 'org.apache.kafka.connect.transforms.ReplaceField$Key',
                'include' => implode(',', array_keys($keyMap)),
                'renames' => self::renames($keyMap),
            ];
        }

        $columns = array_diff_key($columns, $keyMap);
        $pipeline['rename_values'] = [
            'type' => 'org.apache.kafka.connect.transforms.ReplaceField$Value',
            'include' => implode(',', array_keys($columns)),
            'renames' => self::renames($columns),
        ];
        if ($columns === []) {
            /** @var Schema $schema */
            $schema = $this->schemas->resultSchema(
                $object->schema,
                $replica->transforms,
                null,
                TransformScope::Record,
            );
            // An empty include retains every field, so key-only sinks must explicitly exclude them.
            $pipeline['rename_values']['exclude'] = implode(
                ',',
                array_keys($schema->resolveReferences()->effectiveProperties()),
            );
        }

        return self::configuration($pipeline);
    }

    /** @param array<string, true> $reserved */
    private static function temporaryField(string $name, array &$reserved): string
    {
        while (array_key_exists($name, $reserved)) {
            $name .= '_';
        }

        $reserved[$name] = true;

        return $name;
    }

    /** @param array<string, string> $columns */
    private static function renames(array $columns): string
    {
        $renames = [];
        foreach ($columns as $source => $destination) {
            $renames[] = sprintf('%s:%s', $source, $destination);
        }

        return implode(',', $renames);
    }

    /**
     * @param array<string, array<string, string>> $pipeline
     * @return array<string, string>
     */
    private static function configuration(array $pipeline): array
    {
        $config = ['transforms' => implode(',', array_keys($pipeline))];
        foreach ($pipeline as $name => $properties) {
            foreach ($properties as $property => $value) {
                $config[sprintf('transforms.%s.%s', $name, $property)] = $value;
            }

            if (($properties['predicate'] ?? null) === 'tombstone') {
                $config['predicates'] = 'tombstone';
                $config['predicates.tombstone.type'] = 'org.apache.kafka.connect.transforms.predicates.RecordIsTombstone';
            }
        }

        return $config;
    }

    /** @return array<string, array<string, string>> */
    private static function constant(string $name, string $field, FieldMapping $mapping): array
    {
        $transforms = [
            $name => [
                'type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
                'static.field' => $field,
                'static.value' => match ($mapping->static) {
                    true => 'true',
                    false => 'false',
                    default => (string) $mapping->static,
                },
            ],
        ];

        $cast = match (true) {
            is_bool($mapping->static) => 'boolean',
            is_int($mapping->static) => 'int64',
            is_float($mapping->static) => 'float64',
            default => null,
        };
        if ($cast !== null) {
            $transforms[$name . '_cast'] = [
                'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
                'spec' => $field . ':' . $cast,
            ];
        }

        return $transforms;
    }
}
