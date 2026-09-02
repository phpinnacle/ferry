<?php

namespace PHPinnacle\Ferry\Resources\Connections\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Resources\Connections\ConnectionResource;

/**
 * @property Connection $record
 */
class EditConnection extends EditRecord
{
    protected static string $resource = ConnectionResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('phpinnacle-ferry::resources.connection.pages.edit');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('phpinnacle-ferry::resources.connection.actions.delete')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['password']);

        return $data;
    }
}
