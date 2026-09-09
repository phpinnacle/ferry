<?php

namespace PHPinnacle\Ferry\Tests\Fakes;

use PHPinnacle\Ferry\Contracts\StaticDestination;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Enums\ColumnType;

final class FakeCustomersDestination implements StaticDestination
{
    public function __construct(
        private readonly ?string $connectionName = null,
    ) {}

    public function connection(): ?string
    {
        return $this->connectionName;
    }

    public function fields(): array
    {
        return [
            new DestinationField('name', 'Name', ColumnType::String, required: true),
            new DestinationField('tax_number', 'Tax Number', ColumnType::String, required: true),
            new DestinationField('is_active', 'Is Active', ColumnType::Boolean, required: false),
        ];
    }

    public function fixedValues(): array
    {
        return [
            'type' => 'Customer',
            'profile_id' => 'b2b-profile-id',
        ];
    }

    public function getLabel(): string
    {
        return 'Customers';
    }

    public function key(): string
    {
        return 'customers';
    }

    public function primaryKey(): string
    {
        return 'customer_id';
    }

    public function table(): string
    {
        return 'customers';
    }
}
