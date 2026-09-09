<?php

use Illuminate\Support\Facades\Validator;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\SyncTableManager;
use Tests\TestCase;

uses(TestCase::class);

$validate = function (mixed $schema) {
    $validator = Validator::make(
        ['schema' => $schema],
        ['schema' => [new SyncSchema]],
    );

    return $validator->errors()->first('schema') !== ''
        ? $validator->errors()->first('schema')
        : null;
};

it('accepts a valid field mapping', function () use ($validate) {
    expect($validate([
        ['source' => '_idrref', 'column' => 'external_id'],
        ['source' => 'property-1', 'column' => 'title'],
    ]))->toBeNull();
});

it('rejects a mapping that is not a list of rows', function () use ($validate) {
    expect($validate('external_id'))->toBe(__('phpinnacle-ferry::validation.sync_schema.format'));
});

it('requires at least one mapped column', function () use ($validate) {
    expect($validate([]))->toBe(__('phpinnacle-ferry::validation.sync_schema.empty'));
});

it('requires a source field in every row', function () use ($validate) {
    expect($validate([['column' => 'title']]))
        ->toBe(__('phpinnacle-ferry::validation.sync_schema.source_required'));
});

it('requires a column name in every row', function () use ($validate) {
    expect($validate([['source' => '_idrref']]))
        ->toBe(__('phpinnacle-ferry::validation.sync_schema.column_required'));
});

it('forbids mapping the same source field twice', function () use ($validate) {
    expect($validate([
        ['source' => '_idrref', 'column' => 'external_id'],
        ['source' => '_idrref', 'column' => 'row_id'],
    ]))
        ->toBe(__('phpinnacle-ferry::validation.sync_schema.source_duplicate', ['field' => '_idrref']));
});

it('forbids reusing a column name', function () use ($validate) {
    expect($validate([
        ['source' => '_idrref', 'column' => 'title'],
        ['source' => 'property-1', 'column' => 'title'],
    ]))
        ->toBe(__('phpinnacle-ferry::validation.sync_schema.column_duplicate', ['column' => 'title']));
});

it('accepts only snake case latin column names', function (string $column) use ($validate) {
    expect($validate([['source' => '_idrref', 'column' => $column]]))
        ->toBe(__('phpinnacle-ferry::validation.sync_schema.column_format', ['column' => $column]));
})->with([
    'uppercase' => 'Title',
    'leading digit' => '1title',
    'hyphen' => 'column-name',
    'space' => 'column name',
]);

it('forbids reserved technical column names', function () use ($validate) {
    expect($validate([['source' => '_idrref', 'column' => SyncTableManager::ID_COLUMN]]))
        ->toBe(__(
            'phpinnacle-ferry::validation.sync_schema.column_reserved',
            ['column' => SyncTableManager::ID_COLUMN],
        ));
});
