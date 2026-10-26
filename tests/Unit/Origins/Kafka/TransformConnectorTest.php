<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Origins\Kafka\SinkConfigFactory;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;

require_once __DIR__ . '/../../../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();

    config(['database.connections.sqlite.host' => 'db.internal', 'database.connections.sqlite.port' => 5432]);
});

it('orders source transforms and static values around the renames of a sink', function () {
    $factory = $this->app->make(TransformFactory::class);

    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'fields' => [
            '_folder' => JsonSchema::fromArray(['type' => 'boolean']),
        ],
        'sources' => [
            '_fld1' => [
                'type' => 'string',
                'maxLength' => 100,
                'title' => 'Title',
            ],
        ],
    ]);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'schema' => [
            TestCase::keyMapping(),
            new FieldMapping(
                '_fld1',
                'size',
                $object->field('_fld1'),
                result: JsonSchema::fromArray(['type' => 'integer']),
                transforms: [new TransformSetting('cast', ['type' => 'integer'])],
            ),
            new FieldMapping(null, 'active', null, static: true, kind: FieldKind::Static),
        ],
    ]);

    $builder = new SourceFactory;
    $config = new SinkConfigFactory($factory)->make(
        new DestinationFactory()->resolve($replica),
        $replica,
        $object,
        $builder->topic($upstream, $object),
    );

    expect($config['transforms'])
        ->toBe('field_1_0,rename_keys,rename_values,field_2_0,field_2_0_cast')
        ->and($config['transforms.field_1_0.spec'])
        ->toBe('_fld1:int64')
        ->and($config['transforms.rename_values.renames'])
        ->toBe('_idrref:external_id,_fld1:size')
        ->and($config['transforms.field_2_0.static.field'])
        ->toBe('active')
        ->and($config['transforms.field_2_0_cast.spec'])
        ->toBe('active:boolean')
        ->and($config['fields.whitelist'])
        ->toBe('external_id,size,active')
        ->and($replica->hasValidSchema($object))
        ->toBeTrue()
        ->and(array_keys($replica->columnMap($object)))
        ->toBe(['_idrref', '_fld1'])
        ->and($config)
        ->not->toHaveKey('predicates');
});

it('captures an unbound field filter without writing it or capturing unused fields', function () {
    $factory = $this->app->make(TransformFactory::class);
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'fields' => [
            '_marked' => JsonSchema::fromArray(['type' => 'boolean']),
            '_unused' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 10]),
        ],
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'schema' => [
            TestCase::keyMapping(),
            new FieldMapping(
                '_marked',
                null,
                $object->field('_marked'),
                result: $object->field('_marked'),
                transforms: [new TransformSetting('filter', [
                    'mode' => 'exclude',
                    'condition' => '@ == true',
                    'missing_or_null_behavior' => 'include',
                ])],
            ),
        ],
    ]);

    $builder = new SourceFactory;
    $source = $builder->source(
        $upstream,
        config('phpinnacle-ferry.origins.kafka'),
    );
    $config = new SinkConfigFactory($factory)->make(
        new DestinationFactory()->resolve($replica),
        $replica,
        $object,
        $builder->topic($upstream, $object),
    );

    expect($source['column.include.list'])
        ->toBe('public._reference0._idrref,public._reference0._marked')
        ->and($config['transforms'])
        ->toBe('field_1_0,rename_keys,rename_values')
        ->and($config['transforms.field_1_0.filter.condition'])
        ->toBe("$[?(@['_marked'] == true)]")
        ->and($config['transforms.field_1_0.predicate'])
        ->toBe('tombstone')
        ->and($config['transforms.field_1_0.negate'])
        ->toBe('true')
        ->and($config['predicates'])
        ->toBe('tombstone')
        ->and($config['predicates.tombstone.type'])
        ->toBe('org.apache.kafka.connect.transforms.predicates.RecordIsTombstone')
        ->and($config['fields.whitelist'])
        ->toBe('external_id');
});

it('keeps building the shared source when a replica refers to a transform that is no longer registered', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'schema' => [
            new \PHPinnacle\Ferry\Data\FieldMapping(
                '_idrref',
                'external_id',
                JsonSchema::fromArray([
                    'type' => 'string',
                    'format' => 'uuid',
                    'x-ferry-reference' => true,
                    'minLength' => 36,
                    'maxLength' => 36,
                ]),
                result: JsonSchema::fromArray([
                    'type' => 'string',
                    'format' => 'uuid',
                    'x-ferry-reference' => true,
                    'minLength' => 36,
                    'maxLength' => 36,
                ]),
                transforms: [new TransformSetting('missing')],
            ),
        ],
    ]);

    expect(new SourceFactory()->source(
        $upstream,
        config('phpinnacle-ferry.origins.kafka'),
    ))
        ->toMatchArray(['column.include.list' => 'public._reference0._idrref']);
});

it('builds installed string transforms before renames and guards masking against tombstones', function () {
    $factory = app(TransformFactory::class);
    app(TransformRegistry::class)->register(Trim::make(), Substring::make(), MaskField::make());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'fields' => [
            '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        ],
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'schema' => [
            TestCase::keyMapping(),
            new FieldMapping(
                '_description',
                'contact',
                $object->field('_description'),
                result: JsonSchema::fromArray(['type' => 'string']),
                transforms: [
                    new TransformSetting('trim'),
                    new TransformSetting('substring', ['start' => '0', 'end' => '5']),
                    new TransformSetting('substring', ['start' => '1', 'end' => '3']),
                    new TransformSetting('mask', ['replacement' => 'hidden']),
                ],
            ),
        ],
    ]);
    $config = new SinkConfigFactory($factory)->make(
        new DestinationFactory()->resolve($replica),
        $replica,
        $object,
        'test-topic',
    );

    expect($config['transforms'])
        ->toBe('field_1_0,field_1_1,field_1_2,field_1_3,rename_keys,rename_values')
        ->and($config['transforms.field_1_0.type'])
        ->toBe('org.phpinnacle.toblerone.TrimTransform$Value')
        ->and($config['transforms.field_1_0.fields'])
        ->toBe('_description')
        ->and($config['transforms.field_1_1.type'])
        ->toBe('org.phpinnacle.toblerone.SubstringTransform$Value')
        ->and($config['transforms.field_1_1.fields'])
        ->toBe('_description:0:5')
        ->and($replica->fresh()->schema[1]->transforms)
        ->toEqual([
            new TransformSetting('trim'),
            new TransformSetting('substring', ['start' => '0', 'end' => '5']),
            new TransformSetting('substring', ['start' => '1', 'end' => '3']),
            new TransformSetting('mask', ['replacement' => 'hidden']),
        ])
        ->and($config['transforms.field_1_2.fields'])
        ->toBe('_description:1:3')
        ->and($config['transforms.field_1_3.fields'])
        ->toBe('_description')
        ->and($config['transforms.field_1_3.replacement'])
        ->toBe('hidden')
        ->and($config['transforms.field_1_3.predicate'])
        ->toBe('tombstone')
        ->and($config['transforms.field_1_3.negate'])
        ->toBe('true')
        ->and($config['predicates.tombstone.type'])
        ->toBe('org.apache.kafka.connect.transforms.predicates.RecordIsTombstone')
        ->and($config['transforms.rename_values.renames'])
        ->toBe('_idrref:external_id,_description:contact');
});

it('scopes and transforms independently selected reference components using their real columns', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'sources' => [
            '_fld16rref' => [
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
                'title' => 'Supplier',
            ],
            '_fld17_type' => [
                'type' => 'string',
                'minLength' => 2,
                'maxLength' => 2,
                'title' => 'Owner',
            ],
            '_fld17_rtref' => [
                'type' => 'string',
                'minLength' => 8,
                'maxLength' => 8,
                'title' => 'Owner',
            ],
            '_fld17_rrref' => [
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
                'title' => 'Owner',
            ],
        ],
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'schema' => [
            TestCase::keyMapping(),
            new FieldMapping('_fld16rref', 'supplier', $object->field('_fld16rref')),
            new FieldMapping('_fld17_rrref', 'owner', $object->field('_fld17_rrref')),
            new FieldMapping(
                '_fld17_type',
                null,
                $object->field('_fld17_type'),
                result: $object->field('_fld17_type'),
                transforms: [new TransformSetting('filter', [
                    'condition' => "@ == '08'",
                    'mode' => 'include',
                    'missing_or_null_behavior' => 'include',
                ])],
            ),
        ],
    ]);
    $source = new SourceFactory;
    $config = $source->source(
        $upstream,
        config('phpinnacle-ferry.origins.kafka'),
    );
    $sink = new SinkConfigFactory(app(TransformFactory::class))->make(
        new DestinationFactory()->resolve($replica),
        $replica,
        $object,
        $source->topic($upstream, $object),
    );

    expect(explode(',', $config['column.include.list']))
        ->toBe([
            'public._reference0._idrref',
            'public._reference0._fld16rref',
            'public._reference0._fld17_rrref',
            'public._reference0._fld17_type',
        ])
        ->and($sink['transforms.rename_values.renames'])
        ->toBe('_idrref:external_id,_fld16rref:supplier,_fld17_rrref:owner')
        ->and($sink['transforms.field_3_0.filter.condition'])
        ->toBe("$[?(@['_fld17_type'] == '08')]")
        ->and($sink['fields.whitelist'])
        ->toBe('external_id,supplier,owner')
        ->and($replica->fresh()->schema)
        ->toEqual($replica->schema)
        ->and($replica->hasValidSchema($object))
        ->toBeTrue();

    $renamed = StructureItems::make([
        'fields' => array_diff_key(
            $object->schema->properties(),
            array_flip([
                '_fld16rref',
                '_fld17_type',
                '_fld17_rtref',
                '_fld17_rrref',
            ]),
        ),
        'sources' => [
            '_fld26rref' => [
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
                'title' => 'Supplier',
            ],
            '_fld27_type' => [
                'type' => 'string',
                'minLength' => 2,
                'maxLength' => 2,
                'title' => 'Owner',
            ],
            '_fld27_rtref' => [
                'type' => 'string',
                'minLength' => 8,
                'maxLength' => 8,
                'title' => 'Owner',
            ],
            '_fld27_rrref' => [
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
                'title' => 'Owner',
            ],
        ],
    ]);
    expect($replica->hasValidSchema($renamed))
        ->toBeFalse()
        ->and($replica->brokenColumns($renamed))
        ->toContain('supplier', 'owner')
        ->and($replica->columnMap($renamed))
        ->toBe(['_idrref' => 'external_id']);
});
