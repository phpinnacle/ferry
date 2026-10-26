<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Forms\FieldTransforms;

final class SchemaHost extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @return array<SchemaComponent> */
    public static function transformSchema(Transform $transform, ?JsonSchema $input = null): array
    {
        $field = FieldTransforms::make('transforms')->inputSchema($input?->toArray() ?? []);
        Schema::make(self::make())->components([$field])->fill();

        return $transform->block()->getSchema($field, null);
    }

    public static function make(): self
    {
        $component = new self;
        $component->setId('ferry-schema-test');
        $component->setName('ferry-schema-test');

        return $component;
    }
}
