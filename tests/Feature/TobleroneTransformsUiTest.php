<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm;
use PHPinnacle\Ferry\Tests\TestCase;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/ReplicaForm.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';

uses(TestCase::class);

it('updates parent mapping output after native dictionary changes and Livewire refreshes', function () {
    Queue::fake();
    app(DestinationFactory::class)->register(customers_destination());
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['active' => JsonSchema::fromArray(['type' => 'boolean'])]]);
    $page = Livewire::test(ReplicaForm::class, ['state' => [
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'static:customers',
    ]]);
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
    expect($mapping->getOutputSchema()['properties']['active'])->toBe([
        'type' => 'string',
        'enum' => [''],
        'minLength' => 0,
        'maxLength' => 0,
    ]);

    $savedTransforms = $page->instance()->form->getComponentByStatePath($path)->getSettings();
    $reopened = Livewire::test(ReplicaForm::class, [
        'state' => [
            'upstream_id' => $upstream->id,
            'source' => 'object-0',
            'destination' => 'static:customers',
            'mapping' => [[
                'kind' => 'source',
                'source' => 'active',
                'target' => null,
                'options' => ['transforms' => $savedTransforms],
            ]],
        ],
    ]);
    expect($reopened->instance()->form->getComponentByStatePath('mapping')->getOutputSchema()['properties']['active'])
        ->toBe(['type' => 'string', 'enum' => [''], 'minLength' => 0, 'maxLength' => 0]);
});

it('hydrates, edits and reorders native replacement dictionaries after Livewire updates', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['active' => JsonSchema::fromArray(['type' => ['boolean', 'null']])]]);
    $page = Livewire::test(ReplicaForm::class, ['state' => [
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => DestinationType::Dynamic->value,
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
