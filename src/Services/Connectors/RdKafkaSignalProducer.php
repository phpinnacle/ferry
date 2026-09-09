<?php

namespace PHPinnacle\Ferry\Services\Connectors;

use PHPinnacle\Ferry\Contracts\SignalProducer;
use RdKafka\Conf;
use RdKafka\Producer;
use RuntimeException;

class RdKafkaSignalProducer implements SignalProducer
{
    private readonly Producer $producer;

    public function __construct(
        string $bootstrapServers,
        private readonly int $flushTimeout,
    ) {
        $conf = new Conf;
        $conf->set('bootstrap.servers', $bootstrapServers);

        $this->producer = new Producer($conf);
    }

    public function publish(string $topic, string $key, string $payload): void
    {
        $this->producer->newTopic($topic)->producev(RD_KAFKA_PARTITION_UA, 0, $payload, $key);
        $this->producer->poll(0);

        $result = $this->producer->flush($this->flushTimeout);

        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new RuntimeException(__('phpinnacle-ferry::resources.connection.errors.signal_failed', [
                'error' => rd_kafka_err2str($result),
            ]));
        }
    }
}
