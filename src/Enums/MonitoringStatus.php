<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum MonitoringStatus: string implements HasColor, HasIcon, HasLabel
{
    case Disabled = 'disabled';
    case NoConnector = 'no_connector';
    case Unavailable = 'unavailable';
    case NoData = 'no_data';
    case ScrapeFailed = 'scrape_failed';
    case Stale = 'stale';
    case Ready = 'ready';

    public function getColor(): string
    {
        return match ($this) {
            self::Disabled, self::NoConnector => 'gray',
            self::Unavailable, self::ScrapeFailed => 'danger',
            self::NoData, self::Stale => 'warning',
            self::Ready => 'success',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Disabled => Heroicon::MinusCircle,
            self::NoConnector => Heroicon::LinkSlash,
            self::Unavailable => Heroicon::XCircle,
            self::NoData => Heroicon::QuestionMarkCircle,
            self::ScrapeFailed => Heroicon::ExclamationCircle,
            self::Stale => Heroicon::Clock,
            self::Ready => Heroicon::CheckCircle,
        };
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::monitoring.statuses.' . $this->value);
    }
}
