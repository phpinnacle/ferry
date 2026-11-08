<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPinnacle\Ferry\Services\EloquentEventPublisher;
use Tests\TestCase;

uses(TestCase::class);

class CapturedEventOrder extends Model
{
    protected $casts = ['quantity' => 'integer'];
}

class CapturedOrderCreated
{
    public function __construct(
        public Model $order,
    ) {}
}

class CapturedCustomEventOrder extends CapturedEventOrder
{
    protected $dispatchesEvents = ['created' => CapturedOrderCreated::class];
}

it('publishes model lifecycle events with database row images and Eloquent change state', function (
    string $operation,
    ?array $before,
    ?array $after,
    array $expectedEvents,
    array $expectedState,
) {
    $events = new Dispatcher;
    $dispatched = [];
    $observed = [];
    $events->listen('eloquent.*', function (string $event, array $models) use (&$dispatched, &$observed) {
        $model = $models[0];
        $dispatched[] = explode(':', $event)[0];
        $observed[] = [
            'attributes' => $model->getAttributes(),
            'original' => $model->getRawOriginal(),
            'changes' => $model->getChanges(),
            'exists' => $model->exists,
            'created' => $model->wasRecentlyCreated,
            'quantity' => $model->quantity,
        ];
    });

    new EloquentEventPublisher($events)->publish(CapturedEventOrder::class, json_encode([
        'op' => $operation,
        'before' => $before,
        'after' => $after,
    ], JSON_THROW_ON_ERROR));

    expect($dispatched)->toBe($expectedEvents);

    foreach ($observed as $state) {
        expect($state)->toBe($expectedState);
    }
})->with([
    'create' => [
        'c',
        null,
        ['id' => 7, 'quantity' => '2'],
        ['eloquent.created', 'eloquent.saved'],
        [
            'attributes' => ['id' => 7, 'quantity' => '2'],
            'original' => [],
            'changes' => [],
            'exists' => true,
            'created' => true,
            'quantity' => 2,
        ],
    ],
    'update' => [
        'u',
        ['id' => 7, 'quantity' => '2'],
        ['id' => 7, 'quantity' => '3'],
        ['eloquent.updated', 'eloquent.saved'],
        [
            'attributes' => ['id' => 7, 'quantity' => '3'],
            'original' => ['id' => 7, 'quantity' => '2'],
            'changes' => ['quantity' => '3'],
            'exists' => true,
            'created' => false,
            'quantity' => 3,
        ],
    ],
    'delete' => [
        'd',
        ['id' => 7, 'quantity' => '3'],
        null,
        ['eloquent.deleted'],
        [
            'attributes' => ['id' => 7, 'quantity' => '3'],
            'original' => ['id' => 7, 'quantity' => '3'],
            'changes' => [],
            'exists' => false,
            'created' => false,
            'quantity' => 3,
        ],
    ],
    'snapshot read' => ['r', null, ['id' => 7, 'quantity' => '3'], [], []],
]);

it('honors custom model events and their listener results before string events', function (
    ?bool $result,
    array $expected,
) {
    $events = new Dispatcher;
    $observed = [];
    $events->listen(CapturedOrderCreated::class, function (CapturedOrderCreated $event) use (&$observed, $result) {
        $observed[] = ['custom', $event->order->id];

        return $result;
    });
    $events->listen('eloquent.created: ' . CapturedCustomEventOrder::class, function (Model $model) use (&$observed) {
        $observed[] = ['created', $model->id];
    });

    new EloquentEventPublisher($events)->publish(
        CapturedCustomEventOrder::class,
        '{"op":"c","before":null,"after":{"id":7}}',
    );

    expect($observed)->toBe($expected);
})->with([
    'fall through' => [null, [['custom', 7], ['created', 7]]],
    'handled' => [true, [['custom', 7]]],
    'stop custom listeners' => [false, [['custom', 7], ['created', 7]]],
]);

it('does not dispatch model events when JSON decoding fails', function () {
    $events = new Dispatcher;
    $observed = [];
    $events->listen('*', function (string $event) use (&$observed) {
        $observed[] = $event;
    });

    expect(fn () => new EloquentEventPublisher($events)->publish(CapturedEventOrder::class, '{'))
        ->toThrow(JsonException::class);
    expect($observed)->toBe([]);
});
