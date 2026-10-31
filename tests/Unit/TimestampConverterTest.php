<?php

use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Transformations\TimestampConverter;
use PHPinnacle\Jason\Schema;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/SchemaHost.php';
require_once __DIR__ . '/../Fixtures/StructureItems.php';

uses(TestCase::class);

it('offers timestamp conversion for supported field types', function (array $input, bool $accepted) {
    expect(TimestampConverter::make()->accepts(Schema::fromArray($input)))->toBe($accepted);
})->with([
    'text' => [['type' => 'string'], true],
    'epoch' => [['type' => 'integer'], true],
    'nullable timestamp' => [['type' => ['string', 'null'], 'format' => 'date-time'], true],
    'date' => [['type' => 'string', 'format' => 'date'], true],
    'time' => [['type' => 'string', 'format' => 'time'], true],
    'reference' => [['type' => 'string', 'x-ferry-reference' => true], false],
    'number' => [['type' => 'number'], false],
    'boolean' => [['type' => 'boolean'], false],
    'object' => [['type' => 'object'], false],
    'array' => [['type' => 'array'], false],
]);

it('projects timestamp output types without retaining input constraints', function (string $target, array $output) {
    $transform = app(TransformRegistry::class)->get('timestamp_converter');
    foreach ([false, true] as $nullable) {
        $input = Schema::fromArray([
            'type' => $nullable ? ['string', 'null'] : 'string',
            'format' => 'date-time',
            'minLength' => 20,
            'maxLength' => 30,
            'enum' => ['2026-10-27T12:00:00Z'],
        ]);
        $expected = $output;
        if ($nullable) {
            $expected['type'] = [$expected['type'], 'null'];
        }

        expect($transform->output($input, ['target_type' => $target])?->toArray())->toBe($expected);
    }

    expect($transform->output(Schema::fromArray(['type' => 'integer']), []))->toBeNull();
})->with([
    'text' => ['string', ['type' => 'string']],
    'epoch' => ['unix', ['type' => 'integer']],
    'date' => ['Date', ['type' => 'string', 'format' => 'date']],
    'time' => ['Time', ['type' => 'string', 'format' => 'time']],
    'timestamp' => ['Timestamp', ['type' => 'string', 'format' => 'date-time']],
]);

it('validates and dehydrates native timestamp settings', function (array $input, array $params, ?array $expected) {
    $schema = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(
            SchemaHost::transformSchema(TimestampConverter::make(), Schema::fromArray($input)),
        );
    $schema->fill($params);

    if ($expected === null) {
        expect($schema->getState(...))->toThrow(ValidationException::class);

        return;
    }

    expect($schema->getState())->toBe($expected);
})->with([
    'epoch to timestamp' => [
        ['type' => 'integer'],
        ['target_type' => 'Timestamp', 'unix_precision' => 'microseconds'],
        ['target_type' => 'Timestamp', 'unix_precision' => 'microseconds'],
    ],
    'logical timestamp to epoch' => [
        ['type' => 'string', 'format' => 'date-time'],
        ['target_type' => 'unix'],
        ['target_type' => 'unix', 'unix_precision' => 'milliseconds'],
    ],
    'text parsing' => [
        ['type' => 'string'],
        ['target_type' => 'Date', 'format' => 'yyyy-MM-dd', 'replace_null_with_default' => 'false'],
        [
            'target_type' => 'Date',
            'format' => 'yyyy-MM-dd',
            'unix_precision' => 'milliseconds',
            'replace_null_with_default' => 'false',
        ],
    ],
    'formatted output' => [
        ['type' => 'integer'],
        ['target_type' => 'string', 'format' => "yyyy-MM-dd'T'HH:mm:ss.SSSXXX"],
        ['target_type' => 'string', 'format' => "yyyy-MM-dd'T'HH:mm:ss.SSSXXX", 'unix_precision' => 'milliseconds'],
    ],
    'missing output type' => [['type' => 'integer'], [], null],
    'invalid output type' => [['type' => 'integer'], ['target_type' => 'float64'], null],
    'missing text input format' => [['type' => 'string'], ['target_type' => 'Timestamp'], null],
    'missing text output format' => [['type' => 'integer'], ['target_type' => 'string'], null],
    'invalid precision' => [['type' => 'integer'], ['target_type' => 'Timestamp', 'unix_precision' => 'minutes'], null],
    'non-string format' => [['type' => 'integer'], ['target_type' => 'string', 'format' => []], null],
]);

it('refreshes format requirements and keeps repeated timestamp forms independent', function () {
    $transform = TimestampConverter::make();
    $input = Schema::fromArray(['type' => 'integer']);
    $first = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema($transform, $input));
    $first->fill(['target_type' => 'Timestamp']);
    $second = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema($transform, $input));
    $second->fill(['target_type' => 'string', 'format' => 'yyyy-MM-dd']);

    expect($first->getState())->toBe(['target_type' => 'Timestamp', 'unix_precision' => 'milliseconds']);
    expect($second->getState())->toBe([
        'target_type' => 'string',
        'format' => 'yyyy-MM-dd',
        'unix_precision' => 'milliseconds',
    ]);
    $first->getComponent('target_type')->state('string')->callAfterStateUpdated();
    expect($first->getComponent('format')->isRequired())->toBeTrue();
    expect($first->getState(...))->toThrow(ValidationException::class);
    $first->getComponent('format')->state('HH:mm:ss');
    expect($first->getState()['format'])->toBe('HH:mm:ss');
    expect($second->getState()['format'])->toBe('yyyy-MM-dd');
});

it('runs timestamp chains before renaming while preserving tombstone deletes', function () {
    $object = StructureItems::make(['fields' => ['created' => Schema::fromArray(['type' => 'integer'])]]);
    $settings = [
        ['type' => 'timestamp_converter', 'data' => ['target_type' => 'Timestamp', 'unix_precision' => 'microseconds']],
        [
            'type' => 'timestamp_converter',
            'data' => ['target_type' => 'string', 'format' => 'yyyy-MM-dd', 'replace_null_with_default' => 'false'],
        ],
    ];
    $mapping = new FieldMapping('created', 'created_date', ['transforms' => $settings]);
    $replica = new Replica(['schema' => $object->schema, 'mapping' => [$mapping]]);
    $config = app(TransformFactory::class)->pipeline($replica, $object);

    expect(app(ReplicaSchemaResolver::class)->outputSchema($replica, $mapping)?->toArray())->toBe(['type' => 'string']);
    expect($config)
        ->toMatchArray([
            'transforms' => 'field_0_0,field_0_1,rename_values',
            'transforms.field_0_0.type' => 'org.apache.kafka.connect.transforms.TimestampConverter$Value',
            'transforms.field_0_0.field' => 'created',
            'transforms.field_0_0.target.type' => 'Timestamp',
            'transforms.field_0_0.unix.precision' => 'microseconds',
            'transforms.field_0_0.predicate' => 'tombstone',
            'transforms.field_0_0.negate' => 'true',
            'transforms.field_0_1.format' => 'yyyy-MM-dd',
            'transforms.field_0_1.replace.null.with.default' => 'false',
            'transforms.field_0_1.predicate' => 'tombstone',
            'transforms.rename_values.renames' => 'created:created_date',
            'predicates.tombstone.type' => 'org.apache.kafka.connect.transforms.predicates.RecordIsTombstone',
        ])
        ->not->toHaveKey('transforms.field_0_0.format', 'transforms.field_0_0.replace.null.with.default');
});
