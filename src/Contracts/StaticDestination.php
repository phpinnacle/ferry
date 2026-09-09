<?php

namespace PHPinnacle\Ferry\Contracts;

use Filament\Support\Contracts\HasLabel;
use PHPinnacle\Ferry\Data\DestinationField;

interface StaticDestination extends HasLabel
{
    public function key(): string;

    public function getLabel(): string;

    public function table(): string;

    public function primaryKey(): string;

    public function connection(): ?string;

    /** @return list<DestinationField> */
    public function fields(): array;

    /** @return array<string, string> */
    public function fixedValues(): array;
}
