<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\SourceFactory;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

use function PHPinnacle\Ferry\Tests\Fakes\exclude_folders_transform;

require_once __DIR__ . '/../../TestCase.php';
require_once __DIR__ . '/../../Fakes/ExcludeFoldersTransform.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();

    config(['database.connections.sqlite.host' => 'db.internal', 'database.connections.sqlite.port' => 5432]);
});

it('orders record, field and constant transforms around the renames of a sink', function () {
    $registry = $this->app->make(TransformFactory::class);
    $registry->register(exclude_folders_transform());

    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_folder' => new ScalarField(FieldType::Boolean),
        ],
        'properties' => [
            new MetadataProperty(
                id: 'title',
                name: '_fld1',
                code: 2,
                kind: PropertyKind::Field,
                label: 'Title',
                title: 'Title',
                field: new StringField(length: 100, fixed: false),
            ),
        ],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'schema' => SyncSchema::fromBindings(
            $object,
            ['title' => 'size'],
            $registry,
            transforms: [
                'fields' => ['title' => [new TransformSetting('cast', ['type' => 'integer'])]],
                'constants' => ['active' => ['type' => 'boolean', 'value' => 'true']],
            ],
        ),
        'transforms' => SyncSchema::fromTransforms([new TransformSetting('exclude_folders')], $object, $registry),
    ]);

    $builder = new SourceFactory($registry);
    $config = new DestinationFactory()
        ->resolve($sync)
        ->connector($sync, $object, $builder->topic($connection, $object), $registry);

    expect($sync->fresh()->transforms)
        ->toEqual([new TransformSetting('exclude_folders')])
        ->and($config['transforms'])
        ->toBe('unwrap,record_0,field_0_0,rename_keys,rename_values,field_1_0,field_1_0_cast')
        ->and($config['transforms.record_0.type'])
        ->toBe('io.confluent.connect.transforms.Filter$Value')
        ->and($config['transforms.field_0_0.spec'])
        ->toBe('_fld1:int64')
        ->and($config['transforms.rename_values.renames'])
        ->toBe('_idrref:idrref,_fld1:size')
        ->and($config['transforms.field_1_0.static.field'])
        ->toBe('active')
        ->and($config['transforms.field_1_0_cast.spec'])
        ->toBe('active:boolean')
        ->and($config['fields.whitelist'])
        ->toBe('idrref,size,active')
        ->and($sync->hasValidSchema($object))
        ->toBeTrue()
        ->and(array_keys($sync->columnMap($object)))
        ->toBe(['_idrref', '_fld1'])
        ->and($config)
        ->not->toHaveKey('predicates');
});

it('captures the column of a record filter in the source and keeps tombstones out of it', function () {
    $registry = $this->app->make(TransformFactory::class);
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_marked' => new ScalarField(FieldType::Boolean),
        ],
    ]);
    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'transforms' => SyncSchema::fromTransforms(
            [
                new TransformSetting('filter', [
                    'mode' => 'exclude',
                    'column' => '_marked',
                    'type' => 'boolean',
                    'value' => 'true',
                ]),
            ],
            $object,
            $registry,
        ),
    ]);

    $builder = new SourceFactory($registry);
    $source = $builder->source($connection, new Collection([$sync]));
    $config = new DestinationFactory()
        ->resolve($sync)
        ->connector($sync, $object, $builder->topic($connection, $object), $registry);

    expect($source['column.include.list'])
        ->toBe('public._reference0._idrref,public._reference0._marked')
        ->and($config['transforms'])
        ->toBe('unwrap,record_0,rename_keys,rename_values')
        ->and($config['transforms.record_0.filter.condition'])
        ->toBe('$[?(@._marked == true)]')
        ->and($config['transforms.record_0.predicate'])
        ->toBe('tombstone')
        ->and($config['transforms.record_0.negate'])
        ->toBe('true')
        ->and($config['predicates'])
        ->toBe('tombstone')
        ->and($config['predicates.tombstone.type'])
        ->toBe('org.apache.kafka.connect.transforms.predicates.RecordIsTombstone')
        ->and($config['fields.whitelist'])
        ->toBe('idrref');
});

it('keeps building the shared source when a synchronization refers to a transform that is no longer registered', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection);
    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'transforms' => [new TransformSetting('missing')],
    ]);

    expect(new SourceFactory($this->app->make(TransformFactory::class))->source($connection, new Collection([$sync])))
        ->toMatchArray(['column.include.list' => 'public._reference0._idrref']);
});

it('builds installed string transforms before renames and guards masking against tombstones', function () {
    $registry = app(TransformFactory::class);
    $registry->register(Trim::make(), Substring::make(), MaskField::make());
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_description' => new StringField(length: 100, fixed: false),
        ],
    ]);
    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'schema' => SyncSchema::fromBindings($object, ['_description' => 'contact'], $registry, transforms: [
            'fields' => ['_description' => [
                new TransformSetting('trim'),
                new TransformSetting('substring', ['start' => '0', 'end' => '5']),
                new TransformSetting('substring', ['start' => '1', 'end' => '3']),
                new TransformSetting('mask', ['replacement' => 'hidden']),
            ]],
        ]),
    ]);
    $config = new DestinationFactory()
        ->resolve($sync)
        ->connector($sync, $object, 'test-topic', $registry);

    expect($config['transforms'])
        ->toBe('unwrap,field_0_0,field_0_1,field_0_2,field_0_3,rename_keys,rename_values')
        ->and($config['transforms.field_0_0.type'])
        ->toBe('org.phpinnacle.toblerone.TrimTransform$Value')
        ->and($config['transforms.field_0_0.fields'])
        ->toBe('_description')
        ->and($config['transforms.field_0_1.type'])
        ->toBe('org.phpinnacle.toblerone.SubstringTransform$Value')
        ->and($config['transforms.field_0_1.fields'])
        ->toBe('_description:0:5')
        ->and($sync->fresh()->schema[0]->transforms)
        ->toEqual([
            new TransformSetting('trim'),
            new TransformSetting('substring', ['start' => '0', 'end' => '5']),
            new TransformSetting('substring', ['start' => '1', 'end' => '3']),
            new TransformSetting('mask', ['replacement' => 'hidden']),
        ])
        ->and($config['transforms.field_0_2.fields'])
        ->toBe('_description:1:3')
        ->and($config['transforms.field_0_3.fields'])
        ->toBe('_description')
        ->and($config['transforms.field_0_3.replacement'])
        ->toBe('hidden')
        ->and($config['transforms.field_0_3.predicate'])
        ->toBe('tombstone')
        ->and($config['transforms.field_0_3.negate'])
        ->toBe('true')
        ->and($config['predicates.tombstone.type'])
        ->toBe('org.apache.kafka.connect.transforms.predicates.RecordIsTombstone')
        ->and($config['transforms.rename_values.renames'])
        ->toBe('_idrref:idrref,_description:contact');
});
