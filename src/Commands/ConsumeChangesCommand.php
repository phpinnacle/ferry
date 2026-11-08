<?php

namespace PHPinnacle\Ferry\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Services\EloquentEventPublisher;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RuntimeException;

class ConsumeChangesCommand extends Command
{
    protected $signature = 'ferry:consume';

    protected $description = 'Read Debezium JSON topics and publish Eloquent model events';

    public function handle(EloquentEventPublisher $publisher): int
    {
        /** @var array{bootstrap_servers: string, group_id: string, topics: array<string, class-string<Model>>} $config */
        $config = Config::array('phpinnacle-ferry.kafka.events');

        if ($config['topics'] === []) {
            $this->components->info('No model topics configured.');

            return self::SUCCESS;
        }

        $conf = new Conf;
        $conf->set('bootstrap.servers', $config['bootstrap_servers']);
        $conf->set('group.id', $config['group_id']);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('enable.auto.offset.store', 'false');
        $conf->set('auto.offset.reset', 'earliest');

        $consumer = new KafkaConsumer($conf);
        $running = true;
        $this->trap([SIGTERM, SIGINT], function () use (&$running) {
            $running = false;
        });

        try {
            $consumer->subscribe(array_keys($config['topics']));

            while ($running) {
                $message = $consumer->consume(1000);

                if (in_array($message->err, [RD_KAFKA_RESP_ERR__TIMED_OUT, RD_KAFKA_RESP_ERR__PARTITION_EOF], true)) {
                    continue;
                }

                if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    throw new RuntimeException($message->errstr());
                }

                if ($message->payload !== null) {
                    // shortcut: JSON envelopes with raw database attributes; Avro and logical-type decoding are not implemented.
                    $publisher->publish($config['topics'][$message->topic_name], $message->payload);
                }

                $consumer->commit($message);
            }
        } finally {
            $consumer->close();
        }

        return self::SUCCESS;
    }
}
