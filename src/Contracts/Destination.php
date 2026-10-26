<?php

namespace PHPinnacle\Ferry\Contracts;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

interface Destination extends HasLabel, HasIcon, HasColor
{
    public function getTable(): string;

    public function getConnectionName(): ?string;

    public function getPrimaryKey(): string;

    /** @return array<string, string> */
    public function getFixedValues(): array;
}
