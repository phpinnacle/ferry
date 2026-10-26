<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasLabel;

enum KafkaType: string implements HasLabel
{
    case String = 'string';
    case Int8 = 'int8';
    case Int16 = 'int16';
    case Int32 = 'int32';
    case Int64 = 'int64';
    case Float32 = 'float32';
    case Float64 = 'float64';
    case Boolean = 'boolean';

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.kafka_type.' . $this->value);
    }

    public function jsonSchemaType(): string
    {
        return match ($this) {
            self::String => 'string',
            self::Int8, self::Int16, self::Int32, self::Int64 => 'integer',
            self::Float32, self::Float64 => 'number',
            self::Boolean => 'boolean',
        };
    }
}
