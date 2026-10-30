<?php

namespace PHPinnacle\Ferry\Data;

use JsonSerializable;
use PHPinnacle\Eidos\Forms\SchemaCompatibility;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Jason\Schema;

/**
 * @phpstan-type MappingData array{kind: 'source', source: string, target: string|null, options?: array<string, mixed>}|array{kind: 'static', target: string|null, static: string|int|float|bool, options?: array<string, mixed>}
 */
final readonly class FieldMapping implements JsonSerializable
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public ?string $source,
        public ?string $target,
        public array $options = [],
        public string|int|float|bool|null $static = null,
        public FieldKind $kind = FieldKind::Source,
    ) {}

    /** @param MappingData $data */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['kind'] === 'source' ? $data['source'] : null,
            target: $data['target'],
            options: $data['options'] ?? [],
            static: $data['kind'] === 'static' ? $data['static'] : null,
            kind: FieldKind::from($data['kind']),
        );
    }

    /** @return MappingData */
    public function jsonSerialize(): array
    {
        if ($this->kind === FieldKind::Static) {
            /** @var string|int|float|bool $value */
            $value = $this->static;

            return ['kind' => 'static', 'target' => $this->target, 'static' => $value, 'options' => $this->options];
        }

        /** @var string $source */
        $source = $this->source;

        return ['kind' => 'source', 'source' => $source, 'target' => $this->target, 'options' => $this->options];
    }

    /** @return list<TransformSetting> */
    public function transforms(): array
    {
        /** @var list<array{type: string, data?: array<string, mixed>}> $settings */
        $settings = $this->options['transforms'] ?? [];

        return array_map(TransformSetting::fromArray(...), $settings);
    }

    public function identity(): string
    {
        return $this->source ?? 'static:' . $this->target;
    }

    public function isUsed(): bool
    {
        return $this->target !== null || $this->transforms() !== [];
    }

    public function staticSchema(): Schema
    {
        return Schema::fromArray([
            'type' => match (true) {
                is_bool($this->static) => 'boolean',
                is_int($this->static) => 'integer',
                is_float($this->static) => 'number',
                default => 'string',
            },
            'enum' => [$this->static],
            ...(
                is_string($this->static)
                    ? ['minLength' => mb_strlen($this->static), 'maxLength' => mb_strlen($this->static)]
                    : []
            ),
        ]);
    }

    public function canMapTo(Schema $output, Schema $target): bool
    {
        $compatibility = SchemaCompatibility::make();
        if ($this->kind === FieldKind::Static && $this->transforms() === []) {
            // Native static forms validate formats on values; raw values have no declared format.
            $compatibility = $compatibility->formats(false);
        }

        return $compatibility->accepts($output, $target);
    }
}
