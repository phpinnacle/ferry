<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Jason\Schema;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/ReplicaForm.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';

uses(TestCase::class);

it('keeps independent trim toggles when adding, editing and reopening empty string transforms', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        'name' => Schema::fromArray(['type' => 'string']),
        'note' => Schema::fromArray(['type' => 'string']),
    ]]);
    $page = Livewire::test(ReplicaForm::class, [
        'state' => [
            'upstream_id' => $upstream->id,
            'source' => 'object-0',
            'type' => DestinationType::Dynamic->value,
            'name' => 'Empty string transforms',
            'mapping' => [
                ['kind' => 'source', 'source' => '_idrref', 'target' => 'id', 'options' => []],
                ['kind' => 'source', 'source' => 'name', 'target' => 'name', 'options' => []],
                ['kind' => 'source', 'source' => 'note', 'target' => 'note', 'options' => []],
            ],
        ],
    ]);

    foreach (['name' => [], 'note' => ['trim' => true]] as $source => $params) {
        $mapping = $page->instance()->form->getComponentByStatePath('mapping');
        $path = 'mapping.' . $mapping->getSourceRowIndex($source) . '.options.transforms';
        $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
        $page->callAction($action->arguments(['block' => 'empty_string_to_null']), ['data' => $params])
            ->assertHasNoActionErrors();
        $page->call('$refresh');
    }

    $replica = $page->instance()->record;
    $replica->update($page->instance()->form->getState());
    $reopened = Livewire::test(ReplicaForm::class, ['record' => $replica->fresh(), 'state' => []]);
    $mapping = $reopened->instance()->form->getComponentByStatePath('mapping');

    foreach (['name' => false, 'note' => true] as $source => $trim) {
        $path = 'mapping.' . $mapping->getSourceRowIndex($source) . '.options.transforms';
        $editor = $reopened->instance()->form->getComponentByStatePath($path);
        expect($editor->getSettings())->toBe([
            ['type' => 'empty_string_to_null', 'data' => ['trim' => $trim]],
        ]);
        expect($editor->getInputSchema()->toArray())->toBe(['type' => ['string', 'null']]);
    }

    $path = 'mapping.' . $mapping->getSourceRowIndex('note') . '.options.transforms';
    $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
    $reopened->callAction($action->arguments(['block' => 'empty_string_to_null', 'index' => 0]), [
        'data' => ['trim' => false],
    ])->assertHasNoActionErrors();
    $reopened->call('$refresh');
    $replica->update($reopened->instance()->form->getState());
    expect($replica->fresh()->mapping[2]->transforms()[0]->data)->toBe(['trim' => false]);
});

it('saves independent money and radix settings and JSON output after Livewire updates', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        'amount' => Schema::fromArray(['type' => ['number', 'null']]),
        'balance' => Schema::fromArray(['type' => ['number', 'null']]),
    ]]);
    $page = Livewire::test(ReplicaForm::class, [
        'state' => [
            'upstream_id' => $upstream->id,
            'source' => 'object-0',
            'type' => DestinationType::Dynamic->value,
            'name' => 'Numeric transforms',
            'mapping' => [
                ['kind' => 'source', 'source' => '_idrref', 'target' => 'id', 'options' => []],
                ['kind' => 'source', 'source' => 'amount', 'target' => 'amount', 'options' => []],
                ['kind' => 'source', 'source' => 'balance', 'target' => 'balance', 'options' => []],
            ],
        ],
    ]);

    foreach (['amount' => ['RUB', 16], 'balance' => ['KWD', 36]] as $source => [$currency, $radix]) {
        $mapping = $page->instance()->form->getComponentByStatePath('mapping');
        $path = 'mapping.' . $mapping->getSourceRowIndex($source) . '.options.transforms';
        $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
        $page->callAction($action->arguments(['block' => 'money']), ['data' => ['currency' => $currency]])
            ->assertHasNoActionErrors();
        $page->call('$refresh');
        $page->callAction($action->arguments(['block' => 'radix']), ['data' => ['radix' => $radix]])
            ->assertHasNoActionErrors();
        $page->callAction($action->arguments(['block' => 'json']))->assertHasNoActionErrors();
    }

    $page->call('$refresh');
    $replica = $page->instance()->record;
    $replica->update($page->instance()->form->getState());
    $reopened = Livewire::test(ReplicaForm::class, ['record' => $replica->fresh(), 'state' => []]);
    $mapping = $reopened->instance()->form->getComponentByStatePath('mapping');

    foreach (['amount' => ['RUB', 16], 'balance' => ['KWD', 36]] as $source => [$currency, $radix]) {
        $path = 'mapping.' . $mapping->getSourceRowIndex($source) . '.options.transforms';
        $editor = $reopened->instance()->form->getComponentByStatePath($path);
        expect($editor->getSettings())->toBe([
            ['type' => 'money', 'data' => ['currency' => $currency]],
            ['type' => 'radix', 'data' => ['radix' => $radix]],
            ['type' => 'json', 'data' => []],
        ]);
        expect($editor->getInputSchema()->toArray())->toBe(['type' => ['string', 'null']]);
    }
});

it('updates parent mapping output after native dictionary changes and Livewire refreshes', function () {
    Queue::fake();
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['active' => Schema::fromArray(['type' => 'boolean'])]]);
    $page = Livewire::test(ReplicaForm::class, [
        'state' => [
            'upstream_id' => $upstream->id,
            'source' => 'object-0',
            'type' => DestinationType::Static->value,
            'destination' => 'customers',
            'name' => 'Dictionary replica',
            'mapping' => [
                ['kind' => 'source', 'source' => '_idrref', 'target' => 'customer_id', 'options' => []],
                ['kind' => 'static', 'target' => 'name', 'static' => 'Customer', 'options' => []],
                ['kind' => 'static', 'target' => 'tax_number', 'static' => '123', 'options' => []],
            ],
        ],
    ]);
    $mapping = $page->instance()->form->getComponentByStatePath('mapping');
    $path = 'mapping.' . $mapping->getSourceRowIndex('active') . '.options.transforms';
    $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
    $page->callAction($action->arguments(['block' => 'boolean_to_string']), ['data' => [
        'true' => ' yes ',
        'false' => '',
    ]])->assertHasNoActionErrors();
    $page->callAction($action->arguments(['block' => 'string_map']), ['data' => ['mapping' => [' yes ' => '']]])
        ->assertHasNoActionErrors();
    $page->call('$refresh');

    $mapping = $page->instance()->form->getComponentByStatePath('mapping');
    expect($mapping->getOutputSchema()->toArray()['properties']['active'])->toBe([
        'type' => 'string',
        'enum' => [''],
        'minLength' => 0,
        'maxLength' => 0,
    ]);

    $replica = $page->instance()->record;
    $replica->update($page->instance()->form->getState());
    $reopened = Livewire::test(ReplicaForm::class, ['record' => $replica->fresh(), 'state' => []]);
    expect(
        $reopened
            ->instance()
            ->form
            ->getComponentByStatePath('mapping')
            ->getOutputSchema()
            ->toArray()['properties']['active'],
    )
        ->toBe(['type' => 'string', 'enum' => [''], 'minLength' => 0, 'maxLength' => 0]);
});

it('hydrates, edits and reorders native replacement dictionaries after Livewire updates', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['active' => Schema::fromArray(['type' => ['boolean', 'null']])]]);
    $page = Livewire::test(ReplicaForm::class, ['state' => [
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
    ]]);
    $field = $page->instance()->form->getComponentByStatePath('mapping');
    $path = 'mapping.' . $field->getSourceRowIndex('active') . '.options.transforms';
    $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
    $page->callAction($action->arguments(['block' => 'toggle']))->assertHasNoActionErrors();
    $page->callAction($action->arguments(['block' => 'boolean_to_string']), ['data' => [
        'true' => 'yes',
        'false' => 'no',
    ]])
        ->assertHasNoActionErrors();
    $page->callAction($action->arguments(['block' => 'string_map']), [
        'data' => ['mapping' => ['yes' => 'active', 'no' => '']],
    ])
        ->assertHasNoActionErrors();
    $page->call('$refresh');
    $editor = $page->instance()->form->getComponentByStatePath($path);
    expect($editor->getInputSchema()->toArray())->toBe([
        'type' => ['string', 'null'],
        'enum' => ['active', '', null],
        'minLength' => 0,
        'maxLength' => 6,
    ]);
    expect($editor->getSettings()[2]['data'])->toBe(['mapping' => ['yes' => 'active', 'no' => '']]);

    $page->callAction($action->arguments(['block' => 'string_map']), ['data' => ['mapping' => ['active' => ' ready ']]])
        ->assertHasNoActionErrors();
    $page->callAction($action->arguments(['block' => 'string_map', 'index' => 3]), [
        'data' => ['mapping' => ['active' => '  ']],
    ])
        ->assertHasNoActionErrors();
    $page->call('$refresh');
    $editor = $page->instance()->form->getComponentByStatePath($path);
    expect($editor->getInputSchema()->toArray())->toBe([
        'type' => ['string', 'null'],
        'enum' => ['  ', '', null],
        'minLength' => 0,
        'maxLength' => 2,
    ]);
    expect($editor->getContainer()->getState()['transforms'])->toBe([
        ['type' => 'toggle', 'data' => []],
        ['type' => 'boolean_to_string', 'data' => ['true' => 'yes', 'false' => 'no']],
        ['type' => 'string_map', 'data' => ['mapping' => ['yes' => 'active', 'no' => '']]],
        ['type' => 'string_map', 'data' => ['mapping' => ['active' => '  ']]],
    ]);

    $page->callAction($action->arguments(['order' => [0, 1, 3, 2]]))->assertHasNoActionErrors();
    expect($page->instance()->form->getComponentByStatePath($path)->getInputSchema()->toArray())
        ->toBe(['type' => ['string', 'null'], 'enum' => ['active', '', null], 'minLength' => 0, 'maxLength' => 6]);
});
