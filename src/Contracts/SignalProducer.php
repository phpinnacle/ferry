<?php

namespace PHPinnacle\Ferry\Contracts;

interface SignalProducer
{
    public function publish(string $topic, string $key, string $payload): void;
}
