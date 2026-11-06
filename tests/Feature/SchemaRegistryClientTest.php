<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPinnacle\Ferry\Services\SchemaRegistryClient;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'phpinnacle-ferry.schema_registry.base_uri' => 'https://registry.example/api',
        'phpinnacle-ferry.schema_registry.headers' => ['Authorization' => 'Bearer registry-secret'],
    ]);
    Http::preventStrayRequests();
});

it('reads latest schemas with a server-side prefix and pagination using the configured authentication', function (bool $includeDeleted) {
    Http::fake(['registry.example/api/schemas*' => Http::response([
        ['subject' => 'tenant/orders-key', 'id' => 12, 'version' => 3, 'schema' => '"string"'],
        [
            'subject' => 'tenant/orders-value',
            'id' => 13,
            'version' => 4,
            'schemaType' => 'JSON',
            'schema' => '{}',
            'references' => [['name' => 'Address', 'subject' => 'addresses', 'version' => 2]],
            'deleted' => true,
        ],
    ])]);

    $schemas = app(SchemaRegistryClient::class)->schemas('tenant/orders', 50, 26, $includeDeleted);
    expect($schemas)
        ->toHaveCount(2)
        ->and($schemas[0]->subject)
        ->toBe('tenant/orders-key')
        ->and($schemas[0]->version)
        ->toBe(3)
        ->and($schemas[0]->type)
        ->toBe('AVRO')
        ->and($schemas[0]->references)
        ->toBe([])
        ->and($schemas[1]->type)
        ->toBe('JSON')
        ->and($schemas[0]->deleted)
        ->toBeNull()
        ->and($schemas[1]->deleted)
        ->toBeTrue()
        ->and($schemas[1]->references)
        ->toBe([['name' => 'Address', 'subject' => 'addresses', 'version' => 2]]);

    Http::assertSent(
        fn (Request $request) => (
            $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://registry.example/api/schemas?')
            && $request['latestOnly'] === 'true'
            && $request['subjectPrefix'] === 'tenant/orders'
            && $request['offset'] === 50
            && $request['limit'] === 26
            && ($request['deleted'] ?? null) === ($includeDeleted ? 'true' : null)
            && $request->hasHeader('Authorization', 'Bearer registry-secret')
            && $request->hasHeader('Accept', 'application/vnd.schemaregistry.v1+json')
        ),
    );
    Http::assertSentCount(1);
})->with([false, true]);

it('encodes the complete subject as one API path segment and reads version metadata', function (bool $includeDeleted) {
    $subject = 'tenant/orders?version=2#value';
    $path = 'registry.example/api/subjects/tenant%2Forders%3Fversion%3D2%23value/versions';
    $query = $includeDeleted ? '?deleted=true' : '';
    Http::fake([
        $path . $query => Http::response([1, 3]),
        $path . '/3' . $query => Http::response([
            'subject' => $subject,
            'id' => 12,
            'version' => 3,
            'schema' => '"string"',
            'references' => [['name' => 'Address', 'subject' => 'addresses', 'version' => 2]],
        ]),
    ]);
    $registry = app(SchemaRegistryClient::class);

    expect($registry->versions($subject, $includeDeleted))->toBe([1, 3]);
    $schema = $registry->schema($subject, 3, $includeDeleted);

    expect($schema->subject)
        ->toBe($subject)
        ->and($schema->type)
        ->toBe('AVRO')
        ->and($schema->version)
        ->toBe(3)
        ->and($schema->id)
        ->toBe(12)
        ->and($schema->schema)
        ->toBe('"string"')
        ->and($schema->references)
        ->toBe([['name' => 'Address', 'subject' => 'addresses', 'version' => 2]]);
    Http::assertSentCount(2);
})->with([false, true]);

it('asks the registry for effective subject compatibility including its global fallback', function () {
    Http::fake([
        'registry.example/api/config/orders-value?defaultToGlobal=true' => Http::response([
            'compatibilityLevel' => 'FULL_TRANSITIVE',
        ]),
        'registry.example/api/config' => Http::response(['compatibilityLevel' => 'BACKWARD']),
    ]);
    $registry = app(SchemaRegistryClient::class);

    expect($registry->compatibility('orders-value'))
        ->toBe('FULL_TRANSITIVE')
        ->and($registry->compatibility())
        ->toBe('BACKWARD');
    Http::assertSentCount(2);
});

it('requests Avro reference resolution through the registry API', function (bool $includeDeleted) {
    $avro = '{"type":"record","name":"Address","fields":[]}';
    Http::fake([
        'registry.example/api/subjects/orders-value/versions/2/schema?format=resolved'
            . ($includeDeleted ? '&deleted=true' : '') =>
            Http::response($avro),
    ]);

    expect(app(SchemaRegistryClient::class)->resolvedAvroSchema('orders-value', 2, $includeDeleted))->toBe($avro);
    Http::assertSentCount(1);
})->with([false, true]);

it('propagates registry failures instead of returning an empty schema list', function (int $status) {
    Http::fake(['registry.example/api/schemas*' => Http::response(['message' => 'Registry error'], $status)]);

    expect(fn () => app(SchemaRegistryClient::class)->schemas('', 0, 26))->toThrow(RequestException::class);
})->with([401, 403, 500]);

it('propagates a connection failure instead of returning an empty schema list', function () {
    Http::fake(['registry.example/api/schemas*' => Http::failedConnection()]);

    expect(fn () => app(SchemaRegistryClient::class)->schemas('', 0, 26))->toThrow(ConnectionException::class);
});

it('rejects a malformed response body', function () {
    Http::fake(['registry.example/api/schemas*' => Http::response('<html>Proxy error</html>')]);

    expect(fn () => app(SchemaRegistryClient::class)->schemas('', 0, 26))->toThrow(JsonException::class);
});

it('deletes a subject and an explicit version using the configured authentication', function (bool $permanent) {
    $subject = 'tenant/orders?region=eu#value';
    $path = 'https://registry.example/api/subjects/tenant%2Forders%3Fregion%3Deu%23value';
    $query = $permanent ? '?permanent=true' : '';
    Http::fake([
        $path . $query => Http::response([1, 3]),
        $path . '/versions/3' . $query => Http::response('3'),
        'registry.example/api/config' => Http::response(['compatibilityLevel' => 'BACKWARD']),
    ]);
    $registry = app(SchemaRegistryClient::class);

    $registry->deleteSubject($subject, $permanent);
    $registry->deleteVersion($subject, 3, $permanent);
    expect($registry->compatibility())->toBe('BACKWARD');

    foreach ([$path . $query, $path . '/versions/3' . $query] as $url) {
        Http::assertSent(
            fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === $url
            && $request->body() === ''
            && $request->hasHeader('Authorization', 'Bearer registry-secret'),
        );
    }

    Http::assertSentCount(3);
})->with([false, true]);

it('propagates deletion rejections without retrying or deleting dependencies', function (int $status) {
    Http::fake(['registry.example/api/subjects/orders-value*' => Http::response(['error_code' => 42_206], $status)]);
    $registry = app(SchemaRegistryClient::class);

    expect(fn () => $registry->deleteSubject('orders-value', true))->toThrow(RequestException::class);
    expect(fn () => $registry->deleteVersion('orders-value', 3, true))->toThrow(RequestException::class);
    Http::assertSentCount(2);
})->with([403, 404, 422, 500]);

it('restores the original schema payload and looks up the active version assigned by the registry', function (
    string $type,
    string $schema,
) {
    $subject = 'tenant/orders?region=eu#value';
    $path = 'https://registry.example/api/subjects/tenant%2Forders%3Fregion%3Deu%23value';
    $references = [['name' => 'shared.Address', 'subject' => 'addresses', 'version' => 2]];
    $payload = [
        'schemaType' => $type,
        'schema' => $schema,
        'references' => $references,
        'metadata' => ['properties' => ['owner' => 'shipping']],
        'ruleSet' => (object) [],
    ];
    Http::fake([
        $path . '/versions/1?deleted=true' => Http::response(json_encode([
            ...$payload,
            'subject' => $subject,
            'id' => 12,
            'version' => 1,
            'deleted' => true,
            'guid' => 'registry-guid',
            'ts' => 100,
        ], JSON_THROW_ON_ERROR)),
        $path . '/versions' => Http::response(['id' => 12]),
        $path => Http::response([
            ...$payload,
            'subject' => $subject,
            'id' => 12,
            'version' => 4,
            'deleted' => false,
        ]),
    ]);

    $restored = app(SchemaRegistryClient::class)->restoreVersion($subject, 1);

    expect($restored->subject)
        ->toBe($subject)
        ->and($restored->version)
        ->toBe(4)
        ->and($restored->id)
        ->toBe(12)
        ->and($restored->deleted)
        ->toBeFalse()
        ->and($restored->schema)
        ->toBe($schema)
        ->and($restored->references)
        ->toBe($references);

    foreach ([$path . '/versions', $path] as $url) {
        Http::assertSent(
            fn (Request $request) => (
                $request->method() === 'POST'
                && $request->url() === $url
                && $request->hasHeader('Authorization', 'Bearer registry-secret')
                && json_encode(
                    json_decode($request->body(), flags: JSON_THROW_ON_ERROR),
                    JSON_THROW_ON_ERROR,
                ) === json_encode($payload, JSON_THROW_ON_ERROR)
            ),
        );
    }

    Http::assertSentCount(3);
})->with([
    ['AVRO',     '{"type":"record","name":"Order","fields":[]}'],
    ['JSON',     '{"type":"object"}'],
    ['PROTOBUF', 'syntax = "proto3"; message Order {}'],
]);

it('propagates restoration rejections without changing registry settings or restoring dependencies', function (int $status) {
    Http::fake([
        'registry.example/api/subjects/orders-value/versions/1?deleted=true' => Http::response([
            'subject' => 'orders-value',
            'id' => 12,
            'version' => 1,
            'schema' => '"string"',
            'deleted' => true,
        ]),
        'registry.example/api/subjects/orders-value/versions' => Http::response([
            'message' => 'Registration rejected',
        ], $status),
    ]);

    expect(fn () => app(SchemaRegistryClient::class)->restoreVersion('orders-value', 1))
        ->toThrow(RequestException::class);
    Http::assertSentCount(2);
})->with([403, 409, 422, 500]);

it('does not register a schema if the deleted version cannot be retrieved', function () {
    Http::fake(['registry.example/api/subjects/orders-value/versions/1?deleted=true' => Http::response([], 404)]);

    expect(fn () => app(SchemaRegistryClient::class)->restoreVersion('orders-value', 1))
        ->toThrow(RequestException::class);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});

it('reports an invalid active lookup response after registration without retrying registration', function () {
    Http::fake([
        'registry.example/api/subjects/orders-value/versions/1?deleted=true' => Http::response([
            'subject' => 'orders-value',
            'id' => 12,
            'version' => 1,
            'schema' => '"string"',
            'deleted' => true,
        ]),
        'registry.example/api/subjects/orders-value/versions' => Http::response(['id' => 12]),
        'registry.example/api/subjects/orders-value' => Http::response('null'),
    ]);

    expect(fn () => app(SchemaRegistryClient::class)->restoreVersion('orders-value', 1))
        ->toThrow(UnexpectedValueException::class);
    Http::assertSentCount(3);
});
