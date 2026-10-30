<?php

namespace PHPinnacle\Ferry\Tests\Fakes;

use Closure;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Common\Forms\TabbedBuilder\Block as TabbedBlock;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform as TransformContract;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;

final class Transform implements TransformContract
{
    /**
     * @param Closure(JsonSchema): bool|null $accepts
     * @param Closure(JsonSchema, array<string, string>): (JsonSchema|null)|null $output
     * @param Closure(array<string, mixed>): array<string, string>|null $connector
     */
    public function __construct(
        private readonly string $key,
        private readonly TransformTarget $target = TransformTarget::Value,
        private readonly ?Closure $accepts = null,
        private readonly ?Closure $output = null,
        private readonly InlineBlock|TabbedBlock|null $block = null,
        private readonly ?Closure $connector = null,
        private readonly TransformScope $scope = TransformScope::Field,
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function getScope(): TransformScope
    {
        return $this->scope;
    }

    public function sourceFields(array $params): array
    {
        return [];
    }

    public function getTarget(): TransformTarget
    {
        return $this->target;
    }

    public function accepts(JsonSchema $input): bool
    {
        return $this->accepts === null || ($this->accepts)($input);
    }

    public function output(JsonSchema $input, array $params): ?JsonSchema
    {
        return $this->output === null ? $input : ($this->output)($input, $params);
    }

    public function block(): InlineBlock|TabbedBlock
    {
        return $this->block ?? InlineBlock::make($this->key);
    }

    public function connector(array $params): array
    {
        return ($this->connector)($params);
    }
}
