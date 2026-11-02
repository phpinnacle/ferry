<?php

use PHPinnacle\Ferry\Exceptions\SchemaPreviewUnavailable;
use PHPinnacle\Ferry\Services\AvroSchemaConverter;

it('presents nullable fields and Avro defaults without treating fields as optional', function () {
    $schema = new AvroSchemaConverter()->convert(<<<'JSON'
        {
          "type": "record", "name": "Customer", "namespace": "shop", "doc": "Customer record",
          "fields": [
            {"name": "id", "type": "long"},
            {"name": "email", "type": ["null", "string"], "default": null, "doc": "Delivery address"},
            {"name": "active", "type": "boolean", "default": false}
          ]
        }
        JSON);

    expect($schema->title())
        ->toBe('shop.Customer')
        ->and($schema->description())
        ->toBe('Customer record')
        ->and($schema->required())
        ->toBe(['id', 'email', 'active']);
    $email = $schema->properties()['email']->toArray();
    expect($email)->toMatchArray([
        'description' => 'Delivery address',
        'anyOf' => [['type' => 'null', 'x-avro-type' => 'null'], ['type' => 'string', 'x-avro-type' => 'string']],
        'default' => null,
    ]);
    expect($schema->properties()['active']->toArray())->toHaveKey('default', false);
});

it('resolves named record and enum references in their defining namespaces', function () {
    $schema = new AvroSchemaConverter()->convert(<<<'JSON'
        {
          "type": "record", "name": "shop.Customer",
          "fields": [
            {"name": "address", "type": {"type": "record", "name": "Address", "fields": [{"name": "city", "type": "string"}]}},
            {"name": "billing", "type": "Address"},
            {"name": "status", "type": {"type": "enum", "name": "Status", "symbols": ["NEW", "ACTIVE"]}},
            {"name": "previous_status", "type": "shop.Status"},
            {"name": "details", "type": {"type": "record", "name": "other.Details", "fields": [{"name": "address", "type": "shop.Address"}]}}
          ]
        }
        JSON);
    $properties = $schema->properties();

    expect($properties['billing']->properties()['city']->types())
        ->toBe(['string'])
        ->and($properties['previous_status']->enumValues())
        ->toBe(['NEW', 'ACTIVE'])
        ->and($properties['details']->properties()['address']->title())
        ->toBe('shop.Address');
});

it('presents arrays maps fixed bytes and logical types with their underlying representations', function () {
    $schema = new AvroSchemaConverter()->convert(<<<'JSON'
        {
          "type": "record", "name": "Values",
          "fields": [
            {"name": "tags", "type": {"type": "array", "items": "string"}},
            {"name": "scores", "type": {"type": "map", "values": "int"}},
            {"name": "token", "type": {"type": "fixed", "name": "Token", "size": 16}},
            {"name": "created_at", "type": {"type": "long", "logicalType": "timestamp-micros"}},
            {"name": "amount", "type": {"type": "bytes", "logicalType": "decimal", "precision": 12, "scale": 2}}
          ]
        }
        JSON);
    $properties = $schema->properties();

    expect($properties['tags']->toArray()['items']['type'])
        ->toBe('string')
        ->and($properties['scores']->toArray()['additionalProperties'])
        ->toMatchArray(['type' => 'integer', 'maximum' => 2_147_483_647])
        ->and($properties['token']->toArray())
        ->toMatchArray(['type' => 'string', 'minLength' => 16, 'maxLength' => 16])
        ->and($properties['created_at']->types())
        ->toBe(['integer'])
        ->and($properties['created_at']->format())
        ->toBe('timestamp-micros')
        ->and($properties['amount']->toArray()['x-avro'])
        ->toMatchArray(['precision' => 12, 'scale' => 2]);
});

it('rejects recursive previews explicitly instead of truncating their fields', function () {
    expect(fn () => new AvroSchemaConverter()->convert(<<<'JSON'
        {"type":"record","name":"Node","fields":[{"name":"next","type":["null","Node"]}]}
        JSON))->toThrow(SchemaPreviewUnavailable::class);
});

it('does not retain named types between conversions', function () {
    $converter = new AvroSchemaConverter;
    $converter->convert('{"type":"record","name":"Customer","fields":[]}');

    expect(fn () => $converter->convert('"Customer"'))->toThrow(UnexpectedValueException::class, 'Unknown Avro type');
});

it('rejects invalid external Avro definitions', function (string $definition) {
    expect(fn () => new AvroSchemaConverter()->convert($definition))->toThrow(UnexpectedValueException::class);
})->with(['null', '12', '{"fields":[]}']);
