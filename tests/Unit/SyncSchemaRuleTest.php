<?php

use Illuminate\Support\Facades\Validator;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Rules\SyncSchema;
use Tests\TestCase;

uses(TestCase::class);

$validate = function (mixed $columns) {
    $validator = Validator::make(
        ['schema' => $columns],
        ['schema' => [
            'array',
            fn (string $attribute, mixed $value, Closure $fail) => SyncSchema::validateColumns($value, $fail),
        ]],
    );

    return $validator->errors()->first('schema') !== ''
        ? $validator->errors()->first('schema')
        : null;
};

it('accepts valid target columns', function () use ($validate) {
    expect($validate(['external_id', 'title']))->toBeNull();
});

it('requires at least one mapped column', function () use ($validate) {
    expect($validate([]))->toBe(__('phpinnacle-ferry::validation.sync_schema.empty'));
});

it('forbids reusing a column name', function () use ($validate) {
    expect($validate(['title', 'title']))
        ->toBe(__('phpinnacle-ferry::validation.sync_schema.column_duplicate', ['column' => 'title']));
});

it('accepts only snake case latin column names', function (string $column) use ($validate) {
    expect($validate([$column]))
        ->toBe(__('phpinnacle-ferry::validation.sync_schema.column_format', ['column' => $column]));
})->with([
    'uppercase' => 'Title',
    'leading digit' => '1title',
    'hyphen' => 'column-name',
    'space' => 'column name',
]);

it('forbids reserved technical column names', function () use ($validate) {
    expect($validate([Sync::ID_COLUMN]))
        ->toBe(__(
            'phpinnacle-ferry::validation.sync_schema.column_reserved',
            ['column' => Sync::ID_COLUMN],
        ));
});
