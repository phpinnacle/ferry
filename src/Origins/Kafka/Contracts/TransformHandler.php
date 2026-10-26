<?php

namespace PHPinnacle\Ferry\Origins\Kafka\Contracts;

interface TransformHandler
{
    /**
     * @param array<string, string> $params
     * @return array<string, array<string, string>>
     */
    public function connector(string $name, string $field, array $params): array;
}
