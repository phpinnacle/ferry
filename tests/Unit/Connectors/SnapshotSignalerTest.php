<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Contracts\SignalProducer;
use PHPinnacle\Ferry\Services\Connectors\SnapshotSignaler;
use PHPinnacle\Ferry\Services\Connectors\SourceConnectorConfigBuilder;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

function ferry_fake_signal_producer(): FakeSignalProducer
{
    return new FakeSignalProducer;
}

it('does not publish anything when no table needs a snapshot', function () {
    $connection = TestCase::makeConnection();
    $producer = ferry_fake_signal_producer();

    new SnapshotSignaler($producer, new SourceConnectorConfigBuilder)->requestSnapshot($connection, []);

    expect($producer->published)->toBe([]);
});

it('publishes an execute-snapshot signal for a single table', function () {
    $connection = TestCase::makeConnection();
    $producer = ferry_fake_signal_producer();

    new SnapshotSignaler($producer, new SourceConnectorConfigBuilder)
        ->requestSnapshot($connection, ['public._reference0']);

    expect($producer->published)->toHaveCount(1);

    $signal = $producer->published[0];

    expect($signal['topic'])
        ->toBe('ferry-signal')
        ->and($signal['key'])
        ->toBe('ferry.test-connection')
        ->and(json_decode($signal['payload'], true, flags: JSON_THROW_ON_ERROR))
        ->toBe([
            'type' => 'execute-snapshot',
            'data' => [
                'data-collections' => ['public._reference0'],
                'type' => 'INCREMENTAL',
            ],
        ]);
});

it('publishes a single signal covering every newly scoped table', function () {
    $connection = TestCase::makeConnection();
    $producer = ferry_fake_signal_producer();

    new SnapshotSignaler($producer, new SourceConnectorConfigBuilder)
        ->requestSnapshot($connection, ['public._reference0', 'public._reference1']);

    expect($producer->published)->toHaveCount(1);

    $payload = json_decode($producer->published[0]['payload'], true, flags: JSON_THROW_ON_ERROR);

    expect($payload['data']['data-collections'])->toBe(['public._reference0', 'public._reference1']);
});

final class FakeSignalProducer implements SignalProducer
{
    /** @var list<array{topic: string, key: string, payload: string}> */
    public array $published = [];

    public function publish(string $topic, string $key, string $payload): void
    {
        $this->published[] = ['topic' => $topic, 'key' => $key, 'payload' => $payload];
    }
}
