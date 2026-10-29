<?php

namespace PHPinnacle\Ferry\Tests\Fakes;

use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Jason\SchemaBuilder;

function customers_destination(?string $connectionName = null): StaticDestination
{
    return new StaticDestination(
        key: 'customers',
        label: 'Customers',
        table: 'customers',
        primaryKey: ['customer_id'],
        schema: SchemaBuilder::make(Schema::object([
            'customer_id' => SchemaBuilder::make()
                ->type('string')
                ->format('uuid')
                ->with('x-ferry-reference', true)
                ->title('Customer ID')
                ->build(),
            'name' => SchemaBuilder::make()->type('string')->title('Name')->build(),
            'tax_number' => SchemaBuilder::make()->type('string')->title('Tax Number')->build(),
            'is_active' => SchemaBuilder::make()->type('boolean')->title('Is Active')->build(),
        ]))
            ->required(['customer_id', 'name', 'tax_number'])
            ->build(),
        connection: $connectionName,
        fixedValues: [
            'type' => 'Customer',
            'profile_id' => 'b2b-profile-id',
        ],
    );
}
