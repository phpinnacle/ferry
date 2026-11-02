<?php

namespace PHPinnacle\Ferry\Services;

use PHPinnacle\Ferry\Exceptions\SchemaPreviewUnavailable;
use PHPinnacle\Jason\Schema;
use UnexpectedValueException;

/** @phpstan-import-type Keywords from Schema */
class AvroSchemaConverter
{
    public function convert(string $avro): Schema
    {
        $types = [];

        return Schema::fromArray($this->node(json_decode($avro, true, flags: JSON_THROW_ON_ERROR), $types));
    }

    /**
     * @param array<string, array<array-key, mixed>> $types
     * @param list<string> $active
     * @return Keywords
     */
    private function node(mixed $definition, array &$types, string $namespace = '', array $active = []): array
    {
        if (is_array($definition) && array_is_list($definition)) {
            $branches = [];
            foreach ($definition as $branch) {
                $branches[] = $this->node($branch, $types, $namespace, $active);
            }

            return ['anyOf' => $branches];
        }

        if (is_string($definition)) {
            $primitive = $this->primitive($definition);

            return (
                $primitive === null
                    ? $this->namedType($definition, $types, $namespace, $active)
                    : [...$primitive, 'x-avro-type' => $definition]
            );
        }

        if (!is_array($definition) || !array_key_exists('type', $definition)) {
            throw new UnexpectedValueException('Invalid Avro schema definition.');
        }

        $type = $definition['type'];
        $name = null;

        if (in_array($type, ['record', 'enum', 'fixed'], true)) {
            /** @var string $name */
            $name = $definition['name'];
            /** @var string $namespace */
            $namespace = $definition['namespace'] ?? $namespace;
            $name = str_contains($name, '.') || $namespace === '' ? $name : $namespace . '.' . $name;
            $separator = strrpos($name, '.');
            $namespace = $separator === false ? '' : substr($name, 0, $separator);
            $types[$name] = [...$definition, 'name' => $name];
            $active[] = $name;
        }

        $schema = match ($type) {
            'record' => $this->record($definition, $types, $namespace, $active),
            'enum' => $this->enum($definition['symbols']),
            'fixed' => $this->fixed($definition['size']),
            'array' => ['type' => 'array', 'items' => $this->node($definition['items'], $types, $namespace, $active)],
            'map' => [
                'type' => 'object',
                'additionalProperties' => $this->node($definition['values'], $types, $namespace, $active),
            ],
            default => $this->node($type, $types, $namespace, $active),
        };

        if ($name !== null) {
            $schema['title'] = $name;
        }

        if (array_key_exists('doc', $definition)) {
            $schema['description'] = is_string($definition['doc'])
                ? $definition['doc']
                : throw new UnexpectedValueException('Expected Avro documentation to be a string.');
        }

        if (array_key_exists('logicalType', $definition)) {
            $schema['format'] = is_string($definition['logicalType'])
                ? $definition['logicalType']
                : throw new UnexpectedValueException('Expected an Avro logical type name.');
        }

        return [...$schema, 'x-avro' => $definition];
    }

    /**
     * @param array<string, array<array-key, mixed>> $types
     * @param list<string> $active
     * @return Keywords
     */
    private function namedType(string $reference, array &$types, string $namespace, array $active): array
    {
        $name = str_contains($reference, '.') || $namespace === '' ? $reference : $namespace . '.' . $reference;

        if (in_array($name, $active, true)) {
            throw new SchemaPreviewUnavailable('Recursive Avro types cannot be expanded in the schema tree.');
        }

        return $this->node(
            $types[$name] ?? throw new UnexpectedValueException('Unknown Avro type: ' . $name),
            $types,
            $namespace,
            $active,
        );
    }

    /** @return Keywords|null */
    private function primitive(string $type): ?array
    {
        return match ($type) {
            'null', 'boolean', 'string' => ['type' => $type],
            'int' => ['type' => 'integer', 'minimum' => -2_147_483_648, 'maximum' => 2_147_483_647],
            'long' => ['type' => 'integer', 'format' => 'int64'],
            'float', 'double' => ['type' => 'number', 'format' => $type],
            'bytes' => ['type' => 'string', 'format' => 'bytes'],
            default => null,
        };
    }

    /** @return Keywords */
    private function enum(mixed $symbols): array
    {
        if (!is_array($symbols) || !array_is_list($symbols)) {
            throw new UnexpectedValueException('Expected a list of Avro enum symbols.');
        }

        return ['type' => 'string', 'enum' => $symbols];
    }

    /** @return Keywords */
    private function fixed(mixed $size): array
    {
        if (!is_int($size)) {
            throw new UnexpectedValueException('Expected an Avro fixed type size.');
        }

        return ['type' => 'string', 'format' => 'fixed', 'minLength' => $size, 'maxLength' => $size];
    }

    /**
     * @param array<array-key, mixed> $definition
     * @param array<string, array<array-key, mixed>> $types
     * @param list<string> $active
     * @return Keywords
     */
    private function record(array $definition, array &$types, string $namespace, array $active): array
    {
        $properties = [];
        /** @var list<array{name: string, type: mixed, doc?: string, default?: mixed}> $fields */
        $fields = $definition['fields'];

        foreach ($fields as $field) {
            $property = $this->node($field['type'], $types, $namespace, $active);

            if (array_key_exists('doc', $field)) {
                $property['description'] = $field['doc'];
            }

            if (array_key_exists('default', $field)) {
                $property['default'] = $field['default'];
            }

            $properties[$field['name']] = $property;
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
