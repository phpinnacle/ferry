<?php

namespace PHPinnacle\Ferry\Tests\Fakes;

use Closure;
use PHPinnacle\Common\Forms\InlineBuilder\Block;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform as TransformContract;
use PHPinnacle\Ferry\Enums\TransformTarget;

final class Transform implements TransformContract
{
    /**
     * @param Closure(JsonSchema): bool|null $accepts
     * @param Closure(JsonSchema, array<string, string>): (JsonSchema|null)|null $output
     * @param Closure(string, array<string, string>): array<string, string>|null $connector
     */
    public function __construct(
        private readonly string $key,
        private readonly TransformTarget $target = TransformTarget::Value,
        private readonly ?Closure $accepts = null,
        private readonly ?Closure $output = null,
        private readonly ?Block $block = null,
        private readonly ?Closure $connector = null,
    ) {}

    public function getKey(): string
    {
        return $this->key;
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

    public function block(): Block
    {
        return $this->block ?? Block::make($this->key);
    }

    public function connector(string $field, array $params): array
    {
        return ($this->connector)($field, $params);
    }
}
