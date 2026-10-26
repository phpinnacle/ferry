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
use PHPinnacle\Ferry\Tests\Fakes\Transform as FakeTransform;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;

require_once __DIR__ . '/../../../Fakes/Transform.php';

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
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: '_fld1', target: 'size', options: [
                'transforms' => [['type' => 'cast', 'data' => ['type' => 'int64']]],
            ]),
            new FieldMapping(source: null, target: 'active', static: true, kind: FieldKind::Static),
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
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: '_marked', target: null, options: [
                'transforms' => [[
                    'type' => 'filter',
                    'data' => [
                        'mode' => 'exclude',
                        'condition' => '@ == true',
                        'missing_or_null_behavior' => 'include',
                    ],
                ]],
            ]),
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
    app(TransformRegistry::class)->register(new FakeTransform('missing'));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['sources' => ['name' => ['type' => 'string']]]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'mapping' => [
            TestCase::keyMapping(),
            new PHPinnacle\Ferry\Data\FieldMapping(source: 'name', target: 'name', options: [
                'transforms' => [['type' => 'missing', 'data' => []]],
            ]),
        ],
    ]);

    app()->instance(TransformRegistry::class, new TransformRegistry);
    expect(new SourceFactory()->source(
        $upstream,
        config('phpinnacle-ferry.origins.kafka'),
    ))
        ->toMatchArray(['column.include.list' => 'public._reference0._idrref,public._reference0.name']);
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
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: '_description', target: 'contact', options: [
                'transforms' => [
                    ['type' => 'trim', 'data' => []],
                    ['type' => 'substring', 'data' => ['start' => '0', 'end' => '5']],
                    ['type' => 'substring', 'data' => ['start' => '1', 'end' => '3']],
                    ['type' => 'mask', 'data' => ['replacement' => 'hidden']],
                ],
            ]),
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
        ->and($replica->fresh()->mapping[1]->transforms())
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
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: '_fld16rref', target: 'supplier'),
            new FieldMapping(source: '_fld17_rrref', target: 'owner'),
            new FieldMapping(source: '_fld17_type', target: null, options: [
                'transforms' => [[
                    'type' => 'filter',
                    'data' => [
                        'condition' => "@ == '08'",
                        'mode' => 'include',
                        'missing_or_null_behavior' => 'include',
                    ],
                ]],
            ]),
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
        ->and($replica->fresh()->mapping)
        ->toEqual($replica->mapping);
});
