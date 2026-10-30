<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPinnacle\Eidos\Forms\JsonSchemaPicker;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Jason\SchemaBuilder;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/ReplicaForm.php';

uses(TestCase::class);

it('adds and persists a slug through the tabbed builder in both editors', function (DestinationType $type) {
    Queue::fake();
    app(DestinationFactory::class)->register(new StaticDestination(
        'slugs',
        'Slugs',
        'slugs',
        ['id'],
        SchemaBuilder::make(Schema::object([
            'id' => SchemaBuilder::make()->type('string')->format('uuid')->build(),
            'slug' => Schema::union(['string', 'null']),
        ]))
            ->required(['id', 'slug'])
            ->build(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'sources' => [
            'name' => ['type' => ['string', 'null']],
            'rank' => ['type' => 'integer'],
        ],
    ]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, [
        'state' => [
            'name' => 'Slug replica',
            'code' => 'slugs',
            'upstream_id' => $upstream->id,
            'source' => 'object-0',
            'destination' => $type === DestinationType::Static ? 'static:slugs' : 'dynamic',
            'mapping' => [['kind' => 'source', 'source' => '_idrref', 'target' => 'id', 'options' => []]],
        ],
    ]);

    $page->callAction(TestAction::make('add')->schemaComponent('transforms', 'form'), arguments: ['block' => 'slug'])
        ->assertHasNoErrors();
    $item = array_key_first($page->get('data.transforms'));
    $html = $page->effects['html'] ?? implode('', $page->effects['partials'] ?? []);
    expect($html)->toContain('wire:model.live.blur="data.transforms.' . $item . '.data.output"');
    $picker = $page
        ->instance()
        ->form
        ->getComponentByStatePath('transforms')
        ->getChildSchema($item)
        ->getComponentByStatePath('input');
    expect($picker)->toBeInstanceOf(JsonSchemaPicker::class);
    expect($picker->isMultiple())->toBeFalse();
    $page
        ->set('data.transforms.' . $item . '.data.input', "$['name']")
        ->assertSee('slug');
    $html = $page->effects['html'] ?? implode('', $page->effects['partials'] ?? []);
    $sourceAttribute = $type === DestinationType::Static ? 'data-mapping-source-settings' : 'data-binding-source';
    expect($html)->toContain($sourceAttribute . '="slug"');
    $page->set('data.transforms.' . $item . '.data.separators', '.');

    expect($page->get('data.schema.properties'))->not->toHaveKey('slug');
    expect(array_values($page->get('data.transforms')))->toBe([
        ['type' => 'slug', 'data' => ['input' => "$['name']", 'output' => 'slug', 'separators' => '.']],
    ]);
    $mapping = $page->instance()->form->getComponentByStatePath('mapping');
    expect($mapping->getSourceSchema()['properties']['slug'])->toBe(['type' => ['string', 'null']]);
    expect(array_column($mapping->getSources(), 'id'))->toBe(['_idrref', 'name', 'slug', 'rank']);
    $page->set('data.mapping.' . $mapping->getSourceRowIndex('slug') . '.target', 'slug');
    $page->set(
        'data.mapping',
        array_values(array_filter(
            $page->get('data.mapping'),
            fn (array $row) => $row['kind'] !== 'static' || $row['target'] !== 'slug',
        )),
    );
    $state = $page->instance()->form->getState();
    $replica = Replica::create(TestCase::mappedReplicaAttributes($state, app(DestinationFactory::class)))->fresh();
    expect(collect($replica->mapping)->firstWhere('source', 'slug')->target)->toBe('slug');
    expect($replica->schema->properties())->not->toHaveKey('slug');
    expect($replica->transformedSchema()->properties()['slug']->types())->toBe(['string', 'null']);
    expect($replica->outputSchema(new FieldMapping('slug', 'slug'))->types())->toBe(['string', 'null']);
    expect(new SourceFactory()->source($upstream, config('phpinnacle-ferry.origins.kafka'))['column.include.list'])
        ->toBe('public._reference0._idrref,public._reference0.name');
    expect(ReplicaForm::fill($replica->toArray())['transforms'])->toBe($state['transforms']);
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('rejects invalid slug settings in the native record tab', function (
    array $data,
    array $errors,
) {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['sources' => ['name' => ['type' => 'string'], 'rank' => ['type' => 'integer']]]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\ReplicaForm::class, ['state' => [
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'dynamic',
    ]]);

    $page
        ->set('data.transforms', ['slug' => ['type' => 'slug', 'data' => $data]])
        ->call('validateForm')
        ->assertHasErrors(array_combine(
            array_map(fn (string $field) => 'data.transforms.slug.data.' . $field, array_keys($errors)),
            array_values($errors),
        ));
    expect(Replica::query()->count())->toBe(0);
})->with([
    'unknown input' => [
        ['input' => "$['missing']", 'output' => 'slug'],
        ['input' => \PHPinnacle\Eidos\Validation\ValidPath::class],
    ],
    'nontext input' => [
        ['input' => "$['rank']", 'output' => 'slug'],
        ['input' => \PHPinnacle\Eidos\Validation\ValidPath::class],
    ],
    'existing output' => [['input' => "$['name']", 'output' => 'name'], ['output' => 'not_in']],
    'invalid name' => [['input' => "$['name']", 'output' => 'Bad name'], ['output' => 'regex']],
    'invalid separators' => [
        ['input' => "$['name']", 'output' => 'slug', 'separators' => []],
        ['separators' => 'string'],
    ],
]);
