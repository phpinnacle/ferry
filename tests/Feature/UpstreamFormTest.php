<?php

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\UpstreamTestResult;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Enums\SSLMode;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Upstreams\Schemas\UpstreamForm;
use PHPinnacle\Ferry\Services\UpstreamFactory;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();

    config([
        'phpinnacle-ferry.origins.analytics' => [
            ...config('phpinnacle-ferry.origins.kafka'),
        ],
    ]);
});

function ferry_upstream_form(?Upstream $record = null): Schema
{
    $schema = UpstreamForm::configure(
        Schema::make(SchemaHost::make())
            ->model($record ?? Upstream::class)
            ->operation($record === null ? 'create' : 'edit')
            ->statePath('data'),
    );
    $schema->fill([
        'name' => 'Form upstream',
        'code' => 'form-upstream',
        'driver' => 'pgsql',
        'config' => [
            'host' => 'localhost',
            'port' => 5432,
            'database' => 'source',
            'username' => 'user',
            'password' => 'secret',
        ],
        'is_active' => true,
        'origin' => $record?->origin,
        'adapter' => $record?->adapter,
    ]);

    return $schema;
}

it('hydrates hidden connection settings directly from its record without exposing them in model serialization', function () {
    $upstream = TestCase::makeUpstream();
    $form = UpstreamForm::configure(
        Schema::make(SchemaHost::make())->record($upstream)->statePath('data')->operation('edit'),
    );
    $form->fill($upstream->attributesToArray());

    expect($upstream->attributesToArray())->not->toHaveKey('config');
    expect($form->getComponentByStatePath('config.host')->getState())->toBe('localhost');
    expect($form->getComponentByStatePath('config.password')->getState())->toBeNull();

    $form->getComponentByStatePath('config.host')->state('updated.internal');
    $upstream->update($form->getState());

    expect($upstream->fresh()->config['host'])->toBe('updated.internal');
    expect($upstream->fresh()->attributesToArray())->not->toHaveKey('config');
});

it('offers configured origins and saves the selected key', function () {
    $schema = ferry_upstream_form();
    $field = $schema->getComponentByStatePath('origin');

    expect($field->getOptions())->toBe(['kafka' => 'kafka', 'analytics' => 'analytics']);
    expect($field->getDefaultState())->toBeNull();

    $field->state('analytics');
    $upstream = Upstream::create($schema->getState());

    expect($upstream->fresh()->origin)->toBe('analytics');
});

it('rejects missing or unconfigured origins', function (?string $selection) {
    $schema = ferry_upstream_form();
    $schema->getComponentByStatePath('origin')->state($selection);

    expect($schema->getState(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('data.origin'),
        );
})->with([null, 'missing']);

it('keeps the saved driver and origin while updating connection settings', function () {
    $upstream = TestCase::makeUpstream(['origin' => 'analytics']);
    $schema = ferry_upstream_form($upstream);
    $origin = $schema->getComponentByStatePath('origin');
    $driver = $schema->getComponentByStatePath('driver');

    expect($origin->isDisabled())->toBeTrue()->and($driver->isDisabled())->toBeTrue();

    $origin->state('kafka');
    $driver->state('sqlsrv');
    $schema->getComponentByStatePath('config.host')->state('replica.example');
    $state = $schema->getState();
    expect($state)->not->toHaveKey('origin')->not->toHaveKey('driver');
    $upstream->update($state);

    expect($upstream->fresh()->origin)
        ->toBe('analytics')
        ->and($upstream->fresh()->driver)
        ->toBe(Driver::Pgsql)
        ->and($upstream->fresh()->config['host'])
        ->toBe('replica.example');
});

it('uses the selected driver fields and defaults when changing drivers', function () {
    $schema = ferry_upstream_form();
    $driver = $schema->getComponentByStatePath('driver');

    expect($schema->getComponentByStatePath('config.schema'))->not->toBeNull();
    $driver->state('sqlsrv')->callAfterStateUpdated();

    expect($schema->getComponentByStatePath('config.port')->getState())
        ->toBe(1433)
        ->and($schema->getComponentByStatePath('config.host')->getState())
        ->toBeNull()
        ->and($schema->getComponentByStatePath('config.schema'))
        ->toBeNull()
        ->and($schema->getComponentByStatePath('config.sslmode'))
        ->toBeNull();

    $schema->getComponentByStatePath('driver')->state('pgsql')->callAfterStateUpdated();

    expect($schema->getComponentByStatePath('config.port')->getState())
        ->toBe(5432)
        ->and($schema->getComponentByStatePath('config.sslmode'))
        ->not->toBeNull();
});

it('validates the selected driver configuration', function (mixed $port) {
    $schema = ferry_upstream_form();
    $schema->getComponentByStatePath('origin')->state('kafka');
    $schema->getComponentByStatePath('config.port')->state($port);

    expect($schema->getState(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('data.config.port'),
        );
})->with([0, 65_536, 'invalid']);

it('saves PostgreSQL SSL modes as native connection values', function (?string $mode) {
    $schema = ferry_upstream_form();
    $schema->getComponentByStatePath('origin')->state('kafka');
    $schema->getComponentByStatePath('config.sslmode')->state($mode);

    $upstream = Upstream::create($schema->getState())->fresh();

    expect($upstream->config['sslmode'])->toBe($mode)->and($upstream->credentials()['sslmode'])->toBe($mode);
})->with(['driver default' => [null], 'explicit SSL mode' => ['verify-full']]);

it('rejects invalid PostgreSQL SSL modes', function (mixed $mode) {
    $schema = ferry_upstream_form();
    $schema->getComponentByStatePath('origin')->state('kafka');
    $schema->getLivewire()->data['config']['sslmode'] = $mode;

    expect($schema->getState(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('data.config.sslmode'),
        );
})->with(['unknown mode' => ['invalid'], 'number' => [42], 'array' => [['require']]]);

it('keeps the saved password when the configuration password is left blank', function () {
    $upstream = TestCase::makeUpstream();
    $schema = ferry_upstream_form($upstream);
    $schema->getComponentByStatePath('config.password')->state('');
    $schema->getComponentByStatePath('config.host')->state('replica.example');

    $upstream->update($schema->getState());

    expect($upstream->fresh()->config)
        ->toMatchArray(['host' => 'replica.example', 'password' => 'secret']);
});

it('tests only driver fields and preserves a saved password left blank', function (bool $editing) {
    $record = $editing ? TestCase::makeUpstream() : null;
    $schema = ferry_upstream_form($record);
    $schema->getComponentByStatePath('name')->state(null);
    $schema->getComponentByStatePath('config.sslmode')->state(SSLMode::VerifyFull->value);

    if ($editing) {
        $schema->getComponentByStatePath('config.password')->state('');
    }

    $factory = Mockery::mock(UpstreamFactory::class);
    $factory
        ->shouldReceive('test')
        ->once()
        ->with(Mockery::subset([
            'driver' => 'pgsql',
            'host' => 'localhost',
            'password' => 'secret',
            'sslmode' => 'verify-full',
        ]))
        ->andReturn(UpstreamTestResult::success('Connected'));
    app()->instance(UpstreamFactory::class, $factory);

    $action = $schema->getComponent(
        fn ($component) => $component instanceof Action && $component->getName() === 'test_upstream',
    );
    expect($action->isDisabled())->toBeFalse();
    $action->call();

    Notification::assertNotified(Notification::make()->title('Connected')->success());
})->with(['create' => [false], 'edit' => [true]]);

it('validates connection settings before testing an upstream', function () {
    $schema = ferry_upstream_form();
    $schema->getComponentByStatePath('config.port')->state(0);
    $factory = Mockery::mock(UpstreamFactory::class);
    $factory->shouldNotReceive('test');
    app()->instance(UpstreamFactory::class, $factory);

    $action = $schema->getComponent(
        fn ($component) => $component instanceof Action && $component->getName() === 'test_upstream',
    );

    expect($action->call(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('data.config.port'),
        );
});

it('offers the registered adapter and persists its optional key', function (?string $key) {
    $schema = ferry_upstream_form();
    $schema->getComponentByStatePath('origin')->state('kafka');
    $field = $schema->getComponentByStatePath('adapter');
    expect($field->getOptions())
        ->toBe(['rosetta' => '1C Rosetta'])
        ->and($field->getDefaultState())
        ->toBeNull()
        ->and($field->isDisabled())
        ->toBeFalse();
    $field->state($key);

    $upstream = Upstream::create($schema->getState())->fresh();

    expect($upstream->adapter)
        ->toBe($key)
        ->and($upstream->structure->status)
        ->toBe(
            $key === null
                ? StructureStatus::Pending
                : StructureStatus::Preparing,
        );
})->with([null, 'rosetta']);

it('rejects unregistered adapter selections', function (mixed $selection) {
    $schema = ferry_upstream_form();
    $schema->getComponentByStatePath('origin')->state('kafka');
    $schema->getLivewire()->data['adapter'] = $selection;

    expect($schema->getState(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('data.adapter'),
        );
})->with(['unknown' => ['missing'], 'number' => [42]]);

it('keeps the saved adapter when edit state attempts to select or clear it', function (?string $key) {
    $upstream = TestCase::makeUpstream(['adapter' => $key === null ? 'rosetta' : null]);
    $schema = ferry_upstream_form($upstream);
    $field = $schema->getComponentByStatePath('adapter');
    expect($field->isDisabled())->toBeTrue();
    $field->state($key);
    $state = $schema->getState();
    expect($state)->not->toHaveKey('adapter');
    $upstream->update($state);

    expect($upstream->fresh()->adapter)->toBe($key === null ? 'rosetta' : null);
})->with([null, 'rosetta']);
