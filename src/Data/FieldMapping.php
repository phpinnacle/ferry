<?php

namespace PHPinnacle\Ferry\Data;

use JsonSerializable;
use PHPinnacle\Ferry\Casts\FieldDecoder;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Rosetta\Contracts\Field;

/**
 * @phpstan-import-type FieldData from FieldDecoder
 * @phpstan-type MappingData array{kind: 'source', source: string, target: string|null, field: FieldData, result?: value-of<ColumnType>|null, transforms?: list<array{key: string, params: array<string, string>}>}|array{kind: 'static', target: string, static: string|int|float|bool}
 */
final readonly class FieldMapping implements JsonSerializable
{
    /** @param list<TransformSetting> $transforms */
    public function __construct(
        public ?string $source,
        public ?string $target,
        public ?Field $field,
        public ?ColumnType $result = null,
        public array $transforms = [],
        public string|int|float|bool|null $static = null,
        public FieldKind $kind = FieldKind::Source,
    ) {}

    /** @param MappingData $data */
    public static function fromArray(array $data): self
    {
        return match ($data['kind']) {
            'source' => new self(
                source: $data['source'],
                target: $data['target'],
                field: FieldDecoder::decode($data['field']),
                result: isset($data['result']) ? ColumnType::from($data['result']) : null,
                transforms: array_map(TransformSetting::fromArray(...), $data['transforms'] ?? []),
            ),
            'static' => new self(null, $data['target'], null, static: $data['static'], kind: FieldKind::Static),
        };
    }

    /** @return MappingData */
    public function jsonSerialize(): array
    {
        if ($this->kind === FieldKind::Static) {
            /** @var string $target */
            $target = $this->target;
            /** @var string|int|float|bool $value */
            $value = $this->static;

            return ['kind' => 'static', 'target' => $target, 'static' => $value];
        }

        /** @var string $source */
        $source = $this->source;
        /** @var Field $field */
        $field = $this->field;

        /** @var FieldData $data */
        $data = $field->jsonSerialize();

        return [
            'kind' => 'source',
            'source' => $source,
            'target' => $this->target,
            'field' => $data,
            'result' => $this->result?->value,
            'transforms' => array_map(
                static fn (TransformSetting $setting) => $setting->jsonSerialize(),
                $this->transforms,
            ),
        ];
    }

    public function identity(): string
    {
        return $this->source ?? 'static:' . $this->target;
    }

    public function isUsed(): bool
    {
        return $this->target !== null || $this->transforms !== [];
    }

    public function sourceType(): ?ColumnType
    {
        return $this->field !== null ? ColumnType::fromField($this->field) : null;
    }

    public function type(): ?ColumnType
    {
        if ($this->kind === FieldKind::Static) {
            /** @var string|int|float|bool $value */
            $value = $this->static;

            return ColumnType::fromValue($value);
        }

        return $this->result ?? ($this->transforms === [] ? $this->sourceType() : null);
    }
}
