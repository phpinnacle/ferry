<?php

use Illuminate\Support\Facades\Validator;
use PHPinnacle\Ferry\Rules\ReplicaSchema;
use Tests\TestCase;

uses(TestCase::class);

$validate = function (mixed $columns) {
    $validator = Validator::make(
        ['schema' => $columns],
        ['schema' => [
            'array',
            fn (string $attribute, mixed $value, Closure $fail) => ReplicaSchema::validateColumns($value, $fail),
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
    expect($validate([]))->toBe(__('phpinnacle-ferry::validation.replica_schema.empty'));
});

it('forbids reusing a column name', function () use ($validate) {
    expect($validate(['title', 'title']))
        ->toBe(__('phpinnacle-ferry::validation.replica_schema.column_duplicate', ['column' => 'title']));
});

it('accepts only snake case latin column names', function (string $column) use ($validate) {
    expect($validate([$column]))
        ->toBe(__('phpinnacle-ferry::validation.replica_schema.column_format', ['column' => $column]));
})->with([
    'uppercase' => 'Title',
    'leading digit' => '1title',
    'hyphen' => 'column-name',
    'space' => 'column name',
]);

it('allows the former technical column name', function () use ($validate) {
    expect($validate(['idrref']))->toBeNull();
});

it('requires a declared source primary key for mappings', function () {
    $validator = Validator::make(['schema' => ['record_id']], ['schema' => [
        fn (string $attribute, mixed $value, Closure $fail) => ReplicaSchema::validatePrimaryKey([], $value, $fail),
    ]]);

    expect($validator->errors()->first('schema'))
        ->toBe(__('phpinnacle-ferry::validation.replica_schema.primary_key_missing'));
});
