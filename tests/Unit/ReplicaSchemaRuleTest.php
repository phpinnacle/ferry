<?php

use Illuminate\Support\Facades\Validator;
use PHPinnacle\Ferry\Rules\ReplicaSchema;
use Tests\TestCase;

uses(TestCase::class);

$validate = function (mixed $columns) {
    $validator = Validator::make(
        ['mapping' => $columns],
        ['mapping' => [
            'array',
            fn (string $attribute, mixed $value, Closure $fail) => ReplicaSchema::validateColumns($value, $fail),
        ]],
    );

    return $validator->errors()->first('mapping') !== ''
        ? $validator->errors()->first('mapping')
        : null;
};

it('accepts valid target columns', function () use ($validate) {
    expect($validate(['external_id', 'title', 'idrref']))->toBeNull();
});

it('requires at least one mapped column', function () use ($validate) {
    expect($validate([]))->toBe(__('phpinnacle-ferry::validation.replica_schema.empty'));
});

it('requires non-empty string column names', function (mixed $column) use ($validate) {
    expect($validate([$column]))->toBe(__('phpinnacle-ferry::validation.replica_schema.column_required'));
})->with([
    'empty' => [''],
    'integer' => [42],
]);

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

it('requires a declared source primary key for mappings', function () {
    $validator = Validator::make(['mapping' => ['record_id']], ['mapping' => [
        fn (string $attribute, mixed $value, Closure $fail) => ReplicaSchema::validatePrimaryKey([], $value, $fail),
    ]]);

    expect($validator->errors()->first('mapping'))
        ->toBe(__('phpinnacle-ferry::validation.replica_schema.primary_key_missing'));
});
