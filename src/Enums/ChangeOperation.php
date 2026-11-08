<?php

namespace PHPinnacle\Ferry\Enums;

enum ChangeOperation: string
{
    case Create = 'c';
    case Update = 'u';
    case Delete = 'd';
    case Read = 'r';
}
