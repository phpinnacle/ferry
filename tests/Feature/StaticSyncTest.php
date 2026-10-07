<?php

use Filament\Actions\Action;
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
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Pages\EditSync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();

    app(DestinationFactory::class)->register(customers_destination());
});

it('prepares typed synchronization attributes from its form', function (?string $destination) {
    config([
        'phpinnacle-ferry.kafka_connect.base_uri' => null,
        'phpinnacle-ferry.kafka_connect.signal.bootstrap_servers' => null,
    ]);
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
    ]);

    $data = [
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
        'source' => 'object-0',
        'static_destination' => $destination,
        $destination === null ? 'schema' : 'static_mapping' => ['_description' => 'name', '_code' => 'tax_number'],
    ];

    $destinations = app(DestinationFactory::class);
    $sync = Sync::create(SyncForm::forCreate($data, $destinations));
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-mapping-round-trip');
    $livewire->setName('sync-mapping-round-trip');
    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->toArray()));
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
    $sync->update(SyncForm::forUpdate($dehydrated, $sync, $destinations));

    expect($sync->fresh()->name)
        ->toBe('Renamed')
        ->and($sync->fresh()->static_destination)
        ->toBe($destination)
        ->and(array_column($sync->fresh()->schema, 'column', 'source'))
        ->toEqual(['_description' => 'name', '_code' => 'tax_number'])
        ->and(Schema::hasTable('ferry_sync_customers_sync'))
        ->toBe($destination === null);
})->with(['dynamic' => [null], 'static' => ['customers']]);

it('confirms removed and retyped dynamic columns when saving the form', function (string $change, array $columns) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'system' => [
            '_description' => new StringField(length: 100, fixed: false),
            '_code' => new StringField(length: 11, fixed: true),
        ],
    ]);
    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'static_destination' => $change === 'static' ? 'customers' : null,
        'schema' => [
            new FieldMapping('_description', 'name', $object->field('_description')),
            new FieldMapping('_code', 'tax_number', $object->field('_code')),
        ],
    ]);

    if ($change === 'retyped') {
        $object->update(['system' => [
            '_description' => new StringField(length: 100, fixed: false),
            '_code' => new ScalarField(FieldType::Boolean),
        ]]);
    }

    $page = new class extends EditSync {
        public function saveAction(): Action
        {
            return $this->getSaveFormAction()->livewire($this);
        }
    };
    $page->setId('sync-drop-confirmation');
    $page->setName('sync-drop-confirmation');
    $page->record = $sync;
    $this->app->call([$page, 'boot']);
    $page->form->fill(SyncForm::fill($sync->toArray()));

    if ($change === 'removed') {
        $field = $page->form->getComponent('schema');
        unset($page->data['schema'][$field->getBindingKey('_description')]);
    }

    if ($change === 'renamed') {
        $field = $page->form->getComponent('schema');
        $page->data['schema'][$field->getBindingKey('_description')]['column'] = 'display_name';
    }

    $action = $page->saveAction();
    $action->mount(['schema' => null]);

    expect($action->shouldOpenModal())
        ->toBe($columns !== [])
        ->and($page->record->isDirty())
        ->toBeFalse();

    if ($columns !== []) {
        expect($action->getModalDescription())->toBe(__(
            'phpinnacle-ferry::resources.sync.modals.drop_columns.description',
            ['columns' => implode(', ', $columns)],
        ));
    }
})->with([
    'unchanged mapping' => ['unchanged', []],
    'removed field' => ['removed', ['name']],
    'retyped field' => ['retyped', ['tax_number']],
    'renamed column' => ['renamed', []],
    'static destination' => ['static', []],
]);

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

it('pauses a synchronization and rejects editing when its destination is no longer registered', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    TestCase::makeMetadata($connection);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'customers_sync',
        'static_destination' => 'customers',
    ]);
    $sync->forceFill(['static_destination' => 'gone'])->saveQuietly();
    $connection->metadata()->update(['revision' => $connection->draftRevision()]);
    $connection->publishStructure();

    expect($sync->fresh()->status)
        ->toBe(SyncStatus::Pause)
        ->and($sync->fresh()->is_paused)
        ->toBeFalse();

    expect(fn () => SyncForm::forUpdate(
        ['name' => 'Renamed', 'schema' => []],
        $sync->fresh(),
        app(DestinationFactory::class),
    ))
        ->toThrow(
            ValidationException::class,
            __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', ['destination' => 'gone']),
        );
});
