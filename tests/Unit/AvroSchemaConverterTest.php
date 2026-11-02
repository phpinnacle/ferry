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

it('presents Debezium UUID strings including nullable fields with the uuid format', function () {
    $schema = new AvroSchemaConverter()->convert(<<<'JSON'
        {
          "type": "record", "name": "Customer",
          "fields": [
            {"name": "id", "type": {"type": "string", "connect.version": 1, "connect.name": "io.debezium.data.Uuid"}},
            {"name": "parent_id", "type": ["null", {"type": "string", "connect.version": 1, "connect.name": "io.debezium.data.Uuid"}], "default": null},
            {"name": "label", "type": {"type": "string", "connect.name": "example.Label"}}
          ]
        }
        JSON);
    $properties = $schema->properties();

    expect($properties['id']->types())
        ->toBe(['string'])
        ->and($properties['id']->format())
        ->toBe('uuid')
        ->and($properties['id']->toArray()['x-avro'])
        ->toBe([
            'type' => 'string',
            'connect.version' => 1,
            'connect.name' => 'io.debezium.data.Uuid',
        ]);
    expect($properties['parent_id']->toArray())->toMatchArray([
        'anyOf' => [
            ['type' => 'null', 'x-avro-type' => 'null'],
            $properties['id']->toArray(),
        ],
        'default' => null,
    ]);
    expect($properties['label']->types())->toBe(['string'])->and($properties['label']->format())->toBeNull();
});

it('preserves temporal representations and exposes their Debezium precision', function (
    string $type,
    string $name,
    string $format,
) {
    $schema = new AvroSchemaConverter()->convert(json_encode([
        'type' => $type,
        'connect.name' => 'io.debezium.time.' . $name,
    ], JSON_THROW_ON_ERROR));

    expect($schema->types())
        ->toBe([$type === 'string' ? 'string' : 'integer'])
        ->and($schema->format())
        ->toBe($format);
})->with([
    'date' => ['int', 'Date', 'date'],
    'time in milliseconds' => ['int', 'Time', 'time-millis'],
    'time in microseconds' => ['long', 'MicroTime', 'time-micros'],
    'time in nanoseconds' => ['long', 'NanoTime', 'time-nanos'],
    'timestamp in milliseconds' => ['long', 'Timestamp', 'timestamp-millis'],
    'timestamp in microseconds' => ['long', 'MicroTimestamp', 'timestamp-micros'],
    'timestamp in nanoseconds' => ['long', 'NanoTimestamp', 'timestamp-nanos'],
    'zoned timestamp' => ['string', 'ZonedTimestamp', 'date-time'],
    'zoned time' => ['string', 'ZonedTime', 'time'],
    'ISO date with UTC suffix' => ['string', 'IsoDate', 'iso-date'],
    'ISO time' => ['string', 'IsoTime', 'time'],
    'ISO timestamp' => ['string', 'IsoTimestamp', 'date-time'],
    'year' => ['int', 'Year', 'year'],
    'duration in microseconds' => ['long', 'MicroDuration', 'duration-micros'],
    'interval' => ['string', 'Interval', 'duration'],
]);

it('keeps JSON and XML payloads as strings with their media types', function (
    string $name,
    string $format,
    string $mediaType,
) {
    $schema = new AvroSchemaConverter()->convert(json_encode([
        'type' => 'string',
        'connect.name' => 'io.debezium.data.' . $name,
    ], JSON_THROW_ON_ERROR));

    expect($schema->types())
        ->toBe(['string'])
        ->and($schema->format())
        ->toBe($format)
        ->and($schema->contentMediaType())
        ->toBe($mediaType);
})->with([
    'JSON' => ['Json', 'json', 'application/json'],
    'XML' => ['Xml', 'xml', 'application/xml'],
]);

it('presents nullable Debezium enums without trimming or losing empty values', function () {
    $schema = new AvroSchemaConverter()->convert(<<<'JSON'
        ["null", {"type": "string", "connect.name": "io.debezium.data.Enum", "connect.parameters": {"allowed": "NEW,IN PROGRESS,, leading space"}}]
        JSON);

    expect($schema->alternatives()[0]->types())
        ->toBe(['null'])
        ->and($schema->alternatives()[1]->types())
        ->toBe(['string'])
        ->and($schema->alternatives()[1]->enumValues())
        ->toBe(['NEW', 'IN PROGRESS', '', ' leading space']);
});

it('rejects Debezium enums without a string of allowed values', function (mixed $parameters) {
    $avro = json_encode([
        'type' => 'string',
        'connect.name' => 'io.debezium.data.Enum',
        'connect.parameters' => $parameters,
    ], JSON_THROW_ON_ERROR);

    expect(fn () => new AvroSchemaConverter()->convert($avro))->toThrow(UnexpectedValueException::class);
})->with([
    'missing allowed' => [[]],
    'non-string allowed' => [['allowed' => 42]],
    'non-object parameters' => ['invalid'],
]);

it('uses Kafka Connect integer widths while leaving unknown widths unchanged', function (
    string $width,
    int $minimum,
    int $maximum,
) {
    $schema = new AvroSchemaConverter()->convert(json_encode([
        'type' => 'int',
        'connect.type' => $width,
    ], JSON_THROW_ON_ERROR));

    expect($schema->types())
        ->toBe(['integer'])
        ->and($schema->minimum())
        ->toBe($minimum)
        ->and($schema->maximum())
        ->toBe($maximum);
})->with([
    'int8' => ['int8', -128, 127],
    'int16' => ['int16', -32_768, 32_767],
    'unknown width' => ['custom', -2_147_483_648, 2_147_483_647],
]);

it('retains source SQL metadata without applying it as constraints on transformed values', function () {
    $definition = [
        'type' => 'string',
        'connect.parameters' => [
            '__debezium.source.column.type' => 'NUMERIC',
            '__debezium.source.column.length' => '12',
            '__debezium.source.column.scale' => '2',
        ],
    ];

    $schema = new AvroSchemaConverter()->convert(json_encode($definition, JSON_THROW_ON_ERROR));

    expect($schema->toArray())->toBe([
        'type' => 'string',
        'x-avro-type' => 'string',
        'x-avro' => $definition,
    ]);
});

it('keeps explicit Avro logical types ahead of Connect presentation formats', function () {
    $schema = new AvroSchemaConverter()->convert(<<<'JSON'
        {"type":"long","logicalType":"timestamp-millis","connect.name":"io.debezium.time.MicroTimestamp"}
        JSON);

    expect($schema->types())->toBe(['integer'])->and($schema->format())->toBe('timestamp-millis');
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
