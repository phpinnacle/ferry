<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Forms\RecordTransformBlock;
use PHPinnacle\Jason\Schema;

final class DebeziumTimestamps implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'debezium_timestamps';
    }

    public function getScope(): TransformScope
    {
        return TransformScope::Record;
    }

    public function sourceFields(array $params): array
    {
        return [];
    }

    public function accepts(Schema $input): bool
    {
        return $input->singleType() === 'object';
    }

    /** @param array{created_field?: string|null, updated_field?: string|null} $params */
    public function output(Schema $input, array $params): ?Schema
    {
        if (blank($params['created_field'] ?? null) || blank($params['updated_field'] ?? null)) {
            return null;
        }

        $schema = $input->resolveReferences()->keywords();
        $schema['properties'] = array_map(
            fn (Schema $property) => $property->toArray(),
            $input->resolveReferences()->effectiveProperties(),
        )
        + [
            $params['created_field'] => ['type' => ['string', 'null'], 'format' => 'date-time'],
            $params['updated_field'] => ['type' => ['string', 'null'], 'format' => 'date-time'],
        ];

        return Schema::fromArray($schema);
    }

    public function block(): RecordTransformBlock
    {
        return RecordTransformBlock::make($this->getKey())
            ->label(__('phpinnacle-ferry::transforms.debezium_timestamps'))
            ->icon(Heroicon::Clock)
            ->schema([
                TextInput::make('created_field')
                    ->label(__('phpinnacle-ferry::transforms.debezium_created_field'))
                    ->helperText(__('phpinnacle-ferry::transforms.debezium_timestamps_help'))
                    ->default('created_at')
                    ->rules(['string'])
                    ->required(),
                TextInput::make('updated_field')
                    ->label(__('phpinnacle-ferry::transforms.debezium_updated_field'))
                    ->default('updated_at')
                    ->rules(['string'])
                    ->different('created_field')
                    ->required(),
            ]);
    }

    /** @param array{created_field: string, updated_field: string} $params */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.DebeziumTimestampsTransform',
            'created.field' => $params['created_field'],
            'updated.field' => $params['updated_field'],
        ];
    }
}
