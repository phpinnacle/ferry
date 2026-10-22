<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;

enum CustomerMappingField: string implements HasDescription, HasIcon, HasLabel
{
    case Name = 'name';
    case TaxNumber = 'tax_number';
    case Email = 'email';
    case Phone = 'phone';
    case Active = 'is_active';
    case ExternalId = 'external_id';

    /** @return array<string, mixed> */
    public static function jsonSchema(): array
    {
        $properties = [];
        foreach (self::cases() as $field) {
            $properties[$field->value] = [
                'title' => $field->getLabel(),
                'description' => $field->getDescription(),
                'x-icon' => $field->getIcon()->getIconForSize(IconSize::Small),
                'type' => $field === self::Active ? 'boolean' : 'string',
            ];
        }
        $properties['phone'] = [...$properties['phone'], 'type' => 'array', 'items' => ['type' => 'string']];
        $properties['external_id']['x-badges'] = [['label' => 'Unique', 'color' => 'success']];

        return ['type' => 'object', 'properties' => $properties, 'required' => ['name', 'email', 'external_id']];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Name => 'Customer name',
            self::TaxNumber => 'Tax number',
            self::Email => 'Email address',
            self::Phone => 'Phone number',
            self::Active => 'Active customer',
            self::ExternalId => 'External identifier',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Name => 'The name displayed on the customer profile.',
            self::TaxNumber => 'The customer tax registration number.',
            self::Email => 'The address used for customer correspondence.',
            self::Phone => 'The primary contact phone number.',
            self::Active => 'Whether the customer is currently active.',
            self::ExternalId => 'The identifier used by the external system.',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Name => Heroicon::OutlinedUser,
            self::TaxNumber => Heroicon::OutlinedIdentification,
            self::Email => Heroicon::OutlinedEnvelope,
            self::Phone => Heroicon::OutlinedPhone,
            self::Active => Heroicon::OutlinedCheckCircle,
            self::ExternalId => Heroicon::OutlinedKey,
        };
    }
}
