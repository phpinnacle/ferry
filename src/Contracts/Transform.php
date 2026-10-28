<?php

namespace PHPinnacle\Ferry\Contracts;

use PHPinnacle\Common\Forms\InlineBuilder\Block;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Enums\TransformTarget;

interface Transform
{
    public function getKey(): string;

    public function getTarget(): TransformTarget;

    public function accepts(JsonSchema $input): bool;

    /** @param array<string, mixed> $params */
    public function output(JsonSchema $input, array $params): ?JsonSchema;

    public function block(): Block;

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function connector(string $field, array $params): array;
}
