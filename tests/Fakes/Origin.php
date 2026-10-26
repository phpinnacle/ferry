<?php

namespace PHPinnacle\Ferry\Tests\Fakes;

use PHPinnacle\Ferry\Contracts\Origin as OriginContract;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;

class Origin implements OriginContract
{
    /** @var list<array{string, string}> */
    public array $operations = [];

    public function name(): string
    {
        return 'kafka';
    }

    public function make(Upstream|Replica $source): Connector
    {
        $config = $source instanceof Upstream
            ? ['credentials' => $source->config, 'replicas' => $source->replicas()->pluck('id')->all()]
            : [
                'source' => $source->source,
                'destination' => $source->destination,
                'mapping' => $source->attributesToArray()['mapping'],
                'transforms' => $source->attributesToArray()['transforms'],
            ];

        return ($source->connector ?? new Connector)->fill([
            'name' =>
                $source->connector->name
                    ?? 'ferry-' . ($source instanceof Upstream ? 'source-' : 'sink-') . $source->code,
            'origin' => $this->name(),
            'config' => $config,
        ]);
    }

    public function create(Connector $connector): void
    {
        $this->operations[] = ['create', $connector->name];
    }

    public function update(Connector $connector): void
    {
        $this->operations[] = ['update', $connector->name];
    }

    public function remove(Connector $connector): void
    {
        $this->operations[] = ['remove', $connector->name];
    }

    public function start(Connector $connector): void
    {
        $this->operations[] = ['start', $connector->name];
        $connector->status = ConnectorStatus::Running;
    }

    public function pause(Connector $connector): void
    {
        $this->operations[] = ['pause', $connector->name];
        $connector->status = ConnectorStatus::Paused;
    }

    public function status(Connector $connector): ConnectorStatus
    {
        $connector->error = null;

        return $connector->status ?? ConnectorStatus::Running;
    }
}
