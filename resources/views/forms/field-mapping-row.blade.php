@php
    $inverse = $field->isInverse();
    $isLeft = $side === ($inverse ? 'target' : 'source');
    $dense = $field->isDense();
    $compact = $field->isCompact();
    $disabled = $field->isDisabled();
    $constants = $field->getConstants();
    $multipleTargets = $side === 'target' ? $field->getMultipleTargets() : [];
    $staticDefaults = $side === 'target' ? $field->getStaticDefaults() : [];
    $stateKeys = $side === 'target' ? $field->getStateKeys() : [];
    $staticSchemas = $field->getChildSchemas();
    $sourceStateKeys = $field->getSourceStateKeys();
    $statePath = $field->getStatePath();
    $warnings = $side === 'source' ? $field->getSourceWarnings() : $field->getTargetWarnings();
    $plainLabel = strip_tags($item['label']);
    $warning = $warnings[$item['id']] ?? null;
    $typeLabel = $presentation['type'];
    $searchText = $plainLabel.' '.strip_tags($item['description'] ?? '').' '.$item['id'].' '.$item['name'].' '.($side === 'target' ? $typeLabel : '').' '.implode(' ', array_column($item['badges'], 'label'));
    $isMultiple = $side === 'target' && in_array($item['id'], $multipleTargets, true);
    $staticSchema = $side === 'target' && array_key_exists($item['id'], $staticDefaults)
        ? ($staticSchemas[$stateKeys[$item['id']]] ?? $field->getStaticFormSchema($item['id']))
        : null;
    $hasSourceFooter = $side === 'source' && (isset($staticSchemas['settings_'.$sourceStateKeys[$item['id']]]) || isset($constants[$item['id']]));
@endphp
<div
    @class([
        'field-mapping-row relative flex flex-wrap items-start rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900',
        'min-h-14 gap-1' => $dense,
        'min-h-20 gap-2' => ! $dense,
        'has-warning' => $warning !== null,
    ])
    data-mapping-row
    x-on:click="selectFromCard($event, @js($side), @js($item['id']))"
    wire:key="{{ $field->getId() }}-{{ $side }}-{{ $item['id'] }}"
    x-show="isVisible(@js($side), @js($item['id']), @js($searchText))"
    :class="rowClasses(@js($side), @js($item['id']), @js($item['required']))"
>
    <div class="flex w-full items-start">
        <div
            @class(['relative flex min-w-0 flex-1 items-start', 'px-3 pt-2' => $dense, 'pb-2' => $dense && ! $hasSourceFooter, 'px-4 py-3' => ! $dense])
        >
            <button
                type="button"
                class="absolute inset-0 w-full rounded-lg text-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-default"
                x-bind="fieldBindings(@js($side), @js($item['id']))"
                aria-label="{{ 'Select '.$plainLabel.' as '.$side }}"
                @disabled($disabled)
            ></button>
            @if ($side === 'source')
                <div class="relative min-w-0 flex-1">
                    {!! $staticSchemas['sourceCards']->getComponent('heading_'.$sourceStateKeys[$item['id']])->toSchemaHtml() !!}
                </div>
            @else
                <x-phpinnacle-ferry::mapping-item :compact="$compact" class="relative" :item="$item" :presentation="$presentation" :multiple="$isMultiple" :warning="$warning">
                    @if ($isMultiple)
                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400" x-show="isMapped('target', @js($item['id'])) && !isConstant(@js($item['id']))" x-cloak>
                            <span x-show="targetMode(@js($item['id'])) === 'whole'">{{ __('phpinnacle-ferry::schema-forms.mapping.whole_array') }}</span>
                            <span x-show="targetMode(@js($item['id'])) === 'item'">{{ __('phpinnacle-ferry::schema-forms.mapping.array_items') }}</span>
                        </span>
                    @endif
                    @if ($side === 'target')
                        <span class="mt-1 block text-xs text-danger-600 dark:text-danger-400" x-show="hasTypeConflict(@js($item['id']))" x-cloak>{{ __('phpinnacle-ferry::schema-forms.mapping.incompatible') }}</span>
                    @endif
                </x-phpinnacle-ferry::mapping-item>
            @endif
        </div>
        <button
            type="button"
            class="field-mapping-port {{ $isLeft ? 'is-left' : 'is-right' }}"
            data-mapping-port
            data-side="{{ $side }}"
            data-id="{{ $item['id'] }}"
            data-label="{{ $plainLabel }}"
            x-bind="fieldBindings(@js($side), @js($item['id']))"
            aria-label="{{ 'Connect '.$plainLabel.' ('.$side.')' }}"
            @disabled($disabled)
        >
            <span class="field-mapping-anchor" data-mapping-anchor aria-hidden="true">
                <x-filament::icon icon="heroicon-m-check" class="field-mapping-available-icon" />
                <x-filament::icon icon="heroicon-m-x-mark" class="field-mapping-unavailable-icon" />
            </span>
        </button>
    </div>
    @if ($side === 'source' && isset($constants[$item['id']]))
        <div @class(['w-full', 'px-3 pb-2' => $dense, 'px-4 pb-3' => ! $dense]) data-mapping-static-source="{{ $item['id'] }}">
            {{ $staticSchemas['constant_'.$item['id']] }}
        </div>
    @endif
    @if ($hasSourceFooter)
        <div @class(['field-mapping-source-footer flex w-full items-start gap-2', 'px-3 pb-2' => $dense, 'px-4 pb-3' => ! $dense])>
            <div class="min-w-0 flex-1" data-mapping-source-settings="{{ $item['id'] }}">
                {{ $staticSchemas['settings_'.$sourceStateKeys[$item['id']]] ?? null }}
            </div>
            @if (isset($constants[$item['id']]))
                <div class="field-mapping-static-remove shrink-0 p-2" :class="{ 'invisible': isMapped('source', @js($item['id'])) }">
                    {{ ($field->getAction('removeConstant'))(['constant' => $item['id']])->label(__('phpinnacle-ferry::schema-forms.binding.remove_constant').' '.$item['id']) }}
                </div>
            @endif
        </div>
    @endif
    @if ($staticSchema !== null)
        {{-- Mount against Livewire's client state so nested field entanglements see their value. --}}
        <template x-if="$wire.$get(@js($statePath))?.targets?.[@js($stateKeys[$item['id']])]?.kind === 'value'">
            <div @class(['w-full', 'px-3 pb-2' => $dense, 'px-4 pb-3' => ! $dense]) data-mapping-static-form="{{ $item['id'] }}">
                {{ $staticSchema }}
            </div>
        </template>
    @endif
</div>
