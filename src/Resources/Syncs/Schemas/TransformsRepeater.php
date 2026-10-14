<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\TransformFactory;

class TransformsRepeater
{
    public static function make(
        TransformScope $scope,
        ?ConnectionMetadata $object,
        TransformFactory $transforms,
    ): Repeater {
        $available = $transforms->forScope($scope);
        $single = count($available) === 1 ? array_values($available)[0] : null;
        $isField = $scope === TransformScope::Field;

        $repeater = Repeater::make('transforms')
            ->addActionLabel(__('phpinnacle-ferry::resources.sync.fields.add_transform'))
            ->maxItems($isField ? count($available) : null)
            ->schema([
                Select::make('key')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.transform'))
                    ->options(array_map(static fn (Transform $transform) => $transform->getLabel(), $available))
                    ->when(
                        $isField,
                        static fn (Select $select) => $select->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    )
                    ->hiddenJs($single !== null ? 'true' : null)
                    ->required()
                    ->live()
                    ->afterStateUpdated(
                        fn (Set $set, ?string $state) => $set(
                            'params',
                            self::params($transforms->get($state ?? ''), $object),
                        ),
                    ),
                Group::make()
                    ->statePath('params')
                    ->schema(
                        fn (Get $get) => (
                            $transforms->get($get->string('key', isNullable: true) ?? '')?->schema($object) ?? []
                        ),
                    ),
            ]);

        return $single === null
            ? $repeater
            : $repeater->afterStateUpdated(fn (Repeater $component) => self::preselect($component, $single, $object));
    }

    private static function preselect(Repeater $component, Transform $transform, ?ConnectionMetadata $object): void
    {
        /** @var array<array-key, array<string, mixed>> $rows */
        $rows = $component->getRawState();

        foreach ($rows as $item => $row) {
            if (blank($row['key'] ?? null)) {
                $rows[$item] = ['key' => $transform->key, 'params' => self::params($transform, $object)];
            }
        }

        $component->rawState($rows);
    }

    /** @return array<string, mixed> */
    private static function params(?Transform $transform, ?ConnectionMetadata $object): array
    {
        $params = [];

        foreach ($transform?->schema($object) ?? [] as $field) {
            $params[$field->getName()] = $field->getDefaultState();
        }

        return $params;
    }
}
