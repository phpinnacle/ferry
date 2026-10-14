<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Actions;

use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Size;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\TransformsRepeater;
use PHPinnacle\Ferry\Services\TransformFactory;

class FieldTransformsAction
{
    /** @param array<string, string> $fields */
    public static function form(array $fields, ?ConnectionMetadata $object, TransformFactory $transforms): Action
    {
        return Action::make('fieldTransforms')
            ->label(fn (array $arguments, Get $get) => __('phpinnacle-ferry::resources.sync.fields.field_transforms', [
                'count' => count(self::state($get)[self::source($arguments, $fields)] ?? []),
            ]))
            ->icon('heroicon-m-adjustments-horizontal')
            ->color('gray')
            ->size(Size::Small)
            ->button()
            ->modalHeading(fn (array $arguments) => __(
                'phpinnacle-ferry::resources.sync.fields.field_transforms_heading',
                ['field' => $fields[self::source($arguments, $fields)]],
            ))
            ->schema([TransformsRepeater::make(TransformScope::Field, $object, $transforms)->hiddenLabel()])
            ->fillForm(fn (array $arguments, Get $get) => [
                'transforms' => self::state($get)[self::source($arguments, $fields)] ?? [],
            ])
            ->action(function (array $arguments, array $data, Get $get, Set $set) use ($fields) {
                /** @var array{transforms: array<array-key, mixed>} $data */
                $state = self::state($get);
                $state[self::source($arguments, $fields)] = array_values($data['transforms']);

                $set('field_transforms', array_filter($state));
            });
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<string, string> $fields
     */
    private static function source(array $arguments, array $fields): string
    {
        /** @var array{source: string} $validated */
        $validated = Validator::make($arguments, [
            'source' => ['required', 'string', Rule::in(array_keys($fields))],
        ])->validate();

        return $validated['source'];
    }

    /** @return array<string, array<array-key, mixed>> */
    private static function state(Get $get): array
    {
        /** @var array<string, array<array-key, mixed>> */
        return $get->array('field_transforms', isNullable: true) ?? [];
    }
}
