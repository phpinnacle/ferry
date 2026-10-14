<?php

namespace PHPinnacle\Ferry\Transforms;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class FilterTransform implements Transform
{
    public const string KEY = 'filter';

    private const array MODES = ['include', 'exclude'];

    private const array TYPES = [ColumnType::String, ColumnType::Reference, ColumnType::Boolean];

    public string $key {
        get => self::KEY;
    }

    public TransformScope $scope {
        get => TransformScope::Record;
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::resources.transform.filter');
    }

    public function schema(?ConnectionMetadata $object): array
    {
        return [
            Select::make('mode')
                ->label(__('phpinnacle-ferry::resources.transform.filter_mode'))
                ->options([
                    'exclude' => __('phpinnacle-ferry::resources.transform.filter_exclude'),
                    'include' => __('phpinnacle-ferry::resources.transform.filter_include'),
                ])
                ->default('exclude')
                ->required(),
            Select::make('column')
                ->label(__('phpinnacle-ferry::resources.transform.filter_column'))
                ->options(array_map(
                    static fn (array $field) => sprintf('%s (%s)', $field['title'], $field['physical']),
                    self::fields($object),
                ))
                ->searchable()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('value', null))
                ->required(),
            Select::make('value')
                ->key('value_flag')
                ->label(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                ->options([
                    'true' => __('phpinnacle-ferry::resources.transform.filter_true'),
                    'false' => __('phpinnacle-ferry::resources.transform.filter_false'),
                ])
                ->visible(fn (Get $get) => self::isFlag($object, $get->string('column', isNullable: true)))
                ->required(),
            TextInput::make('value')
                ->key('value_text')
                ->label(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                ->visible(fn (Get $get) => !self::isFlag($object, $get->string('column', isNullable: true)))
                ->required(),
        ];
    }

    public function normalize(array $params, ConnectionMetadata $object): array
    {
        $field =
            array_column($object->mappableFields(withKey: false), null, 'physical')[$params['column'] ?? ''] ?? null;

        return $field === null ? $params : [...$params, 'type' => $field['type']->value];
    }

    public function rules(array $params): array
    {
        return [
            'mode' => ['required', Rule::in(self::MODES)],
            'column' => ['required', 'string', 'regex:/^\w+$/'],
            'type' => ['required', Rule::enum(ColumnType::class)->only(self::TYPES)],
            'value' => [
                'required',
                'string',
                (ColumnType::tryFrom($params['type'] ?? '') ?? ColumnType::String)->valueRule(),
            ],
        ];
    }

    public function sourceColumns(array $params): array
    {
        return [$params['column']];
    }

    public function resultType(?ColumnType $input, array $params): ?ColumnType
    {
        return $input;
    }

    public function connector(string $name, ?string $field, array $params): array
    {
        $literal = ColumnType::from($params['type']) === ColumnType::Boolean
            ? $params['value']
            : "'" . addcslashes($params['value'], "\\'") . "'";

        return [
            $name => [
                'type' => 'io.confluent.connect.transforms.Filter$Value',
                'filter.condition' => sprintf('$[?(@.%s == %s)]', $params['column'], $literal),
                'filter.type' => $params['mode'],
                'missing.or.null.behavior' => $params['mode'] === 'include' ? 'exclude' : 'include',
                'predicate' => 'tombstone',
                'negate' => 'true',
            ],
        ];
    }

    private static function isFlag(?ConnectionMetadata $object, ?string $column): bool
    {
        return (self::fields($object)[$column ?? '']['type'] ?? null) === ColumnType::Boolean;
    }

    /** @return array<string, array{source: string, title: string, physical: string, type: ColumnType}> */
    private static function fields(?ConnectionMetadata $object): array
    {
        return array_column(
            array_filter(
                $object?->mappableFields(withKey: false) ?? [],
                static fn (array $field) => in_array($field['type'], self::TYPES, true),
            ),
            null,
            'physical',
        );
    }
}
