<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Schemas;

use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Transformation;

class TransformsBuilder
{
    public static function make(
        TransformScope $scope,
        ?ConnectionMetadata $object,
        TransformRegistry $transforms,
    ): Builder {
        $available = $transforms->forScope($scope);

        return Builder::make('transforms')
            ->blockIcons()
            ->addActionLabel(__('phpinnacle-ferry::resources.sync.fields.add_transform'))
            ->rule(
                fn () => fn (string $attribute, mixed $value, Closure $fail) => self::validateBlocks(
                    $value,
                    $fail,
                    array_keys($available),
                ),
            )
            ->blocks(array_map(
                static fn (Transformation $transform) => Block::make($transform->key())
                    ->label(static fn (?array $state) => (
                        $state === null
                            ? $transform->getLabel()
                            : view('phpinnacle-ferry::components.transform-label', ['transform' => $transform])
                    ))
                    ->icon($transform->getIcon())
                    ->schema([
                        Text::make($transform->getDescription())->visible(filled($transform->getDescription())),
                        ...$transform->getSchema($object),
                    ]),
                $available,
            ))
            ->afterStateHydrated(function (Builder $component, ?array $state) {
                /** @var array<array-key, array{key: string, params?: array<string, string>}>|null $state */
                $component->rawState(array_map(
                    static fn (array $setting) => ['type' => $setting['key'], 'data' => $setting['params'] ?? []],
                    $state ?? [],
                ));
                $component->hydrateItems();
            })
            ->mutateDehydratedStateUsing(function (Builder $component, ?array $state) {
                /** @var array<array-key, array{type: string, data?: array<string, string>|null}> $state */
                /** @var array<array-key, mixed> $rawState */
                $rawState = $component->getRawState() ?? [];

                // Validation can collect blocks without fields last; retain the editor's order.
                return array_map(
                    static fn (int|string $key) => [
                        'key' => $state[$key]['type'],
                        'params' => $state[$key]['data'] ?? [],
                    ],
                    array_keys($rawState),
                );
            });
    }

    /**
     * @param Closure(string): PotentiallyTranslatedString $fail
     * @param list<string> $keys
     */
    private static function validateBlocks(mixed $value, Closure $fail, array $keys): void
    {
        if (!is_array($value)) {
            $fail('validation.array')->translate();

            return;
        }

        $validator = Validator::make(['blocks' => $value], [
            'blocks.*.type' => ['required', 'string', Rule::in($keys)],
        ]);

        if ($validator->fails()) {
            $fail($validator->errors()->first())->translate();
        }
    }
}
