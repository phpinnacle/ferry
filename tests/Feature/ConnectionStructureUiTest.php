<?php

use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema as FilamentSchema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Queue;
use Livewire\Component as LivewireComponent;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Jobs\PrepareStructureJob;
use PHPinnacle\Ferry\Resources\Connections\Actions\PrepareStructureAction;
use PHPinnacle\Ferry\Resources\Connections\Pages\ListConnections;
use PHPinnacle\Ferry\Resources\Connections\Schemas\ConnectionForm;
use PHPinnacle\Ferry\Resources\Connections\Tables\ConnectionTable;
use PHPinnacle\Ferry\Support\ConnectionErrorFormatter;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function (): void {
    Queue::fake();
});

it('labels the preparation action by the current structure status', function (StructureStatus $status): void {
    $connection = TestCase::makeConnection([], ['status' => $status]);

    expect(PrepareStructureAction::table()->record($connection)->getLabel())
        ->toBe(
            __('phpinnacle-ferry::resources.connection.actions.structure.' . $status->value),
        );
})->with([
    'not prepared yet' => StructureStatus::Pending,
    'preparation running' => StructureStatus::Preparing,
    'snapshot published' => StructureStatus::Ready,
    'last attempt failed' => StructureStatus::Failed,
]);

it('hides the preparation action while a live preparation is running', function (): void {
    $connection = TestCase::makeConnection();

    expect(PrepareStructureAction::table()->record($connection)->isVisible())->toBeFalse();
});

it('offers the preparation action again when the running attempt went stale', function (): void {
    $connection = TestCase::makeConnection([], [
        'heartbeat_at' => CarbonImmutable::now()->subSeconds(
            config('phpinnacle-ferry.structure.stale_after') + 1,
        ),
    ]);

    expect(PrepareStructureAction::table()->record($connection)->isVisible())->toBeTrue();
});

it('asks for confirmation only before re-reading a published snapshot', function (
    StructureStatus $status,
    bool $confirms,
): void {
    $connection = TestCase::makeConnection([], ['status' => $status]);

    expect(PrepareStructureAction::table()->record($connection)->isConfirmationRequired())->toBe($confirms);
})->with([
    'not prepared yet' => [StructureStatus::Pending, false],
    'snapshot published' => [StructureStatus::Ready, true],
    'last attempt failed' => [StructureStatus::Failed, false],
]);

it('queues the preparation and notifies the operator', function (): void {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    PrepareStructureAction::table()->record($connection)->call();

    expect($connection->fresh()->status)
        ->toBe(StructureStatus::Preparing)
        ->and(session()->get('filament.notifications'))
        ->toHaveCount(1)
        ->and(session()->get('filament.notifications.0.title'))
        ->toBe(__('phpinnacle-ferry::resources.connection.messages.structure_queued'));

    Queue::assertPushed(PrepareStructureJob::class, 2);
});

it('warns instead of queueing a second preparation', function (): void {
    $connection = TestCase::makeConnection();

    PrepareStructureAction::table()->record($connection)->call();

    expect(session()->get('filament.notifications.0.title'))
        ->toBe(__('phpinnacle-ferry::resources.connection.messages.structure_active'));

    Queue::assertPushed(PrepareStructureJob::class, 1);
});

it('leaves the acted-on record current for the surrounding structure block', function (): void {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    PrepareStructureAction::form()->record($connection)->call();

    expect($connection->status)
        ->toBe(StructureStatus::Preparing)
        ->and($connection->run_id)
        ->not->toBeNull();
});

it('shows the structure status as a badge in the connections table', function (): void {
    $table = ConnectionTable::configure(Table::make(new ListConnections));

    /** @var TextColumn $column */
    $column = $table->getColumns()['status'];

    expect($column)
        ->toBeInstanceOf(TextColumn::class)
        ->and($column->getLabel())
        ->toBe(__('phpinnacle-ferry::resources.connection.fields.structure'))
        ->and($column->isBadge())
        ->toBeTrue();
});

it('renders the read-only structure block only when editing a connection', function (): void {
    $connection = TestCase::makeConnection([], [
        'status' => StructureStatus::Failed,
        'generation' => 1,
        'processed' => 3,
        'total' => 7,
        'published_at' => CarbonImmutable::now(),
        'last_error' => ConnectionErrorFormatter::stale(),
    ]);

    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('connection-form-test');
    $livewire->setName('connection-form-test');

    $entries = fn (string $operation) => collect(
        ConnectionForm::configure(
            FilamentSchema::make($livewire)
                ->statePath('data')
                ->record($connection)
                ->operation($operation),
        )->getFlatComponents(),
    )
        ->filter(fn ($component) => $component instanceof TextEntry)
        ->keyBy(fn (TextEntry $entry) => $entry->getName());

    $edit = $entries('edit');

    expect($edit->get('status')?->getState())
        ->toBe(StructureStatus::Failed)
        ->and($edit->get('objects')?->getState())
        ->toBe(0)
        ->and($edit->get('progress')?->getState())
        ->toBe('3 / 7')
        ->and($edit->get('published_at')?->getState())
        ->not
        ->toBeNull()
        ->and($edit->get('last_error')?->getState())
        ->toBe(ConnectionErrorFormatter::stale())
        ->and($entries('create'))
        ->toBeEmpty();
});
