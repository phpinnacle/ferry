<?php

namespace PHPinnacle\Ferry\Contracts;

use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Common\Forms\TabbedBuilder\Block as TabbedBlock;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Jason\Schema;

interface Transform
{
    public function getKey(): string;

    public function getScope(): TransformScope;

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    public function sourceFields(array $params): array;

    public function accepts(Schema $input): bool;

    /** @param array<string, mixed> $params */
    public function output(Schema $input, array $params): ?Schema;

    public function block(): InlineBlock|TabbedBlock;

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function connector(TransformTarget $target, array $params): array;
}
