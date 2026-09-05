<?php

use PHPinnacle\Ferry\Models\Model;
use PHPinnacle\Ferry\Resources\Connections\ConnectionResource;
use Tests\TestCase;

uses(TestCase::class);

it('preserves nullable connection and navigation configuration', function () {
    config()->set('phpinnacle-ferry.connection', null);
    config()->set('phpinnacle-ferry.navigation.connection', ['icon' => null, 'sort' => null]);

    $model = new Model;
    $model->setConnection('model-default');

    expect($model->getConnectionName())
        ->toBeNull()
        ->and(ConnectionResource::getNavigationIcon())
        ->toBeNull()
        ->and(ConnectionResource::getNavigationSort())
        ->toBeNull();

    config()->set('phpinnacle-ferry.connection', 'ferry');
    config()->set('phpinnacle-ferry.navigation.connection', ['icon' => 'phosphor-plugs', 'sort' => 0]);

    expect($model->getConnectionName())
        ->toBe('ferry')
        ->and(ConnectionResource::getNavigationIcon())
        ->toBe('phosphor-plugs')
        ->and(ConnectionResource::getNavigationSort())
        ->toBe(0);

    config()->set('phpinnacle-ferry', []);

    expect($model->getConnectionName())->toBe('model-default');
});
