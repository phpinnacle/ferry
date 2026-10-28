<?php

namespace PHPinnacle\Ferry\Services;

use Closure;
use PHPinnacle\Ferry\Contracts\Origin;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Exceptions\ConnectorOperationFailed;
use PHPinnacle\Ferry\Jobs\Connector\SynchronizeConnectorsJob;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use Throwable;

class ConnectorManager
{
    public function __construct(
        private readonly OriginFactory $origins,
        private readonly ReplicaTableManager $tables,
    ) {}

    public function save(Replica $replica): void
    {
        if ($replica->connector === null && $replica->mapping === []) {
            return;
        }

        $upstream = $replica->upstream;

        if (
            $replica->isDirty('status')
            && !$replica->isDirty(['source', 'schema', 'mapping', 'transforms', 'destination'])
        ) {
            if ($replica->connector !== null) {
                SynchronizeConnectorsJob::dispatch($upstream->id);
            }

            return;
        }

        $this->configure($replica);
    }

    public function refresh(Upstream $upstream): void
    {
        if ($upstream->connector !== null) {
            $this->store($upstream, $this->origins->for($upstream)->make($upstream));
        }
    }

    public function start(Replica $replica): void
    {
        if ($replica->status !== ReplicaStatus::Pause && $replica->connector?->status === ConnectorStatus::Failed) {
            $this->restart($replica);
        } else {
            $this->activate($replica);
        }
    }

    public function activate(Replica $replica): void
    {
        $replica
            ->getConnection()
            ->transaction(function () use ($replica) {
                Upstream::query()->whereKey($replica->upstream_id)->lockForUpdate()->firstOrFail();
                $replica->status = $replica->connector?->applied_config === null
                    ? ReplicaStatus::Pending
                    : ReplicaStatus::Active;
                $replica->saveQuietly();
                $this->configure($replica);
                SynchronizeConnectorsJob::dispatch($replica->upstream_id);
            });
    }

    public function pause(Replica $replica): void
    {
        $replica->pause();
    }

    public function restart(Upstream|Replica $owner): void
    {
        SynchronizeConnectorsJob::dispatch($owner instanceof Upstream ? $owner->id : $owner->upstream_id);
    }

    public function checkStatus(Connector $connector): void
    {
        $this->check($this->origins->create($connector->origin), $connector);
    }

    /** @param (Closure(Connector, Throwable): void)|null $onFailure */
    public function refreshStatuses(?Closure $onFailure = null): void
    {
        foreach (Connector::query()->lazyById() as $connector) {
            try {
                $this->checkStatus($connector);
            } catch (Throwable $exception) {
                if ($onFailure === null) {
                    report($exception);
                } else {
                    $onFailure($connector, $exception);
                }
            }
        }
    }

    public function removed(Replica $replica): void
    {
        if ($replica->connector === null) {
            $this->dropReplica($replica);
        } else {
            SynchronizeConnectorsJob::dispatch($replica->upstream_id);
        }

        $this->refresh($replica->upstream);
    }

    public function removedUpstream(Upstream $upstream): void
    {
        if ($upstream->connector === null && !$upstream->replicas()->withTrashed()->exists()) {
            $upstream->forceDeleteQuietly();
        } else {
            SynchronizeConnectorsJob::dispatch($upstream->id);
        }
    }

    public function synchronize(string $upstreamId): void
    {
        new Upstream()
            ->getConnection()
            ->transaction(function () use ($upstreamId) {
                $upstream = Upstream::withTrashed()->lockForUpdate()->find($upstreamId);

                if ($upstream === null) {
                    return;
                }

                $replicas = $upstream->replicas()->withTrashed()->with('connector')->get();

                foreach ($replicas as $replica) {
                    if ($upstream->trashed() || $replica->trashed()) {
                        $this->removeConnector($replica->connector);
                        $this->dropReplica($replica);
                    }
                }

                if ($upstream->trashed()) {
                    $this->removeConnector($upstream->connector);
                    $upstream->forceDeleteQuietly();

                    return;
                }

                $source = $upstream->connector;

                if ($source !== null) {
                    $this->apply($source);
                }

                foreach ($replicas as $replica) {
                    if ($replica->trashed() || $replica->connector === null) {
                        continue;
                    }

                    $this->apply($replica->connector);
                    $this->run($replica->connector, $replica->status !== ReplicaStatus::Pause);

                    if ($replica->status === ReplicaStatus::Pending) {
                        Replica::withoutEvents($replica->activate(...));
                    }
                }

                if ($source !== null) {
                    $this->run($source, $upstream->replicas()->exists());
                }
            });
    }

    private function store(Upstream|Replica $owner, Connector $connector): void
    {
        $connector->upstream_id = $owner instanceof Upstream ? $owner->id : $owner->upstream_id;
        $connector->save();
        $owner->recordConnector($connector);
    }

    private function configure(Replica $replica): void
    {
        $origin = $this->origins->for($replica);
        $source = $origin->make($replica->upstream);
        $sink = $origin->make($replica);

        $this->store($replica->upstream, $source);
        $this->store($replica, $sink);
    }

    private function apply(Connector $connector): void
    {
        $origin = $this->origins->create($connector->origin);

        try {
            if ($connector->applied_config === null) {
                $origin->create($connector);
            } elseif (
                $connector->applied_config !== $connector->config
                || $connector->status === ConnectorStatus::Unknown
            ) {
                $origin->update($connector);
            }

            $connector->markApplied();
        } catch (Throwable $exception) {
            throw new ConnectorOperationFailed($connector->id, $exception);
        }
    }

    private function run(Connector $connector, bool $running): void
    {
        $origin = $this->origins->create($connector->origin);

        try {
            if ($running) {
                $origin->start($connector);
            } else {
                $origin->pause($connector);
            }

            $this->check($origin, $connector);
        } catch (Throwable $exception) {
            throw new ConnectorOperationFailed($connector->id, $exception);
        }
    }

    private function check(Origin $origin, Connector $connector): void
    {
        try {
            $status = $origin->status($connector);
        } catch (Throwable $exception) {
            throw new ConnectorOperationFailed($connector->id, $exception);
        }
        $connector->recordStatus($status, $connector->error);
    }

    private function removeConnector(?Connector $connector): void
    {
        if ($connector === null) {
            return;
        }

        try {
            $this->origins->create($connector->origin)->remove($connector);
            $connector->delete();
        } catch (Throwable $exception) {
            throw new ConnectorOperationFailed($connector->id, $exception);
        }
    }

    private function dropReplica(Replica $replica): void
    {
        if ($replica->type === DestinationType::Dynamic) {
            $this->tables->drop($replica);
        }

        $replica->forceDeleteQuietly();
    }
}
