@php
    use Filament\Support\Facades\FilamentAsset;
    use PHPinnacle\Ferry\FerryServiceProvider;
    use PHPinnacle\Ferry\Forms\JsonSchema;

    $sources = $getSources();
    $targets = $getTargets();
    $outputPresentation = array_map(fn (JsonSchema $property) => $property->presentation(), JsonSchema::fromArray($getOutputSchema())->properties());
    $connectionModes = $getConnectionModes();
    $sourceLabel = $getSourceLabel();
    $targetLabel = $getTargetLabel();
    $hasFields = $sources !== [] && $targets !== [];
    $sourceWarnings = $getSourceWarnings();
    $targetWarnings = $getTargetWarnings();
    $requiredTargets = array_column(array_filter($targets, fn (array $target) => $target['required']), 'id');
    $multipleTargets = $getMultipleTargets();
    $stateKeys = $getStateKeys();
    $staticDefaults = $getStaticDefaults();
    $staticSchemas = $getChildSchemas();
    $sourceStateKeys = $getSourceStateKeys();
    $inverse = $isInverse();
    $disabled = $isDisabled();
    $searchable = $isSearchable();
    $contained = $isContained();
    $compact = $isCompact();
    $statePath = $getStatePath();
    $configurationKey = md5(serialize([$sources, $targets, $outputPresentation, $connectionModes, $multipleTargets, $stateKeys, $staticDefaults, $inverse, $disabled, $searchable, $contained, $compact, $sourceLabel, $targetLabel, $sourceWarnings, $targetWarnings]));
    $panels = ['source' => ['label' => $sourceLabel, 'items' => $sources, 'warnings' => $sourceWarnings], 'target' => ['label' => $targetLabel, 'items' => $targets, 'warnings' => $targetWarnings]];

    if ($inverse) {
        $panels = array_reverse($panels, true);
    }
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        {{ $getExtraAttributeBag()->class([
            'field-mapping isolate',
            'rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => $contained,
            'fi-compact' => $compact,
        ]) }}
        wire:key="{{ $getId() }}-{{ $configurationKey }}"
        @if (! $hasStaticForms() && ! $hasSourceSettings())
            wire:ignore
        @endif
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('field-mapping', FerryServiceProvider::PACKAGE) }}"
        x-data="fieldMapping({
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            disabled: @js($disabled),
            inverse: @js($inverse),
            requiredTargets: @js($requiredTargets),
            connectionModes: @js($connectionModes),
            stateKeys: @js((object) $stateKeys),
            staticDefaults: @js((object) $staticDefaults),
        })"
        x-on:keydown.escape.window="cancel()"
        x-on:resize.window="refresh()"
    >
        @if ($contained)
            <div @class(['flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 dark:border-white/10', 'px-3 py-2' => $compact, 'px-5 py-4' => ! $compact])>
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                    <input type="checkbox" x-model="unmappedOnly" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800" />
                    {{ __('phpinnacle-ferry::resources.mapping.show_unmapped') }}
                </label>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {!! __('phpinnacle-ferry::resources.mapping.mapped_count', ['mapped' => '<span x-text="mappedTargetCount()"></span>', 'total' => count($targets)]) !!}
                    @if ($requiredTargets !== [])
                        <span class="ml-3 text-warning-600 dark:text-warning-400" x-show="missingRequired().length" x-cloak>
                            {!! __('phpinnacle-ferry::resources.mapping.required_remaining', ['count' => '<span x-text="missingRequired().length"></span>']) !!}
                        </span>
                    @endif
                </span>
            </div>
        @endif

        @foreach ($panels as $panel)
            @foreach ($hasFields ? array_diff_key($panel['warnings'], array_column($panel['items'], null, 'id')) : $panel['warnings'] as $id => $warning)
                <div @class(['field-mapping-warning', 'px-3 pt-2' => $compact, 'px-5 pt-4' => ! $compact]) role="alert">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                    <span>{{ $id }}: {{ $warning }}</span>
                </div>
            @endforeach
        @endforeach

        <div class="overflow-x-auto" x-on:scroll="refresh()">
            <div @class(['field-mapping-canvas relative grid grid-cols-[minmax(0,1fr)_minmax(72px,0.45fr)_minmax(0,1fr)] gap-0', 'p-3' => $compact, 'px-5 py-5' => ! $compact]) x-ref="canvas" :class="{ 'is-connecting': selected !== null }">
                @if (! $hasFields)
                    <x-filament::empty-state
                        class="col-span-full"
                        icon="heroicon-o-rectangle-stack"
                        :heading="__('phpinnacle-ferry::resources.mapping.empty')"
                        :contained="false"
                    />
                @else
                    @foreach ($panels as $side => $panel)
                        @php
                            $isLeft = $side === ($inverse ? 'target' : 'source');
                        @endphp
                        <section class="{{ $isLeft ? 'col-start-1' : 'col-start-3' }} row-start-1 min-w-0" aria-label="{{ $panel['label'] }}" wire:key="{{ $getId() }}-{{ $side }}">
                            <h3 @class(['text-sm font-semibold text-gray-950 dark:text-white', 'mb-2' => $compact, 'mb-3' => ! $compact])>{{ $panel['label'] }}</h3>
                            @if ($searchable)
                                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                                    <x-filament::input
                                        type="search"
                                        x-model="{{ $side }}Search"
                                        placeholder="{{ __('phpinnacle-ferry::resources.mapping.search_placeholder') }}"
                                        aria-label="{{ 'Search '.$panel['label'] }}"
                                    />
                                </x-filament::input.wrapper>
                            @endif

                            <div @class(['flex flex-col', 'gap-2' => $compact, 'gap-3' => ! $compact, 'mt-3' => $searchable && $compact, 'mt-5' => $searchable && ! $compact])>
                                @foreach ($panel['items'] as $item)
                                    @php
                                        $plainLabel = strip_tags($item['label']);
                                        $warning = $panel['warnings'][$item['id']] ?? null;
                                        $presentation = $side === 'source' ? ($outputPresentation[$item['id']] ?? JsonSchema::fromArray([])->presentation()) : $item['presentation'];
                                        $typeLabel = $presentation['type'];
                                        $searchText = $plainLabel.' '.strip_tags($item['description'] ?? '').' '.$item['id'].' '.$typeLabel.' '.implode(' ', array_column($item['badges'], 'label'));
                                        $isMultiple = $side === 'target' && in_array($item['id'], $multipleTargets, true);
                                        $staticSchema = $side === 'target' && array_key_exists($item['id'], $staticDefaults)
                                            ? ($staticSchemas[$stateKeys[$item['id']]] ?? $getStaticFormSchema($item['id']))
                                            : null;
                                    @endphp
                                    <div
                                        @class([
                                            'field-mapping-row relative flex flex-wrap items-start gap-2 rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900',
                                            'min-h-14' => $compact,
                                            'min-h-20' => ! $compact,
                                            'has-warning' => $warning !== null,
                                        ])
                                        data-mapping-row
                                        wire:key="{{ $getId() }}-{{ $side }}-{{ $item['id'] }}"
                                        x-show="isVisible(@js($side), @js($item['id']), @js($searchText))"
                                        :class="rowClasses(@js($side), @js($item['id']), @js($item['required']))"
                                    >
                                        <div class="flex w-full items-start">
                                            <div
                                                @class(['relative flex min-w-0 flex-1 items-start', 'px-3 py-2' => $compact, 'px-4 py-3' => ! $compact])
                                                x-on:click="if (!$event.target.closest('button')) select(@js($side), @js($item['id']))"
                                            >
                                                <button
                                                    type="button"
                                                    class="absolute inset-0 w-full rounded-lg text-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-default"
                                                    x-bind="fieldBindings(@js($side), @js($item['id']))"
                                                    aria-label="{{ 'Select '.$plainLabel.' as '.$side }}"
                                                    @disabled($disabled)
                                                ></button>
                                                <x-phpinnacle-ferry::mapping-item class="relative" :item="$item" :presentation="$presentation" :multiple="$isMultiple" :warning="$warning">
                                                    @if ($isMultiple)
                                                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400" x-show="isMapped('target', @js($item['id'])) && !isConstant(@js($item['id']))" x-cloak>
                                                            <span x-show="targetMode(@js($item['id'])) === 'whole'">{{ __('phpinnacle-ferry::resources.mapping.whole_array') }}</span>
                                                            <span x-show="targetMode(@js($item['id'])) === 'item'">{{ __('phpinnacle-ferry::resources.mapping.array_items') }}</span>
                                                        </span>
                                                    @endif
                                                    @if ($side === 'target')
                                                        <span class="mt-1 block text-xs text-danger-600 dark:text-danger-400" x-show="hasTypeConflict(@js($item['id']))" x-cloak>{{ __('phpinnacle-ferry::resources.mapping.incompatible') }}</span>
                                                    @endif
                                                </x-phpinnacle-ferry::mapping-item>
                                            </div>
                                            <button
                                                type="button"
                                                class="field-mapping-port {{ $isLeft ? 'is-left' : 'is-right' }}"
                                                data-mapping-port
                                                data-side="{{ $side }}"
                                                data-id="{{ $item['id'] }}"
                                                data-label="{{ $plainLabel }}"
                                                data-type="{{ $typeLabel }}"
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
                                        @if ($side === 'source' && isset($staticSchemas['settings_'.$sourceStateKeys[$item['id']]]))
                                            <div @class(['w-full', 'px-3 pb-2' => $compact, 'px-4 pb-3' => ! $compact]) data-mapping-source-settings="{{ $item['id'] }}">
                                                {{ $staticSchemas['settings_'.$sourceStateKeys[$item['id']]] }}
                                            </div>
                                        @endif
                                        @if ($staticSchema !== null)
                                            {{-- Mount against Livewire's client state so nested field entanglements see their value. --}}
                                            <template x-if="$wire.$get(@js($statePath))?.targets?.[@js($stateKeys[$item['id']])]?.kind === 'value'">
                                                <div @class(['w-full', 'px-3 pb-2' => $compact, 'px-4 pb-3' => ! $compact]) data-mapping-static-form="{{ $item['id'] }}">
                                                    {{ $staticSchema }}
                                                </div>
                                            </template>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach

                    <div class="field-mapping-lines absolute inset-0">
                        <template x-for="connection in connections" :key="connection.source">
                            <svg class="absolute inset-0 size-full" :viewBox="`0 0 ${width} ${height}`" role="group" aria-label="Field connection">
                                <path class="field-mapping-line" :d="connection.path" />
                                <path
                                    class="field-mapping-hit"
                                    :d="connection.path"
                                    :aria-label="connection.label"
                                    :tabindex="disabled ? -1 : 0"
                                    role="button"
                                    x-on:click="remove(connection.target, connection.source)"
                                    x-on:keydown.enter.prevent="remove(connection.target, connection.source)"
                                    x-on:keydown.space.prevent="remove(connection.target, connection.source)"
                                />
                            </svg>
                        </template>
                    </div>
                @endif
            </div>
        </div>

        <span class="sr-only" role="status" aria-live="polite" x-text="announcement"></span>
    </div>
</x-dynamic-component>
