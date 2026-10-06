<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\StaticDestinationRegistry;
use PHPinnacle\Ferry\Services\SyncReviewer;
use PHPinnacle\Ferry\Tests\Fakes\FakeCustomersDestination;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/FakeCustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

function ferry_reviewer_object(string $connectionId, int $generation): ConnectionMetadata
{
    return ConnectionMetadata::create([
        'connection_id' => $connectionId,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
        'system' => ['_idrref' => new ScalarField(FieldType::Id)],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $generation,
    ]);
}

it('does not resume a synchronization paused manually even when its mapping is valid', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = ferry_reviewer_object($connection->id, $connection->generation);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'schema' => [new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id))],
    ], [
        'status' => SyncStatus::Pause,
        'is_paused' => true,
    ]);
    TestCase::makeConnector($sync, ['name' => 'ferry-sink-' . $sync->code])
        ->recordStatus(ConnectorStatus::Paused);

    expect(app(SyncReviewer::class)->shouldResume($sync, $object))->toBeFalse();
});

it('resumes a synchronization automatically paused for a broken mapping once it becomes valid again', function (?ConnectorStatus $status) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = ferry_reviewer_object($connection->id, $connection->generation);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'schema' => [new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id))],
    ], [
        'status' => SyncStatus::Pause,
        'is_paused' => false,
    ]);
    $connector = TestCase::makeConnector($sync, [
        'name' => 'ferry-sink-' . $sync->code,
        'config' => ['connector.class' => 'io.confluent.connect.jdbc.JdbcSinkConnector'],
    ]);

    if ($status !== null) {
        $connector->recordStatus($status);
    }

    expect(app(SyncReviewer::class)->shouldResume($sync, $object))->toBeTrue();
})->with(['checked' => [ConnectorStatus::Paused], 'configured' => [null]]);

it('reports static mappings that no longer match the destination declaration', function () {
    app(StaticDestinationRegistry::class)->register(new FakeCustomersDestination);

    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = ferry_reviewer_object($connection->id, $connection->generation);
    $object->forceFill([
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_description' => new StringField(length: 100, fixed: false),
            '_marked' => new ScalarField(FieldType::Boolean),
        ],
    ])->save();

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'static_destination' => 'customers',
        'source' => 'object-0',
        'schema' => [
            new FieldMapping('_description', 'name', new StringField(length: 100, fixed: false)),
            new FieldMapping('_marked', 'is_retired', new ScalarField(FieldType::Boolean)),
        ],
    ]);

    expect(app(SyncReviewer::class)->brokenColumns($sync, $object->fresh()))
        ->toBe(['is_retired', 'tax_number']);
});

function ferry_reviewer_orphaned_sync(): array
{
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = ferry_reviewer_object($connection->id, $connection->generation);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'schema' => [new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id))],
    ]);
    $sync->forceFill(['static_destination' => 'gone'])->saveQuietly();

    return [$connection, $object, $sync->fresh()];
}

it('reports every mapping as broken when the static destination is no longer registered', function () {
    [, $object, $sync] = ferry_reviewer_orphaned_sync();

    $reviewer = app(SyncReviewer::class);

    expect($reviewer->brokenColumns($sync, $object))
        ->toBe(['external_id'])
        ->and($reviewer->isValid($sync, $object))
        ->toBeFalse();
});

it('pauses a synchronization automatically instead of failing the review when its static destination is gone', function () {
    [$connection, , $sync] = ferry_reviewer_orphaned_sync();

    app(SyncReviewer::class)->review($connection->fresh());

    expect($sync->fresh()->status)
        ->toBe(SyncStatus::Pause)
        ->and($sync->fresh()->is_paused)
        ->toBeFalse();
});

it('does not resume a synchronization while its static destination is still gone', function () {
    [, $object, $sync] = ferry_reviewer_orphaned_sync();

    $sync->forceFill([
        'status' => SyncStatus::Pause,
        'is_paused' => false,
    ])->saveQuietly();
    TestCase::makeConnector($sync, ['name' => 'ferry-sink-' . $sync->code])
        ->recordStatus(ConnectorStatus::Paused);

    expect(app(SyncReviewer::class)->shouldResume($sync->fresh(), $object))->toBeFalse();
});

it('does not resume a synchronization that was never activated', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = ferry_reviewer_object($connection->id, $connection->generation);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'schema' => [new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id))],
    ], ['status' => SyncStatus::Pause, 'is_paused' => false]);

    expect(app(SyncReviewer::class)->shouldResume($sync, $object))->toBeFalse();
});
