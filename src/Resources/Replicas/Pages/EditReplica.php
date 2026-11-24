<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Resources\Actions\RequestSnapshotAction;
use PHPinnacle\Ferry\Resources\Actions\ViewConnectorConfigAction;
use PHPinnacle\Ferry\Resources\Replicas\Actions\PauseReplicaAction;
use PHPinnacle\Ferry\Resources\Replicas\Actions\StartReplicaAction;
use PHPinnacle\Ferry\Resources\Replicas\ReplicaResource;
use PHPinnacle\Ferry\Services\PrometheusClient;
use PHPinnacle\Ferry\Widgets\Monitoring;

/**
 * @property Replica $record
 */
class EditReplica extends EditRecord
{
    protected static string $resource = ReplicaResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('phpinnacle-ferry::resources.replica.pages.edit');
    }

    protected function getHeaderWidgets(): array
    {
        return app(PrometheusClient::class)->isEnabled($this->record->upstream->origin) ? [Monitoring::class] : [];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewConnectorConfigAction::make(),
            RequestSnapshotAction::make(),
            StartReplicaAction::table()->button(),
            PauseReplicaAction::table()->button(),
            DeleteAction::make()
                ->label(__('phpinnacle-ferry::resources.replica.actions.delete'))
                ->requiresConfirmation()
                ->modalHeading(__('phpinnacle-ferry::resources.replica.modals.delete.heading'))
                ->modalDescription(__(
                    'phpinnacle-ferry::resources.replica.modals.delete.' . match ($this->record->type) {
                        DestinationType::Dynamic => 'description_dynamic',
                        DestinationType::Static => 'description_static',
                    },
                ))
                ->modalSubmitActionLabel(__('phpinnacle-ferry::resources.replica.actions.delete')),
        ];
    }
}
