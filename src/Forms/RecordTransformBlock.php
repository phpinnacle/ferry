<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use PHPinnacle\Common\Forms\TabbedBuilder\Block;
use PHPinnacle\Eidos\Forms\JsonSchema;

final class RecordTransformBlock extends Block
{
    /** @param Closure(JsonSchema): array<Component> $components */
    public function parameters(Closure $components): static
    {
        return $this->schema(Schema::make()->components(function (Schema $schema) use ($components) {
            /** @var self $block */
            $block = $schema->getParentComponent();
            $builder = $block->getBuilder();
            /** @var string $itemPath */
            $itemPath = $schema->getStatePath(false);

            return $components($builder->getInputSchema(Str::beforeLast($itemPath, '.')));
        }));
    }

    public function getInputSchema(): JsonSchema
    {
        return $this->getBuilder()->getInputSchema();
    }

    private function getBuilder(): RecordTransforms
    {
        /** @var RecordTransforms */
        return $this->getContainer()->getParentComponent();
    }
}
