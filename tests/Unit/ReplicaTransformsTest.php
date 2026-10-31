<?php

use Filament\Schemas\Schema as FilamentSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Forms\FieldTransforms;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Transformations\Slug;
use PHPinnacle\Ferry\Transformations\Trim;
use PHPinnacle\Jason\Schema;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/StructureItems.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

it('runs record transforms before field chains and renames regardless of mapping order', function () {
    app(TransformRegistry::class)->register(Trim::make());
    $object = StructureItems::make([
        'sources' => [
            'name' => ['type' => 'string'],
            'tail' => ['type' => 'integer'],
        ],
    ]);
    $replica = new Replica([
        'schema' => $object->schema,
        'transforms' => [
            new TransformSetting('insert_uuid', ['fields' => ["$['_idrref']"], 'output' => 'uuid']),
            new TransformSetting('slug', ['input' => "$['name']", 'output' => 'slug']),
            new TransformSetting('slug', ['input' => "$['slug']", 'output' => 'other_slug']),
        ],
        'mapping' => [
            new FieldMapping('name', 'title', ['transforms' => [['type' => 'trim']]]),
            new FieldMapping(null, 'name', ['transforms' => [['type' => 'trim']]], ' A constant ', FieldKind::Static),
            new FieldMapping('slug', 'slug'),
            new FieldMapping('other_slug', 'other_slug'),
            new FieldMapping('uuid', 'id'),
        ],
    ]);

    $config = app(TransformFactory::class)->pipeline($replica, $object, ['uuid' => 'id']);

    expect($config)->toMatchArray([
        'transforms' => 'replica_0,replica_0_key,replica_1,replica_2,field_0_0,field_1_0,field_1_1,rename_keys,rename_values',
        'transforms.replica_0.type' => 'org.phpinnacle.toblerone.InsertUuid$Value',
        'transforms.replica_0_key.type' => 'org.phpinnacle.toblerone.InsertUuid$Key',
        'transforms.replica_1.input' => 'name',
        'transforms.replica_1.output' => 'slug',
        'transforms.replica_2.input' => 'slug',
        'transforms.field_0_0.fields' => 'name',
        'transforms.field_1_0.static.field' => '__ferry_static_1',
        'transforms.field_1_1.fields' => '__ferry_static_1',
        'transforms.rename_values.renames' => 'name:title,slug:slug,other_slug:other_slug,__ferry_static_1:name',
    ]);
    expect(array_keys(app(ReplicaSchemaResolver::class)->transformedSchema($replica)->properties()))
        ->toBe(['uuid', 'name', 'slug', 'other_slug', 'tail']);
    expect(app(ReplicaSchemaResolver::class)->primaryKeySources($replica))->toBe(['uuid']);
});

it('keeps record transforms out of field editors and field transforms out of record chains', function () {
    $field = FieldTransforms::make('transforms')->inputSchema(Schema::fromArray([
        'type' => 'object',
        'properties' => [],
    ]));
    FilamentSchema::make(SchemaHost::make())->components([$field])->fill();
    expect($field->getAvailableBlocks())->not->toHaveKeys(['insert_uuid', 'slug']);
    expect(app(ReplicaSchemaResolver::class)->resultSchema(
        Schema::fromArray(['type' => 'object']),
        [new TransformSetting('filter')],
        TransformScope::Record,
    ))->toBeNull();
});

it('derives nullable slug schemas without carrying source length or format constraints', function () {
    $input = Schema::fromArray([
        'type' => 'object',
        'properties' => [
            'name' => ['type' => ['string', 'null'], 'format' => 'email', 'maxLength' => 20],
        ],
        'required' => ['name'],
    ]);

    $output = Slug::make()->output($input, ['input' => "$['name']", 'output' => 'slug']);

    expect($output->properties()['slug']->toArray())->toBe(['type' => ['string', 'null'], 'title' => 'Slug']);
    expect($output->required())->toBe(['name', 'slug']);
    expect(Slug::make()->output($input, ['input' => "$['missing']", 'output' => 'slug']))->toBeNull();
});

it('leaves mapped output unavailable while a record transform is incomplete', function () {
    $replica = new Replica([
        'schema' => Schema::object(['name' => Schema::string()]),
        'transforms' => [new TransformSetting('slug', ['input' => "$['name']"])],
    ]);

    expect(app(ReplicaSchemaResolver::class)->outputSchema($replica, new FieldMapping('name', 'name')))->toBeNull();
});

it('keeps internal constants separate from physical fields and preserves fixed value precedence', function () {
    $schema = Schema::fromArray([
        'type' => 'object',
        'properties' => [
            '__ferry_static_1' => ['type' => 'string'],
            'name' => ['type' => 'string'],
        ],
    ]);
    $replica = new Replica([
        'schema' => $schema,
        'mapping' => [
            new FieldMapping('name', 'profile_id'),
            new FieldMapping(null, 'name', [], true, FieldKind::Static),
        ],
    ]);

    $config = app(TransformFactory::class)->pipeline(
        $replica,
        StructureItems::make(['sources' => ['__ferry_static_1' => ['type' => 'string']]]),
        fixedValues: ['profile_id' => 'fixed'],
    );

    expect($config)->toMatchArray([
        'transforms' => 'field_1_0,field_1_0_cast,insert_profile_id,rename_values',
        'transforms.field_1_0.static.field' => '__ferry_static_1_',
        'transforms.field_1_0_cast.spec' => '__ferry_static_1_:boolean',
        'transforms.rename_values.include' => '__ferry_static_1_,__ferry_fixed_profile_id',
        'transforms.rename_values.renames' => '__ferry_static_1_:name,__ferry_fixed_profile_id:profile_id',
    ]);
});

it('composes UUID and slug transforms for both record values and selected keys', function () {
    $schema = Schema::fromArray(['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]);
    $settings = [
        new TransformSetting('insert_uuid', ['fields' => ["$['id']"], 'output' => 'uuid']),
        new TransformSetting('slug', ['input' => "$['uuid']", 'output' => 'slug']),
    ];

    $schemas = app(ReplicaSchemaResolver::class);
    expect($schemas->resultSchema($schema, $settings, TransformScope::Record)?->properties())->toHaveKey('slug');
    $replica = new Replica([
        'schema' => $schema,
        'transforms' => $settings,
        'mapping' => [new FieldMapping('slug', 'id')],
    ]);
    expect($schemas->keyPlan($replica, ['slug'])['sources'])->toBe(['id']);
    $pipeline = app(TransformFactory::class)->pipeline($replica, new StructureItem(['schema' => $schema]), [
        'slug' => 'id',
    ]);
    expect($pipeline)->toMatchArray([
        'transforms.replica_0.type' => 'org.phpinnacle.toblerone.InsertUuid$Value',
        'transforms.replica_0_key.type' => 'org.phpinnacle.toblerone.InsertUuid$Key',
        'transforms.replica_1.type' => 'org.phpinnacle.toblerone.SlugTransform$Value',
        'transforms.replica_1_key.type' => 'org.phpinnacle.toblerone.SlugTransform$Key',
    ]);
});

it('previews only the valid prefix of an incomplete record chain', function () {
    $schema = Schema::fromArray([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
    ]);
    $settings = [
        new TransformSetting('slug', ['input' => "$['name']", 'output' => 'slug']),
        new TransformSetting('slug', ['input' => "$['slug']", 'output' => '']),
        new TransformSetting('slug', ['input' => "$['name']", 'output' => 'later_slug']),
    ];

    $preview = app(ReplicaSchemaResolver::class)->previewRecordSchema($schema, $settings);

    expect(array_keys($preview->properties()))->toBe(['name', 'slug']);
    expect(app(ReplicaSchemaResolver::class)->resultSchema(
        $schema,
        $settings,
        TransformScope::Record,
    ))->toBeNull();
    expect(app(ReplicaSchemaResolver::class)->previewRecordSchema($schema, []))->toBe($schema);
});
