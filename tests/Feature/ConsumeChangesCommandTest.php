<?php

use Illuminate\Console\Signals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

class ConsumedOrder extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'public.orders';
}

function ferry_consumer_upstream(array $attributes = []): Upstream
{
    return TestCase::makeUpstream([
        'adapter' => 'eloquent',
        'config' => ['connection' => 'pgsql', 'models' => [ConsumedOrder::class]],
        ...$attributes,
    ]);
}

it('finishes without opening Kafka when there are no active Eloquent upstreams', function () {
    Queue::fake();
    TestCase::makeUpstream(['code' => 'rosetta']);
    ferry_consumer_upstream(['code' => 'inactive', 'is_active' => false]);

    $this->artisan('ferry:consume')->expectsOutputToContain('No active Eloquent upstreams.')->assertSuccessful();
});

it('requires an applied source connector before consuming an upstream', function () {
    Queue::fake();
    $upstream = ferry_consumer_upstream();

    $this
        ->artisan('ferry:consume', ['--upstream' => $upstream->id])
        ->expectsOutputToContain('Apply the upstream source connector before consuming events.')
        ->assertFailed();
});

it('does not consume inactive or other-adapter upstreams', function (array $attributes) {
    Queue::fake();
    $upstream = ferry_consumer_upstream($attributes);

    expect(fn () => $this->artisan('ferry:consume', ['--upstream' => $upstream->id])->run())
        ->toThrow(ModelNotFoundException::class);
})->with([
    'inactive' => [['adapter' => 'eloquent', 'is_active' => false]],
    'other adapter' => [['adapter' => 'rosetta']],
]);

it('supervises consumer subprocess output and shutdown', function (string $script, int $exitCode, string $message) {
    Queue::fake();
    $upstream = ferry_consumer_upstream();
    $basePath = base_path();
    $directory = sys_get_temp_dir() . '/ferry-consume-' . bin2hex(random_bytes(8));
    mkdir($directory);
    file_put_contents($directory . '/artisan', $script);
    $this->app->setBasePath($directory);
    $asyncSignals = pcntl_async_signals();
    $signalHandlers = [];

    foreach ([SIGTERM, SIGINT] as $signal) {
        $signalHandlers[$signal] = pcntl_signal_get_handler($signal);
        pcntl_signal($signal, SIG_DFL);
    }

    Signals::resolveAvailabilityUsing(fn () => true);

    try {
        $this
            ->artisan('ferry:consume')
            ->expectsOutputToContain('[test-upstream] --upstream=' . $upstream->id)
            ->expectsOutputToContain($message)
            ->assertExitCode($exitCode);
    } finally {
        Signals::resolveAvailabilityUsing(fn () => false);

        foreach ($signalHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }

        pcntl_async_signals($asyncSignals);
        $this->app->setBasePath($basePath);
        unlink($directory . '/artisan');
        rmdir($directory);
    }
})->with([
    'unexpected exit fails the supervisor' => [
        '<?php fwrite(STDOUT, $argv[2] . PHP_EOL); exit(23);',
        1,
        'Consumer [test-upstream] exited with code 23.',
    ],
    'termination is forwarded and finishes gracefully' => [
        <<<'PHP'
            <?php
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () {
                fwrite(STDOUT, "terminated\n");
                exit(0);
            });
            fwrite(STDOUT, $argv[2] . PHP_EOL);
            posix_kill(posix_getppid(), SIGTERM);
            sleep(5);
            exit(24);
            PHP,
        0,
        'terminated',
    ],
])->skip(!extension_loaded('pcntl') || !extension_loaded('posix'), 'Signal handling requires pcntl and posix.');
