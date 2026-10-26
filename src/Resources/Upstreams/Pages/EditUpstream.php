<?php

namespace PHPinnacle\Ferry\Resources\Upstreams\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Upstreams\UpstreamResource;

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
            DeleteAction::make()
                ->label(__('phpinnacle-ferry::resources.upstream.actions.delete')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['config'] = $this->record->config;

        return $data;
    }
}
