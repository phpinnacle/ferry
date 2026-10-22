<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Pages;

use Filament\Resources\Pages\CreateRecord;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Resources\Syncs\SyncResource;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;

/** @phpstan-import-type CreateState from SyncForm */
class CreateSync extends CreateRecord
{
    protected static string $resource = SyncResource::class;

    private DestinationFactory $destinations;

    private TransformRegistry $registry;

    public function boot(
        DestinationFactory $destinations,
        TransformRegistry $registry,
    ): void {
        $this->destinations = $destinations;
        $this->registry = $registry;
    }

    public function getTitle(): string
    {
        return __('phpinnacle-ferry::resources.sync.pages.create');
    }

    /** @param CreateState $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return SyncForm::forCreate($data, $this->destinations, $this->registry);
    }
}
