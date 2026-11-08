<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Ferry\Enums\ChangeOperation;

class EloquentEventPublisher
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    /** @param class-string<Model> $modelClass */
    public function publish(string $modelClass, string $payload): void
    {
        /**
         * @var array{op: 'c'|'r', before: null, after: array<string, mixed>}
         *     |array{op: 'u', before: array<string, mixed>, after: array<string, mixed>}
         *     |array{op: 'd', before: array<string, mixed>, after: null} $change
         */
        $change = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $operation = ChangeOperation::from($change['op']);

        if ($operation === ChangeOperation::Read) {
            return;
        }

        $event = match ($operation) {
            ChangeOperation::Create => 'created',
            ChangeOperation::Update => 'updated',
            ChangeOperation::Delete => 'deleted',
        };

        $attributes = $change['op'] === 'd' ? $change['before'] : $change['after'];
        $model = new $modelClass;
        $model->setRawAttributes($change['before'] ?? [], sync: true);
        $model->setRawAttributes($attributes);
        $model->exists = $operation !== ChangeOperation::Delete;
        $model->wasRecentlyCreated = $operation === ChangeOperation::Create;

        if ($operation === ChangeOperation::Update) {
            $model->syncChanges();
        }

        $this->dispatchModelEvent($model, $event);

        if ($operation !== ChangeOperation::Delete) {
            $this->dispatchModelEvent($model, 'saved');
            $model->syncOriginal();
        }
    }

    private function dispatchModelEvent(Model $model, string $event): void
    {
        $eventClass = $model->dispatchesEvents()[$event] ?? null;

        if ($eventClass !== null) {
            $result = array_filter(
                $this->events->dispatch(new $eventClass($model)) ?? [],
                fn ($response) => $response !== null,
            );

            if ($result !== []) {
                return;
            }
        }

        $this->events->dispatch("eloquent.{$event}: " . $model::class, [$model]);
    }
}
