<?php

namespace PHPinnacle\Ferry\Tests\Fakes;

use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Jason\Schema;

function customers_destination(?string $connectionName = null): StaticDestination
{
    return new StaticDestination(
        key: 'customers',
        label: 'Customers',
        table: 'customers',
        primaryKey: ['customer_id'],
        schema: Schema::object([
            'name' => Schema::string()->title('Name'),
            'tax_number' => Schema::string()->title('Tax Number'),
            'is_active' => Schema::boolean()->title('Is Active'),
        ])->required(['name', 'tax_number']),
        connection: $connectionName,
        fixedValues: [
            'type' => 'Customer',
            'profile_id' => 'b2b-profile-id',
        ],
    );
}
