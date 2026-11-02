<?php

namespace PHPinnacle\Ferry\Data;

/**
 * @phpstan-type RegistrySchema array{subject: string, version: int, id: int, schemaType?: string, schema: string, references?: list<array{name: string, subject: string, version: int}>, deleted?: bool}
 */
final readonly class RegisteredSchema
{
    /** @param list<array{name: string, subject: string, version: int}> $references */
    public function __construct(
        public string $subject,
        public int $version,
        public int $id,
        public string $type,
        public string $schema,
        public array $references = [],
        public ?bool $deleted = null,
    ) {}

    /** @param RegistrySchema $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['subject'],
            $data['version'],
            $data['id'],
            $data['schemaType'] ?? 'AVRO',
            $data['schema'],
            $data['references'] ?? [],
            $data['deleted'] ?? null,
        );
    }

    public function formattedSchema(): string
    {
        if ($this->type === 'PROTOBUF') {
            return $this->schema;
        }

        return json_encode(
            json_decode($this->schema, flags: JSON_THROW_ON_ERROR),
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR,
        );
    }
}
