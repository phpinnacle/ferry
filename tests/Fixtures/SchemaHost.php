<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;

final class SchemaHost extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function make(): self
    {
        $component = new self;
        $component->setId('ferry-schema-test');
        $component->setName('ferry-schema-test');

        return $component;
    }
}
