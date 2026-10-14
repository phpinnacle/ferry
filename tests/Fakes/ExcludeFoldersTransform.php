<?php

namespace PHPinnacle\Ferry\Tests\Fakes;

use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

function exclude_folders_transform(): Transform
{
    return new class implements Transform {
        public string $key {
            get => 'exclude_folders';
        }

        public TransformScope $scope {
            get => TransformScope::Record;
        }

        public function getLabel(): string
        {
            return 'Exclude folders';
        }

        public function schema(?ConnectionMetadata $object): array
        {
            return [];
        }

        public function normalize(array $params, ConnectionMetadata $object): array
        {
            return $params;
        }

        public function rules(array $params): array
        {
            return [];
        }

        public function sourceColumns(array $params): array
        {
            return ['_folder'];
        }

        public function resultType(?ColumnType $input, array $params): ?ColumnType
        {
            return $input;
        }

        public function connector(string $name, ?string $field, array $params): array
        {
            return [$name => ['type' => 'io.confluent.connect.transforms.Filter$Value']];
        }
    };
}
