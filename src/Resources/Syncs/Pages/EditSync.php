<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Forms\FieldBinding;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\SyncResource;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use PHPinnacle\Ferry\Services\SyncDestinationResolver;
use PHPinnacle\Ferry\Services\SyncReviewer;
use PHPinnacle\Ferry\Services\SyncSchemaBuilder;

/**
 * @property Sync $record
 */
class EditSync extends EditRecord
{
    protected static string $resource = SyncResource::class;

    /** @var list<string>|null */
    private ?array $columnsToDrop = null;

    private SyncSchemaBuilder $schemas;

    private SyncReviewer $reviewer;

    private SyncDestinationResolver $destinations;

    public function boot(
        SyncSchemaBuilder $schemas,
        SyncReviewer $reviewer,
        SyncDestinationResolver $destinations,
    ): void {
        $this->schemas = $schemas;
        $this->reviewer = $reviewer;
        $this->destinations = $destinations;
    }

    public function getTitle(): string|Htmlable
    {
        return __('phpinnacle-ferry::resources.sync.pages.edit');
    }

    public function hasFormWrapper(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('phpinnacle-ferry::resources.sync.actions.delete'))
                ->requiresConfirmation()
                ->modalHeading(__('phpinnacle-ferry::resources.sync.modals.delete.heading'))
                ->modalDescription(__(
                    'phpinnacle-ferry::resources.sync.modals.delete.' . match ($this->record->destinationType()) {
                        DestinationType::Dynamic => 'description_dynamic',
                        DestinationType::Static => 'description_static',
                    },
                ))
                ->modalSubmitActionLabel(__('phpinnacle-ferry::resources.sync.actions.delete')),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->mountUsing(fn () => $this->form->validate())
            ->action($this->save(...))
            ->modal(fn () => $this->droppedColumns() !== [])
            ->requiresConfirmation()
            ->modalHeading(__('phpinnacle-ferry::resources.sync.modals.drop_columns.heading'))
            ->modalDescription(fn () => __(
                'phpinnacle-ferry::resources.sync.modals.drop_columns.description',
                ['columns' => implode(', ', $this->droppedColumns())],
            ));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Sync $record */
        $object = $record->connection->publishedObject($record->source);

        if ($object === null) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.sync.errors.unknown_object'),
            ]);
        }

        $destination = $this->destinations->find($record->static_destination);

        if ($record->static_destination !== null && $destination === null) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', [
                    'destination' => $record->static_destination,
                ]),
            ]);
        }

        /** @var array<string, string> $bindings */
        $bindings = $data[$destination === null ? 'schema' : 'static_mapping'];

        $schema = $this->schemas->fromBindings($object, $bindings, $destination);

        $shouldActivate = $record
            ->getConnection()
            ->transaction(function () use ($record, $data, $schema, $object) {
                $record->update([
                    'name' => $data['name'],
                    'schema' => $schema,
                ]);

                return $this->reviewer->shouldResume($record, $object);
            });

        if ($shouldActivate) {
            app(ConnectorManager::class)->activate($record);
        }

        return $record;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var list<array{source: string, column: string}> $schema */
        $schema = $data['schema'];
        $data['schema'] = array_column($schema, 'column', 'source');
        $data['static_mapping'] = $data['schema'];

        return $data;
    }

    /** @return list<string> */
    private function droppedColumns(): array
    {
        if ($this->columnsToDrop !== null) {
            return $this->columnsToDrop;
        }

        /** @var Sync $record */
        $record = $this->getRecord();

        if ($record->destinationType() === DestinationType::Static) {
            return $this->columnsToDrop = [];
        }

        /** @var FieldBinding $field */
        $field = $this->form->getComponent('schema');
        /** @var array<string, mixed> $bindings */
        $bindings = $this->data['schema'] ?? [];
        $sources = [];

        foreach ($field->getSources() as $source) {
            if (array_key_exists($field->getBindingKey($source['id']), $bindings)) {
                $sources[$source['id']] = true;
            }
        }

        $object = $record->connection->publishedObject($record->source);
        $retyped = $object instanceof ConnectionMetadata
            ? $this->reviewer->brokenColumns($record, $object)
            : [];

        $dropped = [];

        foreach ($record->schema as $mapping) {
            if (!array_key_exists($mapping->source, $sources) || in_array($mapping->column, $retyped, true)) {
                $dropped[] = $mapping->column;
            }
        }

        return $this->columnsToDrop = $dropped;
    }
}
