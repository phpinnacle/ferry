<?php

use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Rules\ReplicaSchema;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Jason\SchemaBuilder;
use Tests\TestCase;

uses(TestCase::class);

it('uses JSON Schema compatibility when saving static replicas', function (
    array $source,
    array $target,
    bool $allowed,
) {
    $schema = Schema::fromArray([
        'properties' => ['amount' => $source, '_idrref' => ['type' => 'string']],
    ]);
    $destination = new StaticDestination(
        'totals',
        'Totals',
        'totals',
        ['id'],
        SchemaBuilder::make(Schema::object([
            'id' => Schema::string(),
            'amount' => SchemaBuilder::make(Schema::fromArray($target))->title('Amount')->build(),
        ]))
            ->required(['id', 'amount'])
            ->build(),
    );
    $mappings = [
        new FieldMapping(source: 'amount', target: 'amount'),
        new FieldMapping(source: '_idrref', target: 'id'),
    ];

    if ($allowed) {
        app(ReplicaSchema::class)->assertValid(
            $mappings,
            $destination,
            ['_idrref'],
            $schema,
        );
        expect($mappings[0]->transforms())->toBe([]);
    } else {
        expect(fn () => app(ReplicaSchema::class)->assertValid(
            $mappings,
            $destination,
            ['_idrref'],
            $schema,
        ))
            ->toThrow(ValidationException::class);
    }
})->with([
    'integer to number' => [['type' => 'integer'], ['type' => 'number'], true],
    'number to integer' => [['type' => 'number'], ['type' => 'integer'], false],
    'matching formats' => [
        ['type' => 'string', 'format' => 'uuid'],
        ['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true],
        true,
    ],
    'reference to text' => [
        ['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true],
        ['type' => 'string'],
        true,
    ],
]);

it('compares native static values with destination enums and lengths', function (
    string $value,
    array $target,
    bool $allowed,
) {
    $schema = Schema::fromArray(['properties' => ['_idrref' => ['type' => 'string']]]);
    $destination = new StaticDestination(
        'labels',
        'Labels',
        'labels',
        ['id'],
        SchemaBuilder::make(Schema::object([
            'id' => Schema::string(),
            'label' => Schema::fromArray($target),
        ]))
            ->required(['id'])
            ->build(),
    );
    $mapping = new FieldMapping(source: null, target: 'label', static: $value, kind: FieldKind::Static);
    $mappings = [$mapping, new FieldMapping(source: '_idrref', target: 'id')];

    if ($allowed) {
        app(ReplicaSchema::class)->assertValid(
            $mappings,
            $destination,
            ['_idrref'],
            $schema,
        );
        expect($mapping->static)->toBe($value);
    } else {
        expect(fn () => app(ReplicaSchema::class)->assertValid(
            $mappings,
            $destination,
            ['_idrref'],
            $schema,
        ))
            ->toThrow(ValidationException::class);
    }
})->with([
    'allowed enum' => ['active', ['type' => 'string', 'enum' => ['active', 'inactive']], true],
    'outside enum' => ['other', ['type' => 'string', 'enum' => ['active', 'inactive']], false],
    'Unicode length' => ['żółć', ['type' => 'string', 'maxLength' => 4], true],
]);

it('resolves label and schema callbacks in the current request locale', function () {
    $destination = new StaticDestination(
        key: 'localized',
        label: fn () => app()->getLocale(),
        table: 'localized',
        primaryKey: ['name'],
        schema: fn () => Schema::object([
            'name' => SchemaBuilder::make()->type('string')->title(app()->getLocale())->build(),
        ]),
    );
    app()->setLocale('ru');
    expect($destination->getLabel())->toBe('ru');
    expect($destination->getSchema()->properties()['name']->keyword('title'))->toBe('ru');

    app()->setLocale('en');

    expect($destination->getLabel())->toBe('en');
    expect($destination->getSchema()->properties()['name']->keyword('title'))->toBe('en');
});
