<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Forms\RecordTransformBlock;
use PHPinnacle\Jason\Schema;

final class Unwrap implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'unwrap';
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

    public function output(Schema $input, array $params): Schema
    {
        // Removing the transport envelope preserves the row schema and physical field dependencies.
        return $input;
    }

    public function block(): RecordTransformBlock
    {
        return RecordTransformBlock::make($this->getKey())
            ->label(__('phpinnacle-ferry::transforms.unwrap'))
            ->icon(Heroicon::ArrowsPointingOut)
            ->schema([
                Select::make('delete_mode')
                    ->label(__('phpinnacle-ferry::transforms.unwrap_delete_mode'))
                    ->options([
                        'drop' => 'drop',
                        'tombstone' => 'tombstone',
                        'rewrite' => 'rewrite',
                        'rewrite-with-tombstone' => 'rewrite-with-tombstone',
                        'delete-to-tombstone' => 'delete-to-tombstone',
                    ])
                    ->default('tombstone')
                    ->selectablePlaceholder(false)
                    ->required(),
            ]);
    }

    /** @param array{delete_mode: string} $params */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'io.debezium.transforms.ExtractNewRecordState',
            'delete.tombstone.handling.mode' => $params['delete_mode'],
        ];
    }
}
