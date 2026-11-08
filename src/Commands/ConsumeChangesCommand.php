<?php

namespace PHPinnacle\Ferry\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\EloquentEventPublisher;
use PHPinnacle\Ferry\Services\EloquentModels;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ConsumeChangesCommand extends Command
{
    protected $signature = 'ferry:consume {--upstream= : Consume one upstream by ID}';

    protected $description = 'Read Debezium JSON topics and publish Eloquent model events';

    public function handle(EloquentEventPublisher $publisher): int
    {
        if ($this->option('upstream') === null) {
            return $this->supervise();
        }

        $upstream = Upstream::activeForAdapter('eloquent')->findOrFail($this->option('upstream'));

        return $this->consume($upstream, $publisher);
    }

    private function supervise(): int
    {
        $upstreams = Upstream::activeForAdapter('eloquent')->orderBy('id')->get();

        if ($upstreams->isEmpty()) {
            $this->components->info('No active Eloquent upstreams.');

            return self::SUCCESS;
        }

        /** @var array<string, Process> $processes */
        $processes = [];
        $running = true;
        $this->trap([SIGTERM, SIGINT], function (int $signal) use (&$running, &$processes) {
            $running = false;

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->signal($signal);
                }
            }
        });

        try {
            foreach ($upstreams as $upstream) {
                $process = new Process(
                    [
                        PHP_BINARY,
                        base_path('artisan'),
                        'ferry:consume',
                        '--upstream=' . $upstream->id,
                        '--no-interaction',
                    ],
                    base_path(),
                    timeout: null,
                );
                $processes[$upstream->code] = $process;
                $process->start(function (string $type, string $buffer) use ($upstream) {
                    $this->output->write('[' . $upstream->code . '] ' . $buffer, false, OutputInterface::OUTPUT_RAW);
                });
            }

            do {
                foreach ($processes as $code => $process) {
                    $alive = $process->isRunning();

                    if (!$running) {
                        break;
                    }

                    if (!$alive) {
                        $this->components->error(sprintf(
                            'Consumer [%s] exited with code %s.',
                            $code,
                            $process->getExitCode(),
                        ));

                        return self::FAILURE;
                    }

                    $process->clearOutput()->clearErrorOutput();
                }

                usleep(100_000);
            } while ($running);
        } finally {
            foreach ($processes as $process) {
                $process->stop(Config::integer('phpinnacle-ferry.events.shutdown_timeout'));
            }
        }

        return self::SUCCESS;
    }

    private function consume(Upstream $upstream, EloquentEventPublisher $publisher): int
    {
        if ($upstream->connector?->applied_config === null) {
            $this->components->error('Apply the upstream source connector before consuming events.');

            return self::FAILURE;
        }

        /** @var array{bootstrap_servers: string, group_id: string} $config */
        $config = Config::array('phpinnacle-ferry.events');
        /** @var array{models: list<class-string<Model>>} $source */
        $source = $upstream->config;
        $topics = [];

        foreach (EloquentModels::byTable($source['models']) as $table => $models) {
            $topics[$upstream->topicName($table)] = $models;
        }

        $conf = new Conf;
        $conf->set('bootstrap.servers', $config['bootstrap_servers']);
        $conf->set('group.id', $config['group_id'] . '.' . $upstream->id);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('enable.auto.offset.store', 'false');
        $conf->set('auto.offset.reset', 'earliest');

        $consumer = new KafkaConsumer($conf);
        $running = true;
        $this->trap([SIGTERM, SIGINT], function () use (&$running) {
            $running = false;
        });

        try {
            $consumer->subscribe(array_keys($topics));

            while ($running) {
                $message = $consumer->consume(1000);

                if (in_array($message->err, [RD_KAFKA_RESP_ERR__TIMED_OUT, RD_KAFKA_RESP_ERR__PARTITION_EOF], true)) {
                    continue;
                }

                if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    throw new RuntimeException($message->errstr());
                }

                if ($message->payload !== null) {
                    foreach ($topics[$message->topic_name] as $model) {
                        $publisher->publish($model, $message->payload);
                    }
                }

                $consumer->commit($message);
            }
        } finally {
            $consumer->close();
        }

        return self::SUCCESS;
    }
}
