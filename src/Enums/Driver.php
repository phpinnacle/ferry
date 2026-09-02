<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasLabel;

enum Driver: string implements HasLabel
{
    case Pgsql = 'pgsql';
    case Sqlsrv = 'sqlsrv';

    public function defaultPort(): int
    {
        return match ($this) {
            self::Pgsql => 5432,
            self::Sqlsrv => 1433,
        };
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.driver.' . $this->value);
    }
}
