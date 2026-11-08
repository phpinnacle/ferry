<?php

use Tests\TestCase;

uses(TestCase::class);

it('finishes without opening Kafka when no model topics are configured', function () {
    config(['phpinnacle-ferry.kafka.events.topics' => []]);

    $this->artisan('ferry:consume')->expectsOutputToContain('No model topics configured.')->assertSuccessful();
});
