<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasLabel;

enum SSLMode: string implements HasLabel
{
    case Disable = 'disable';
    case Allow = 'allow';
    case Prefer = 'prefer';
    case Require = 'require';
    case VerifyCa = 'verify-ca';
    case VerifyFull = 'verify-full';

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.ssl_mode.' . $this->value);
    }
}
