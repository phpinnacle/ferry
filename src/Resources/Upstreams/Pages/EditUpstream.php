<?php

namespace PHPinnacle\Ferry\Resources\Upstreams\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Actions\RequestSnapshotAction;
use PHPinnacle\Ferry\Resources\Actions\ViewConnectorConfigAction;
use PHPinnacle\Ferry\Resources\Upstreams\UpstreamResource;
use PHPinnacle\Ferry\Services\PrometheusClient;
use PHPinnacle\Ferry\Widgets\Monitoring;

/**
 * @property Upstream $record
 */
class EditUpstream extends EditRecord
{
    protected static string $resource = UpstreamResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('phpinnacle-ferry::resources.upstream.pages.edit');
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewConnectorConfigAction::make(),
            RequestSnapshotAction::make(),
            DeleteAction::make()
                ->label(__('phpinnacle-ferry::resources.upstream.actions.delete')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return app(PrometheusClient::class)->isEnabled($this->record->origin) ? [Monitoring::class] : [];
    }
}
