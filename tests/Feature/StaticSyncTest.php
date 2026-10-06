<?php

use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema as FormSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Pages\CreateSync;
use PHPinnacle\Ferry\Resources\Syncs\Pages\EditSync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Services\StaticDestinationRegistry;
use PHPinnacle\Ferry\Services\SyncDestinationResolver;
use PHPinnacle\Ferry\Services\SyncReviewer;
use PHPinnacle\Ferry\Services\SyncSchemaBuilder;
use PHPinnacle\Ferry\Tests\Fakes\FakeCustomersDestination;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/FakeCustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();

    app(StaticDestinationRegistry::class)->register(new FakeCustomersDestination);
});

it('creates and edits a synchronization through its page handlers', function (?string $destination) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);

    $data = [
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
        'source' => 'object-0',
        'static_destination' => $destination,
        $destination === null ? 'schema' : 'static_mapping' => ['_description' => 'name', '_code' => 'tax_number'],
    ];

    $create = new CreateSync;
    $create->boot(app(SyncSchemaBuilder::class), app(SyncDestinationResolver::class));
    $sync = new ReflectionMethod($create, 'handleRecordCreation')->invoke($create, $data);

    $edit = new EditSync;
    $edit->boot(app(SyncSchemaBuilder::class), app(SyncReviewer::class), app(SyncDestinationResolver::class));
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-mapping-round-trip');
    $livewire->setName('sync-mapping-round-trip');
    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $filled = new ReflectionMethod($edit, 'mutateFormDataBeforeFill')->invoke($edit, $sync->toArray());
    $form->fill($filled);
    $livewire->data['name'] = 'Renamed';

    if ($destination !== null) {
        expect($form->getComponent('static_mapping')->getState())
            ->toBe(['name' => '_description', 'tax_number' => '_code']);
    }

    $dehydrated = $form->getState();
    expect($dehydrated[$destination === null ? 'schema' : 'static_mapping'])->toEqual([
        '_description' => 'name',
        '_code' => 'tax_number',
    ]);
    new ReflectionMethod($edit, 'handleRecordUpdate')->invoke($edit, $sync, $dehydrated);

    expect($sync->fresh()->name)
        ->toBe('Renamed')
        ->and($sync->fresh()->static_destination)
        ->toBe($destination)
        ->and(array_column($sync->fresh()->schema, 'column', 'source'))
        ->toEqual(['_description' => 'name', '_code' => 'tax_number'])
        ->and(Schema::hasTable('ferry_sync_customers_sync'))
        ->toBe($destination === null);
})->with(['dynamic' => [null], 'static' => ['customers']]);

it('resolves the destination table from the registered static destination', function () {
    $sync = TestCase::makeSync([
        'code' => 'customers_sync',
        'static_destination' => 'customers',
        'schema' => [
            new FieldMapping('_description', 'name', new StringField(length: 100, fixed: false)),
        ],
    ]);

    expect($sync->destinationType())
        ->toBe(DestinationType::Static)
        ->and($sync->destination)
        ->toBe('customers')
        ->and(Schema::hasTable('ferry_sync_customers_sync'))
        ->toBeFalse();
});

it('does not affect the destination table when a static synchronization is deleted', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
    });

    $sync = TestCase::makeSync([
        'code' => 'customers_sync',
        'static_destination' => 'customers',
        'schema' => [
            new FieldMapping('_description', 'name', new StringField(length: 100, fixed: false)),
        ],
    ]);

    $sync->delete();

    expect(Schema::hasTable('customers'))
        ->toBeTrue()
        ->and(Sync::query()->count())
        ->toBe(0);
});

it('rejects saving a synchronization whose static destination is no longer registered', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
        'system' => ['_idrref' => new ScalarField(FieldType::Id)],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'customers_sync',
        'static_destination' => 'customers',
    ]);
    $sync->forceFill(['static_destination' => 'gone'])->saveQuietly();

    $page = new EditSync;
    $page->boot(
        app(SyncSchemaBuilder::class),
        app(SyncReviewer::class),
        app(SyncDestinationResolver::class),
    );

    expect(fn () => new ReflectionMethod($page, 'handleRecordUpdate')->invoke(
        $page,
        $sync->fresh(),
        ['name' => 'Renamed', 'schema' => []],
    ))
        ->toThrow(
            ValidationException::class,
            __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', ['destination' => 'gone']),
        );
});
