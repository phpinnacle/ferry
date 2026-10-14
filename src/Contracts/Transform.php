<?php

namespace PHPinnacle\Ferry\Contracts;

use Filament\Forms\Components\Field;
use Filament\Support\Contracts\HasLabel;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

interface Transform extends HasLabel
{
    public string $key { get; }

    public TransformScope $scope { get; }

    public function getLabel(): string;

    /** @return array<Field> */
    public function schema(?ConnectionMetadata $object): array;

    /**
     * @param array<string, string> $params
     * @return array<string, string>
     */
    public function normalize(array $params, ConnectionMetadata $object): array;

    /**
     * @param array<string, string> $params
     * @return array<string, list<mixed>>
     */
    public function rules(array $params): array;

    /**
     * @param array<string, string> $params
     * @return list<string>
     */
    public function sourceColumns(array $params): array;

    /** @param array<string, string> $params */
    public function resultType(?ColumnType $input, array $params): ?ColumnType;

    /**
     * @param array<string, string> $params
     * @return array<string, array<string, string>>
     */
    public function connector(string $name, ?string $field, array $params): array;
}
