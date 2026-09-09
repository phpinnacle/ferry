<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ConnectorStatus: string implements HasColor, HasIcon, HasLabel
{
    case Unknown = 'unknown';
    case Running = 'running';
    case Paused = 'paused';
    case Failed = 'failed';

    public static function fromConnectState(string $state): self
    {
        return match (strtoupper($state)) {
            'RUNNING' => self::Running,
            'PAUSED', 'STOPPED' => self::Paused,
            'FAILED' => self::Failed,
            default => self::Unknown,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::Running => 'success',
            self::Paused => 'warning',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Unknown => Heroicon::QuestionMarkCircle,
            self::Running => Heroicon::CheckCircle,
            self::Paused => Heroicon::PauseCircle,
            self::Failed => Heroicon::XCircle,
        };
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.connector_status.' . $this->value);
    }
}
