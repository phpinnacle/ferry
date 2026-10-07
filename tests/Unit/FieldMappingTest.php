<?php

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPinnacle\Ferry\Forms\FieldMapping;
use PHPinnacle\Ferry\Tests\Fixtures\CustomerMappingField;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/CustomerMappingField.php';

uses(TestCase::class);

dataset('field mapping mappingValues', [
    'empty mapping' => [[], true],
    'valid mapping' => [['title' => 'name'], true],
    'source reused without permission' => [['title' => 'name', 'contact' => 'name'], false],
    'unknown target' => [['missing' => 'name'], false],
    'unknown source' => [['title' => 'missing'], false],
    'numeric source' => [['title' => 0], false],
    'nested value' => [['title' => ['name']], false],
    'scalar mapping' => ['name', false],
    'sequential mapping' => [['name', 'email'], false],
]);

dataset('field mapping typePairs', [
    'equal' => ['string', 'string', true],
    'different' => ['string', 'boolean', false],
    'untyped source' => [null, 'boolean', true],
    'untyped destination' => ['string', null, true],
    'both untyped' => [null, null, true],
    'type names are exact' => ['String', 'string', false],
]);

dataset('field mapping orientedMappingValues', [
    'forward multiple sources' => [false, ['payload' => ['id', 'name']], true],
    'forward one source still uses a list' => [false, ['payload' => ['id']], true],
    'forward single destination' => [false, ['enabled' => 'active'], true],
    'forward scalar for multiple destination' => [false, ['payload' => 'id'], false],
    'forward list for single destination' => [false, ['enabled' => ['active']], false],
    'forward empty list' => [false, ['payload' => []], false],
    'forward associative list' => [false, ['payload' => ['field' => 'id']], false],
    'forward nested list' => [false, ['payload' => [['id']]], false],
    'forward numeric source' => [false, ['payload' => [42]], false],
    'forward duplicate within list' => [false, ['payload' => ['id', 'id']], false],
    'forward reused source across destinations' => [false, ['payload' => ['id'], 'enabled' => 'id'], false],
    'forward unknown source in list' => [false, ['payload' => ['unknown']], false],
    'inverse multiple sources' => [true, ['id' => 'payload', 'name' => 'payload'], true],
    'inverse one source' => [true, ['id' => 'payload'], true],
    'inverse single destination' => [true, ['active' => 'enabled'], true],
    'inverse reused single destination' => [true, ['id' => 'enabled', 'name' => 'enabled'], false],
    'inverse list value' => [true, ['id' => ['payload']], false],
    'inverse numeric destination' => [true, ['id' => 42], false],
    'inverse unknown source' => [true, ['unknown' => 'payload'], false],
    'inverse unknown destination' => [true, ['id' => 'unknown'], false],
    'inverse scalar state' => [true, 'payload', false],
]);

it('oriented state preserves cardinality and rejects malformed values', function (
    bool $inverse,
    mixed $value,
    bool $valid,
) {
    $field = FieldMapping::make('mapping')
        ->options(source: ['id', 'name', 'active'], dest: ['payload', 'enabled'])
        ->multiple(['payload'])
        ->inverse($inverse);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));

    $this->assertSame(
        $valid,
        $validator->make(['mapping' => $value], ['mapping' => $field->getValidationRules()])->passes(),
    );
})->with('field mapping orientedMappingValues');

it('required multiple destinations are checked in both orientations', function () {
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));

    foreach ([false, true] as $inverse) {
        $field = FieldMapping::make('mapping')
            ->options(source: ['id', 'name'], dest: ['payload', 'optional'])
            ->multiple(['payload'])
            ->requiredTargets(['payload'])
            ->inverse($inverse);
        $valid = $inverse ? ['id' => 'payload', 'name' => 'payload'] : ['payload' => ['id', 'name']];
        $missing = $inverse ? ['id' => 'optional'] : ['optional' => 'id'];

        $this->assertTrue(
            $validator->make(['mapping' => $valid], ['mapping' => $field->getValidationRules()])->passes(),
        );
        $this->assertFalse(
            $validator->make(['mapping' => $missing], ['mapping' => $field->getValidationRules()])->passes(),
        );
        $this->assertFalse($validator->make(['mapping' => []], ['mapping' => $field->getValidationRules()])->passes());
    }
});

it('plain strings use their values as identifiers', function () {
    $field = FieldMapping::make('mapping')->options(source: ['name', 'email'], dest: ['title']);

    $this->assertSame(['name', 'email'], array_column($field->getSources(), 'id'));
    $this->assertSame(['name', 'email'], array_column($field->getSources(), 'label'));
    $this->assertSame(['title'], array_column($field->getTargets(), 'id'));
    $this->assertNull($field->getSources()[0]['icon']);
    $this->assertNull($field->getSources()[0]['description']);
});

it('associative keys are identifiers even when labels match', function () {
    $field = FieldMapping::make('mapping')->options(source: ['first' => 'Name', 'second' => 'Name']);

    $this->assertSame(['first', 'second'], array_column($field->getSources(), 'id'));
    $this->assertSame(['Name', 'Name'], array_column($field->getSources(), 'label'));
});

it('enum lists use backing values and all three contracts', function () {
    $field = FieldMapping::make('mapping')->options(dest: CustomerMappingField::cases());
    $first = $field->getTargets()[0];

    $this->assertSame('name', $first['id']);
    $this->assertSame('Customer name', $first['label']);
    $this->assertSame(Heroicon::OutlinedUser, $first['icon']);
    $this->assertSame('The name displayed on the customer profile.', $first['description']);
});

it('regular objects preserve htmlable metadata and explicit keys', function () {
    $option = new class implements HasDescription, HasIcon, HasLabel {
        public function getLabel(): HtmlString
        {
            return new HtmlString('<strong>Name</strong>');
        }

        public function getIcon(): Heroicon
        {
            return Heroicon::OutlinedUser;
        }

        public function getDescription(): HtmlString
        {
            return new HtmlString('<em>Customer name</em>');
        }
    };
    $field = FieldMapping::make('mapping')->options(source: ['customer_name' => $option], dest: [$option]);

    $this->assertSame('customer_name', $field->getSources()[0]['id']);
    $this->assertSame('0', $field->getTargets()[0]['id']);
    $this->assertEquals($option->getLabel(), $field->getSources()[0]['label']);
    $this->assertEquals($option->getDescription(), $field->getSources()[0]['description']);
    $this->assertSame($option->getIcon(), $field->getSources()[0]['icon']);
});

it('missing optional contracts and null labels fall back to the key', function () {
    $option = new class implements HasLabel {
        public function getLabel(): ?string
        {
            return null;
        }
    };
    $field = FieldMapping::make('mapping')->options(source: ['name' => $option, 'id' => new \stdClass]);

    $this->assertSame(['name', 'id'], array_column($field->getSources(), 'label'));
    $this->assertNull($field->getSources()[0]['icon']);
    $this->assertNull($field->getSources()[0]['description']);
});

it('mapping validation rejects unknown identifiers and malformed values', function (mixed $value, bool $valid) {
    $field = FieldMapping::make('mapping')->options(source: ['name', 'email'], dest: ['title', 'contact']);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));

    $this->assertSame(
        $valid,
        $validator->make(['mapping' => $value], ['mapping' => $field->getValidationRules()])->passes(),
    );
})->with('field mapping mappingValues');

it('required destinations cannot be omitted even in an empty mapping', function () {
    $field = FieldMapping::make('mapping')
        ->options(source: ['email'], dest: ['contact', 'optional'])
        ->requiredTargets(['contact']);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));

    foreach ([null, [], ['optional' => 'email']] as $value) {
        $this->assertFalse(
            $validator->make(['mapping' => $value], ['mapping' => $field->getValidationRules()])->passes(),
        );
    }

    $this->assertFalse($validator->make([], ['mapping' => $field->getValidationRules()])->passes());
    $this->assertTrue(
        $validator->make(['mapping' => ['contact' => 'email']], ['mapping' => $field->getValidationRules()])->passes(),
    );
});

it('missing required destination uses a plain label', function () {
    $option = new class implements HasLabel {
        public function getLabel(): HtmlString
        {
            return new HtmlString('<strong>Contact</strong>');
        }
    };
    $field = FieldMapping::make('mapping')
        ->options(source: ['email'], dest: ['contact' => $option, 'name' => 'Name'])
        ->requiredTargets(['contact', 'name']);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));
    $result = $validator->make(['mapping' => ['name' => 'email']], ['mapping' => $field->getValidationRules()]);

    $this->assertSame(['Connect the required destination: Contact.'], $result->errors()->get('mapping'));
});

it('numeric identifiers keep their string identity and multiple permission', function () {
    $field = FieldMapping::make('mapping')
        ->options(source: ['0', '00'], dest: ['1', '2', '3'])
        ->multiple(['1'])
        ->requiredTargets(['1']);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));

    $this->assertSame(['0', '00'], array_column($field->getSources(), 'id'));
    $this->assertSame(['1'], $field->getMultipleTargets());
    $this->assertTrue(
        $validator->make(['mapping' => ['1' => ['0', '00']]], ['mapping' => $field->getValidationRules()])->passes(),
    );
    $this->assertFalse(
        $validator
            ->make(['mapping' => ['1' => ['00'], '2' => '00']], ['mapping' => $field->getValidationRules()])
            ->passes(),
    );

    $field->inverse();
    $this->assertTrue(
        $validator
            ->make(['mapping' => ['0' => '1', '00' => '1']], ['mapping' => $field->getValidationRules()])
            ->passes(),
    );
    $this->assertTrue(
        $validator
            ->make(['mapping' => ['0' => '1', '00' => '2']], ['mapping' => $field->getValidationRules()])
            ->passes(),
    );
});

it('only explicitly multiple destinations accept source lists', function () {
    $multiple = ['title'];
    $field = FieldMapping::make('mapping')
        ->options(source: ['name', 'email', 'id'], dest: ['title', 'contact', 'other'])
        ->multiple(function () use (&$multiple) {
            return $multiple;
        });
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));

    $this->assertTrue(
        $validator
            ->make(['mapping' => ['title' => ['name', 'email'], 'other' => 'id']], [
                'mapping' => $field->getValidationRules(),
            ])
            ->passes(),
    );
    $this->assertFalse(
        $validator
            ->make(['mapping' => ['contact' => ['name', 'email']]], ['mapping' => $field->getValidationRules()])
            ->passes(),
    );

    $multiple = [];
    $this->assertFalse(
        $validator
            ->make(['mapping' => ['title' => ['name', 'email']]], ['mapping' => $field->getValidationRules()])
            ->passes(),
    );
});

it('type compatibility is validated on the server', function (?string $sourceType, ?string $targetType, bool $valid) {
    $field = FieldMapping::make('mapping')
        ->options(source: ['source'], dest: ['target'])
        ->types(
            source: $sourceType === null ? [] : ['source' => $sourceType],
            dest: $targetType === null ? [] : ['target' => $targetType],
        );
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));

    foreach ([false, true] as $inverse) {
        $field->inverse($inverse);

        foreach ([false, true] as $multiple) {
            $field->multiple($multiple ? ['target'] : []);
            $value = $inverse ? ['source' => 'target'] : ['target' => $multiple ? ['source'] : 'source'];
            $this->assertSame(
                $valid,
                $validator->make(['mapping' => $value], ['mapping' => $field->getValidationRules()])->passes(),
            );
        }
    }
})->with('field mapping typePairs');
