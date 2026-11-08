<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Ferry\Jobs\Connector\SynchronizeConnectorsJob;
use PHPinnacle\Ferry\Jobs\Structure\PrepareStructureJob;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Upstreams\Schemas\UpstreamForm;
use PHPinnacle\Ferry\Services\ConnectorManager;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

class CapturedOrder extends Model
{
    protected $connection = 'capture-models';

    protected $table = 'sales.orders';
}

class OtherConnectionOrder extends CapturedOrder
{
    protected $connection = 'other-models';
}

class UnregisteredOrder extends CapturedOrder {}

beforeEach(function () {
    config([
        'database.connections.capture-models' => [
            'driver' => 'pgsql',
            'host' => 'source.test',
            'port' => 5432,
            'database' => 'orders',
            'username' => 'reader',
            'password' => 'secret',
            'prefix' => 'app_',
        ],
        'database.connections.other-models' => ['driver' => 'pgsql', 'database' => 'other'],
    ]);

    Filament::setCurrentPanel(Panel::make()->id('ferry')->plugin(
        FerryPlugin::make()->models(CapturedOrder::class, OtherConnectionOrder::class),
    ));
});

function ferry_eloquent_form(): Schema
{
    $schema = UpstreamForm::configure(
        Schema::make(SchemaHost::make())->model(Upstream::class)->operation('create')->statePath('data'),
    );
    $schema->fill([
        'name' => 'Orders',
        'code' => 'orders',
        'origin' => 'kafka',
        'adapter' => 'eloquent',
        'is_active' => true,
        'config' => ['connection' => 'capture-models', 'models' => [CapturedOrder::class]],
    ]);

    return $schema;
}

it('offers only registered models belonging to the selected connection and resets selection when it changes', function () {
    $form = ferry_eloquent_form();
    $models = $form->getComponentByStatePath('config.models');

    expect($models->getOptions())->toBe([CapturedOrder::class => CapturedOrder::class]);
    expect($form->getComponentByStatePath('config.connection')->getOptions())->not->toHaveKey('sqlite');

    $form->getComponentByStatePath('config.connection')->state('other-models')->callAfterStateUpdated();

    expect($models->getState())->toBe([]);
    expect($models->getOptions())->toBe([OtherConnectionOrder::class => OtherConnectionOrder::class]);
});

it('rejects models that are not available for capture', function (string $model) {
    $form = ferry_eloquent_form();
    $form->getComponentByStatePath('config.models')->state([$model]);

    expect($form->getState(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('data.config.models.0'),
        );
})->with([
    'unregistered' => [UnregisteredOrder::class],
    'other connection' => [OtherConnectionOrder::class],
]);

it('creates and starts capture without replicas and includes every column without Rosetta converters', function () {
    Queue::fake([SynchronizeConnectorsJob::class, PrepareStructureJob::class]);

    $upstream = Upstream::create(ferry_eloquent_form()->getState());
    app(ConnectorManager::class)->synchronize($upstream->id);
    $upstream->refresh();

    expect($upstream->replicas()->count())->toBe(0);
    expect($upstream->connector->status)->toBe(ConnectorStatus::Running);
    expect($upstream->connector->applied_config)->not->toBeNull();
    Queue::assertPushed(SynchronizeConnectorsJob::class, fn ($job) => $job->upstreamId === $upstream->id);

    $config = new OriginFactory(app(), config('phpinnacle-ferry.origins'))->for($upstream)->make($upstream)->config;

    expect($config)
        ->toMatchArray([
            'table.include.list' => 'sales\\.app_orders',
            'database.hostname' => 'source.test',
            'database.dbname' => 'orders',
        ])
        ->not->toHaveKeys(['column.include.list', 'converters', 'message.key.columns']);
    expect(app(ConnectorManager::class)->canSnapshot($upstream))->toBeTrue();
});

it('hydrates saved model settings and preserves the connection while editing', function () {
    Queue::fake([SynchronizeConnectorsJob::class, PrepareStructureJob::class]);
    $upstream = Upstream::create(ferry_eloquent_form()->getState());
    $form = UpstreamForm::configure(
        Schema::make(SchemaHost::make())->record($upstream)->statePath('data')->operation('edit'),
    );
    $form->fill($upstream->attributesToArray());

    expect($form->getComponentByStatePath('config.models')->getState())->toBe([CapturedOrder::class]);
    $connection = $form->getComponentByStatePath('config.connection');
    expect($connection->isDisabled())->toBeTrue();
    $connection->state('other-models');

    $state = $form->getState();

    expect($state['config']['connection'])->toBe('capture-models');
});

it('rejects models from another connection even if the disabled connection state is changed', function () {
    Queue::fake([SynchronizeConnectorsJob::class, PrepareStructureJob::class]);
    $upstream = Upstream::create(ferry_eloquent_form()->getState());
    $form = UpstreamForm::configure(
        Schema::make(SchemaHost::make())->record($upstream)->statePath('data')->operation('edit'),
    );
    $form->fill($upstream->attributesToArray());
    $form->getComponentByStatePath('config.connection')->state('other-models');
    $form->getComponentByStatePath('config.models')->state([OtherConnectionOrder::class]);

    expect($form->getState(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('data.config.models.0'),
        );
});
