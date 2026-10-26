<?php

namespace PHPinnacle\Ferry\Origins\Kafka\Contracts;

interface SignalProducer
{
    public function publish(string $topic, string $key, string $payload): void;
}
