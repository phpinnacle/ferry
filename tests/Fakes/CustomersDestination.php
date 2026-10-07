<?php

namespace PHPinnacle\Ferry\Tests\Fakes;

use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;

function customers_destination(?string $connectionName = null): StaticDestination
{
    return new StaticDestination(
        key: 'customers',
        label: 'Customers',
        table: 'customers',
        primaryKey: 'customer_id',
        fields: [
            new DestinationField('name', 'Name', ColumnType::String, required: true),
            new DestinationField('tax_number', 'Tax Number', ColumnType::String, required: true),
            new DestinationField('is_active', 'Is Active', ColumnType::Boolean, required: false),
        ],
        connection: $connectionName,
        fixedValues: [
            'type' => 'Customer',
            'profile_id' => 'b2b-profile-id',
        ],
    );
}
