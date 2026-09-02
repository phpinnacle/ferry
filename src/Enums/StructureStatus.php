<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum StructureStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Preparing => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pending => Heroicon::Clock,
            self::Preparing => Heroicon::ArrowPath,
            self::Ready => Heroicon::CheckCircle,
            self::Failed => Heroicon::XCircle,
        };
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.structure_status.' . $this->value);
    }
}
