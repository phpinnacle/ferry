<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema as FilamentSchema;
use Livewire\Component;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Forms\FieldTransforms;
use PHPinnacle\Jason\Schema;

final class SchemaHost extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @return array<SchemaComponent> */
    public static function transformSchema(Transform $transform, ?Schema $input = null): array
    {
        $input ??= Schema::fromArray([]);
        $field = FieldTransforms::make('transforms')->inputSchema($input);
        FilamentSchema::make(self::make())->components([$field])->fill();

        return $transform->block()->getSchema($field, null, ['input' => $input]);
    }

    public static function make(): self
    {
        $component = new self;
        $component->setId('ferry-schema-test');
        $component->setName('ferry-schema-test');

        return $component;
    }
}
