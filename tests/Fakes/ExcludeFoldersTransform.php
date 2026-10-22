<?php

namespace PHPinnacle\Ferry\Tests\Fakes;

use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Transformation;

function exclude_folders_transform(): Transformation
{
    config(['phpinnacle-ferry.transforms.exclude_folders' => ExcludeFoldersHandler::class]);

    return Transformation::make('exclude_folders', TransformScope::Record)->label('Exclude folders');
}

final class ExcludeFoldersHandler implements TransformHandler
{
    public function normalize(array $params, ConnectionMetadata $object): array
    {
        return $params;
    }

    public function sourceColumns(array $params): array
    {
        return ['_folder'];
    }

    public function connector(string $name, ?string $field, array $params): array
    {
        return [$name => ['type' => 'io.confluent.connect.transforms.Filter$Value']];
    }
}
