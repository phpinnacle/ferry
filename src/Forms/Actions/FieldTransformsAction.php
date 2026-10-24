<?php

namespace PHPinnacle\Ferry\Forms\Actions;

use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Forms\FieldTransforms;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Transformation;

/** @phpstan-import-type Transform from FieldTransforms */
class FieldTransformsAction
{
    public static function make(FieldTransforms $field, TransformRegistry $transforms): Action
    {
        $object = $field->getObject();
        $available = $transforms->all();
        $selected = fn (array $arguments) => self::selected($arguments, $available);

        return Action::make('fieldTransforms')
            ->label(__('phpinnacle-ferry::resources.sync.fields.add_transform'))
            ->icon(Heroicon::Plus)
            ->color('gray')
            ->disabled(
                fn (FieldTransforms $component, array $arguments) => (
                    $component->isDisabled()
                    || !self::isAvailable($arguments, $available)
                    || self::isAdding($arguments)
                    && is_string($arguments['transform'] ?? null)
                    && !array_key_exists($arguments['transform'], $component->getAvailableTransforms($transforms))
                ),
            )
            ->view('phpinnacle-ferry::actions.field-transforms')
            ->viewData(fn (FieldTransforms $component) => [
                'available' => $available,
                'addable' => $component->getAvailableTransforms($transforms),
                'settings' => $component->getSettings(),
                'allowedMoves' => $component->getAllowedMoves($transforms),
                'object' => $object,
            ])
            ->modalHidden(
                fn (Action $action, array $arguments) => (
                    $action->isDisabled()
                    || !self::hasForm($arguments)
                    || $selected($arguments)->getSchema($object) === []
                ),
            )
            ->modalIcon(fn (array $arguments) => $selected($arguments)->getIcon())
            ->modalDescription(fn (array $arguments) => $selected($arguments)->getDescription())
            ->modalHeading(function (FieldTransforms $component, array $arguments) use ($selected) {
                $label = $component->getLabel();

                return __('phpinnacle-ferry::resources.sync.fields.field_transform_heading', [
                    'transform' => $selected($arguments)->getLabel(),
                    'field' => strip_tags($label instanceof Htmlable ? $label->toHtml() : $label ?? ''),
                ]);
            })
            ->schema(function (Action $action, FieldTransforms $component, array $arguments) use ($selected, $object) {
                if ($action->isDisabled() || !self::hasForm($arguments)) {
                    return [];
                }

                return self::schema(
                    $selected($arguments),
                    $object,
                    $component->getInputSchema(self::position($component->getSettings(), $arguments)),
                );
            })
            ->fillForm(fn (FieldTransforms $component, array $arguments) => self::hasForm($arguments)
                ? self::formData($component->getSettings(), $arguments)
                : null)
            ->action(function (FieldTransforms $component, array $arguments, array $data) use (
                $available,
                $transforms,
            ) {
                if (self::isAdding($arguments)) {
                    Validator::make($arguments, [
                        'transform' => [
                            'required',
                            Rule::in(array_keys($component->getAvailableTransforms($transforms))),
                        ],
                    ])->validate();
                }
                /** @var array{params?: array<string, string>} $data */
                $settings = self::apply($component->getSettings(), $arguments, $data, $available);
                if (array_key_exists('order', $arguments) && !$component->hasCompatibleTypes($settings, $transforms)) {
                    throw ValidationException::withMessages([
                        'order' => __('phpinnacle-ferry::validation.transform_order'),
                    ]);
                }
                $component->state($settings);
                $component->callAfterStateUpdated();
            });
    }

    /** @param array<array-key, mixed> $arguments */
    private static function hasForm(array $arguments): bool
    {
        return !array_key_exists('order', $arguments) && !array_key_exists('remove', $arguments);
    }

    /** @param array<array-key, mixed> $arguments */
    private static function isAdding(array $arguments): bool
    {
        return self::hasForm($arguments) && !array_key_exists('index', $arguments);
    }

    /**
     * @param list<Transform> $settings
     * @param array<array-key, mixed> $arguments
     * @param array{params?: array<string, string>} $data
     * @param array<string, Transformation> $available
     * @return list<Transform>
     */
    private static function apply(array $settings, array $arguments, array $data, array $available): array
    {
        if (array_key_exists('order', $arguments)) {
            return self::reorder($settings, $arguments);
        }

        $key = self::selected($arguments, $available)->key();

        $position = self::position($settings, $arguments);

        if ($position === null) {
            $settings[] = ['key' => $key, 'params' => $data['params'] ?? []];
        } elseif (array_key_exists('remove', $arguments)) {
            unset($settings[$position]);
        } else {
            $settings[$position] = ['key' => $key, 'params' => $data['params'] ?? []];
        }

        return array_values($settings);
    }

    /**
     * @param list<Transform> $settings
     * @param array<array-key, mixed> $arguments
     * @return array{params: array<string, string>}|null
     */
    private static function formData(array $settings, array $arguments): ?array
    {
        $position = self::position($settings, $arguments);

        return $position === null ? null : ['params' => $settings[$position]['params'] ?? []];
    }

    /**
     * @param list<Transform> $settings
     * @param array<array-key, mixed> $arguments
     */
    private static function position(array $settings, array $arguments): ?int
    {
        $matching = array_filter(
            $settings,
            static fn (array $setting) => $setting['key'] === ($arguments['transform'] ?? null),
        );
        /** @var array{index?: int|string} $validated */
        $validated = Validator::make($arguments, [
            'index' => [
                Rule::requiredIf(array_key_exists('remove', $arguments)),
                'integer',
                Rule::in(array_keys($matching)),
            ],
        ])->validate();

        return array_key_exists('index', $validated) ? (int) $validated['index'] : null;
    }

    /** @return list<Component> */
    private static function schema(Transformation $transform, ?ConnectionMetadata $object, ?JsonSchema $input): array
    {
        $schema = $transform->getSchema($object, $input);

        return (
            $schema === []
                ? []
                : [Group::make()->statePath('params')->schema($schema)]
        );
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<string, Transformation> $available
     */
    private static function isAvailable(array $arguments, array $available): bool
    {
        return (
            array_key_exists('order', $arguments)
            || is_string($arguments['transform'] ?? null) && array_key_exists($arguments['transform'], $available)
        );
    }

    /**
     * @param list<Transform> $settings
     * @param array<array-key, mixed> $arguments
     * @return list<Transform>
     */
    private static function reorder(array $settings, array $arguments): array
    {
        /** @var array{order: list<int|string>} $validated */
        $validated = Validator::make($arguments, [
            'order' => ['present', 'array', 'list', 'size:' . count($settings)],
            'order.*' => ['required', 'integer', 'distinct', Rule::in(array_keys($settings))],
        ])->validate();

        return array_map(static fn (int|string $index) => $settings[(int) $index], $validated['order']);
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<string, Transformation> $available
     */
    private static function selected(array $arguments, array $available): Transformation
    {
        /** @var array{transform: string} $validated */
        $validated = Validator::make($arguments, [
            'transform' => ['required', 'string', Rule::in(array_keys($available))],
        ])->validate();

        return $available[$validated['transform']];
    }
}
