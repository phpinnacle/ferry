<?php

use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Rules\ReplicaSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Jason\SchemaBuilder;
use Tests\TestCase;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../Fakes/CustomersDestination.php';

uses(TestCase::class);

it('uses JSON Schema compatibility when saving static replicas', function (
    array $source,
    array $target,
    bool $allowed,
) {
    $schema = JsonSchema::fromArray([
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
            ['_idrref'],
        );
        expect($mappings[0]->transforms())->toBe([]);
    } else {
        expect(fn () => app(ReplicaSchema::class)->assertValid(
            $mappings,
            $destination,
            ['_idrref'],
            $schema,
            ['_idrref'],
        ))
            ->toThrow(ValidationException::class);
    }
})->with([
    'integer to number' => [['type' => 'integer'], ['type' => 'number'], true],
    'number to integer' => [['type' => 'number'], ['type' => 'integer'], false],
    'matching integers' => [['type' => 'integer'], ['type' => 'integer'], true],
    'matching numbers' => [['type' => 'number'], ['type' => 'number'], true],
    'nullable source' => [['type' => ['string', 'null']], ['type' => 'string'], false],
    'nullable target' => [['type' => 'string'], ['type' => ['string', 'null']], true],
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
    'text to UUID' => [['type' => 'string'], ['type' => 'string', 'format' => 'uuid'], false],
    'date-time to text' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'string'], true],
    'text to date-time' => [['type' => 'string'], ['type' => 'string', 'format' => 'date-time'], false],
    'fitting string' => [['type' => 'string', 'maxLength' => 40], ['type' => 'string', 'maxLength' => 80], true],
    'oversized string' => [['type' => 'string', 'maxLength' => 80], ['type' => 'string', 'maxLength' => 40], false],
    'unknown string bound' => [['type' => 'string'], ['type' => 'string', 'maxLength' => 40], false],
    'enum subset' => [
        ['type' => 'string', 'enum' => ['a']],
        ['type' => 'string', 'enum' => ['a', 'b']],
        true,
    ],
    'unrestricted source to enum' => [['type' => 'string'], ['type' => 'string', 'enum' => ['a']], false],
    'composed string' => [
        ['allOf' => [['type' => 'string'], ['maxLength' => 40]]],
        ['type' => 'string', 'maxLength' => 80],
        true,
    ],
]);

it('compares native static values with destination enums and lengths', function (
    string $value,
    array $target,
    bool $allowed,
) {
    $schema = JsonSchema::fromArray(['properties' => ['_idrref' => ['type' => 'string']]]);
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
            ['_idrref'],
        );
        expect($mapping->static)->toBe($value);
    } else {
        expect(fn () => app(ReplicaSchema::class)->assertValid(
            $mappings,
            $destination,
            ['_idrref'],
            $schema,
            ['_idrref'],
        ))
            ->toThrow(ValidationException::class);
    }
})->with([
    'allowed enum' => ['active', ['type' => 'string', 'enum' => ['active', 'inactive']], true],
    'outside enum' => ['other', ['type' => 'string', 'enum' => ['active', 'inactive']], false],
    'fitting length' => ['abc', ['type' => 'string', 'minLength' => 2, 'maxLength' => 3], true],
    'too short' => ['a', ['type' => 'string', 'minLength' => 2], false],
    'too long' => ['abcd', ['type' => 'string', 'maxLength' => 3], false],
    'Unicode length' => ['żółć', ['type' => 'string', 'maxLength' => 4], true],
]);

it('exposes default presentation for resolved destinations', function (
    DestinationType $type,
    string $destinationKey,
    string $label,
) {
    $factory = new DestinationFactory(app(ReplicaSchemaResolver::class));
    $factory->register(customers_destination());
    $destination = $factory->resolve(new Replica([
        'type' => $type,
        'destination' => $destinationKey,
        'code' => 'demo',
    ]));

    expect($destination->getLabel())->toBe($label);
    expect($destination->getIcon())->toBeNull();
    expect($destination->getColor())->toBeNull();
})->with([
    'static' => [DestinationType::Static, 'customers', 'Customers'],
    'dynamic' => [DestinationType::Dynamic, 'existing_table', 'existing_table'],
]);

it('preserves configured static destination presentation', function () {
    $label = new HtmlString('<strong>Customers</strong>');
    $color = ['500' => '#2563eb', '600' => '#1d4ed8'];
    $destination = new StaticDestination(
        key: 'customers',
        label: $label,
        table: 'customers',
        primaryKey: ['id'],
        schema: Schema::object(),
        icon: Heroicon::UserGroup,
        color: $color,
    );

    expect($destination->getLabel())->toBe($label);
    expect($destination->getIcon())->toBe(Heroicon::UserGroup);
    expect($destination->getColor())->toBe($color);
});

it('resolves label and schema callbacks in the current request locale', function () {
    $destination = new StaticDestination(
        key: 'localized',
        label: fn () => app()->getLocale(),
        table: 'localized',
        primaryKey: ['id'],
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
