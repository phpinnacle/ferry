<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum SyncStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case Pause = 'pause';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Active => 'success',
            self::Pause => 'warning',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pending => Heroicon::Clock,
            self::Active => Heroicon::PlayCircle,
            self::Pause => Heroicon::PauseCircle,
        };
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.sync_status.' . $this->value);
    }
}
