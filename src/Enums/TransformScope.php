<?php

namespace PHPinnacle\Ferry\Enums;

enum TransformScope: string
{
    case Record = 'record';
    case Field = 'field';
    case Static = 'static';
}
