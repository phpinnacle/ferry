<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasLabel;

enum DestinationType: string implements HasLabel
{
    case Dynamic = 'dynamic';
    case Static = 'static';

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.destination_type.' . $this->value);
    }
}
