<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Pages;

use Filament\Resources\Pages\CreateRecord;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Resources\Syncs\SyncResource;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformFactory;

/** @phpstan-import-type CreateState from SyncForm */
class CreateSync extends CreateRecord
{
    protected static string $resource = SyncResource::class;

    private DestinationFactory $destinations;

    private TransformFactory $transforms;

    public function boot(DestinationFactory $destinations, TransformFactory $transforms): void
    {
        $this->destinations = $destinations;
        $this->transforms = $transforms;
    }

    public function getTitle(): string
    {
        return __('phpinnacle-ferry::resources.sync.pages.create');
    }

    /** @param CreateState $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return SyncForm::forCreate($data, $this->destinations, $this->transforms);
    }
}
