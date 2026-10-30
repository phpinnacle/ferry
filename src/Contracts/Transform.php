<?php

namespace PHPinnacle\Ferry\Contracts;

use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Common\Forms\TabbedBuilder\Block as TabbedBlock;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;

interface Transform
{
    public function getKey(): string;

    public function getScope(): TransformScope;

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    public function sourceFields(array $params): array;

    public function getTarget(): TransformTarget;

    public function accepts(JsonSchema $input): bool;

    /** @param array<string, mixed> $params */
    public function output(JsonSchema $input, array $params): ?JsonSchema;

    public function block(): InlineBlock|TabbedBlock;

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function connector(array $params): array;
}
