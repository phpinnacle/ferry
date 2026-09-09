<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\SyncResource;
use PHPinnacle\Ferry\Services\SyncDestinationResolver;
use PHPinnacle\Ferry\Services\SyncSchemaBuilder;

class CreateSync extends CreateRecord
{
    protected static string $resource = SyncResource::class;

    private SyncSchemaBuilder $schemas;

    private SyncDestinationResolver $destinations;

    public function boot(SyncSchemaBuilder $schemas, SyncDestinationResolver $destinations): void
    {
        $this->schemas = $schemas;
        $this->destinations = $destinations;
    }

    public function getTitle(): string
    {
        return __('phpinnacle-ferry::resources.sync.pages.create');
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var string $connectionId */
        $connectionId = $data['connection_id'];
        /** @var string $source */
        $source = $data['source'];
        $connection = Connection::query()->find($connectionId);
        $object = $connection?->publishedObject($source);

        if ($object === null) {
            throw ValidationException::withMessages([
                'data.source' => __('phpinnacle-ferry::resources.sync.errors.unknown_object'),
            ]);
        }

        /** @var string|null $staticDestination */
        $staticDestination = $data['static_destination'] ?? null;

        if ($staticDestination === '') {
            $staticDestination = null;
        }

        if ($staticDestination === null) {
            /** @var array<string, string> $bindings */
            $bindings = $data['schema'];
        } else {
            /** @var array<string, string> $mapping */
            $mapping = $data['static_mapping'];
            $bindings = array_flip($mapping);
        }

        $schema = $this->schemas->fromBindings(
            $object,
            $bindings,
            $this->destinations->destination($staticDestination),
        );

        return Sync::query()
            ->getConnection()
            ->transaction(fn () => Sync::create([
                'connection_id' => $connectionId,
                'name' => $data['name'],
                'code' => $data['code'],
                'static_destination' => $staticDestination,
                'source' => $source,
                'schema' => $schema,
            ]));
    }
}
