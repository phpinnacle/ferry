<?php

namespace PHPinnacle\Ferry\Casts;

use PHPinnacle\Rosetta\Contracts\Field;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ReferenceField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
use PHPinnacle\Rosetta\Fields\UnionField;

/**
 * @phpstan-type ScalarData array{type: 'boolean'|'date'|'time'|'datetime'|'binary'|'id'|'uuid'|'unknown'|'undefined'}
 * @phpstan-type NumberData array{type: 'number', precision?: int, scale?: int, unsigned: bool}
 * @phpstan-type ReferenceData array{type: 'reference', target: string}
 * @phpstan-type StringData array{type: 'string', length?: int, fixed: bool}
 * @phpstan-type LeafData ScalarData|NumberData|ReferenceData|StringData
 * @phpstan-type UnionData array{type: 'union', variants: non-empty-list<LeafData>}
 * @phpstan-type FieldData LeafData|UnionData
 */
class FieldDecoder
{
    /** @param FieldData $data */
    public static function decode(array $data): Field
    {
        return match ($data['type']) {
            'boolean', 'date', 'time', 'datetime', 'binary', 'id', 'uuid', 'unknown', 'undefined' => new ScalarField(
                FieldType::from($data['type']),
            ),
            'number' => new NumberField(
                precision: $data['precision'] ?? null,
                scale: $data['scale'] ?? null,
                unsigned: $data['unsigned'],
            ),
            'reference' => new ReferenceField($data['target']),
            'string' => new StringField(
                length: $data['length'] ?? null,
                fixed: $data['fixed'],
            ),
            'union' => new UnionField(array_map(self::decode(...), $data['variants'])),
        };
    }
}
