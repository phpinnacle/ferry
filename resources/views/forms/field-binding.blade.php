@php
    use Filament\Support\Facades\FilamentAsset;
    use PHPinnacle\Ferry\FerryServiceProvider;

    $sources = $getSources();
    $bindings = $getBindings();
    $childSchemas = $getChildSchemas();
    $sourceStateKeys = $getSourceStateKeys();
    $constants = $getConstants();
    $sourceLabel = $getSourceLabel();
    $targetLabel = $getTargetLabel();
    $hasFields = $sources !== [];
    $searchable = $isSearchable();
    $contained = $isContained();
    $dense = $isDense();
    $compact = $isCompact();
    $disabled = $isDisabled();
    $statePath = $getStatePath();
    $warnings = $getWarnings();
    $unavailableWarnings = array_diff_key($warnings, array_column($sources, null, 'id'));
    $configurationKey = md5(serialize([$sources, $disabled, $searchable, $dense, $compact, $contained, $sourceLabel, $targetLabel]));
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        {{ $getExtraAttributeBag()->class([
            'field-binding isolate',
            'rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => $contained,
            'fi-dense' => $dense,
            'fi-compact' => $compact,
        ]) }}
        wire:key="{{ $getId() }}-{{ $configurationKey }}"
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('field-binding', FerryServiceProvider::PACKAGE) }}"
        x-data="fieldBinding({ state: $wire.$entangle('{{ $statePath }}'), disabled: @js($disabled), sourceKeys: @js(array_values($sourceStateKeys)) })"
    >
        @if ($contained)
            <div @class(['flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 dark:border-white/10', 'px-3 py-2' => $dense, 'px-5 py-4' => ! $dense])>
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                    <input type="checkbox" x-model="unboundOnly" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800" />
                    {{ __('phpinnacle-ferry::resources.binding.show_unbound') }}
                </label>
                <span class="text-sm text-gray-500 dark:text-gray-400" x-text="@js(__('phpinnacle-ferry::resources.binding.bound_count', ['bound' => '__bound__', 'total' => count($sources)])).replace('__bound__', boundCount())">{{ __('phpinnacle-ferry::resources.binding.bound_count', ['bound' => count($bindings) - count($constants), 'total' => count($sources)]) }}</span>
            </div>
        @endif

        @foreach ($unavailableWarnings as $id => $warning)
            <div @class(['field-mapping-warning', 'px-3 pt-2' => $dense, 'px-5 pt-4' => ! $dense]) role="alert">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                <span>{{ $id }}: {{ $warning }}</span>
            </div>
        @endforeach

        <div @class(['space-y-2 p-3' => $dense, 'space-y-3 px-5 py-5' => ! $dense])>
            @if (! $hasFields)
                <x-filament::empty-state
                    icon="heroicon-o-rectangle-stack"
                    :heading="__('phpinnacle-ferry::resources.binding.empty')"
                    :contained="false"
                />
            @else
                <div @class(['grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_minmax(72px,0.45fr)_minmax(0,2fr)]', 'gap-y-3' => $dense, 'gap-y-5' => ! $dense, 'pb-1' => $searchable && $dense, 'pb-2' => $searchable && ! $dense])>
                    <div class="min-w-0">
                        <h3 @class(['text-sm font-semibold text-gray-950 dark:text-white', 'mb-2' => $searchable && $dense, 'mb-3' => $searchable && ! $dense])>{{ $sourceLabel }}</h3>
                        @if ($searchable)
                            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                                <x-filament::input type="search" x-model="search" placeholder="{{ __('phpinnacle-ferry::resources.binding.search_placeholder') }}" aria-label="{{ __('phpinnacle-ferry::resources.binding.search_label', ['label' => $sourceLabel]) }}" />
                            </x-filament::input.wrapper>
                        @endif
                    </div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white md:col-start-3">{{ $targetLabel }}</h3>
                </div>

                @foreach ($sources as $source)
                    @php
                        $key = $getBindingKey($source['id']);
                        $binding = $bindings[$key] ?? null;
                        $warning = $warnings[$source['id']] ?? null;
                        $searchText = strip_tags($source['label'].' '.($source['description'] ?? '')).' '.$source['id'].' '.$source['name'].' '.$source['presentation']['type'];
                    @endphp
                    <div
                        class="grid grid-cols-1 items-stretch md:grid-cols-[minmax(0,1fr)_minmax(72px,0.45fr)_minmax(0,2fr)] md:gap-0"
                        :class="{ 'gap-2': @js($dense) && !isBound(@js($key)), 'gap-3': @js(! $dense) && !isBound(@js($key)) }"
                        wire:key="{{ $getId() }}-{{ $key }}"
                        data-binding-source="{{ $source['id'] }}"
                        @if ($searchable)
                            x-show="@js($searchText).toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()) && (!unboundOnly || !isBound(@js($key)))"
                        @else
                            x-show="!unboundOnly || !isBound(@js($key))"
                        @endif
                    >
                        <div @class([
                            'field-mapping-row relative flex min-w-0 flex-wrap items-start rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900',
                            'min-h-14 gap-2 px-3 py-2' => $dense,
                            'min-h-20 gap-3 px-4 py-3' => ! $dense,
                            'is-mapped' => $binding !== null,
                            'has-warning' => $warning !== null,
                        ]) :class="{ 'is-mapped': isBound(@js($key)) }">
                            <div class="flex w-full items-start gap-2">
                                <x-phpinnacle-ferry::mapping-item :compact="$compact" :item="$source" :warning="$warning" />
                            </div>
                            @if (isset($childSchemas['settings_'.$sourceStateKeys[$source['id']]]))
                                <div class="w-full" data-binding-source-settings="{{ $source['id'] }}">
                                    {{ $childSchemas['settings_'.$sourceStateKeys[$source['id']]] }}
                                </div>
                            @endif
                        </div>
                        @php
                            $unbind = ($getAction('unbind'))(['source' => $source['id']]);
                            $unbindLabel = $unbind->getLabel().' '.strip_tags($source['label']);
                        @endphp
                        <button
                            type="button"
                            class="field-binding-connection flex h-6 w-full items-center justify-center self-center md:col-start-2 md:h-4"
                            aria-label="{{ $unbindLabel }}"
                            title="{{ $unbindLabel }}"
                            x-show="isBound(@js($key))"
                            x-on:click="{{ $unbind->getAlpineClickHandler() }}"
                            @if ($binding === null) x-cloak @endif
                            @disabled($unbind->isDisabled())
                        >
                            <svg class="hidden h-4 w-full md:block" viewBox="0 0 100 16" preserveAspectRatio="none" aria-hidden="true">
                                <path class="field-mapping-line" d="M 0 8 H 100" vector-effect="non-scaling-stroke" />
                                <path class="field-mapping-hit" d="M 0 8 H 100" vector-effect="non-scaling-stroke" />
                            </svg>
                            <svg class="h-6 w-4 md:hidden" viewBox="0 0 16 100" preserveAspectRatio="none" aria-hidden="true">
                                <path class="field-mapping-line" d="M 8 0 V 100" vector-effect="non-scaling-stroke" />
                                <path class="field-mapping-hit" d="M 8 0 V 100" vector-effect="non-scaling-stroke" />
                            </svg>
                        </button>
                        <div @class(['relative flex min-w-0 items-start rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 md:col-start-3', 'min-h-14 gap-2 px-3 py-2' => $dense, 'min-h-20 gap-3 px-4 py-3' => ! $dense])>
                            <fieldset class="min-w-0 flex-1" :inert="!isBound(@js($key))" :disabled="disabled || !isBound(@js($key))" @if ($binding === null) inert disabled @endif>
                                {{ $getBindingSchema($source['id']) }}
                            </fieldset>
                            <div class="field-binding-overlay" x-show="!isBound(@js($key))" @if ($binding !== null) x-cloak @endif>
                                {{ ($getAction('bind'))(['source' => $source['id']]) }}
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif

            @if ($hasConstants())
                <div @class(['space-y-2' => $dense, 'space-y-3' => ! $dense]) data-binding-constants>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('phpinnacle-ferry::resources.binding.constants') }}</h3>
                    @foreach ($constants as $key => $constant)
                        <div
                            @class(['flex items-start rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900', 'gap-2 px-3 py-2' => $dense, 'gap-3 px-4 py-3' => ! $dense])
                            wire:key="{{ $getId() }}-{{ $key }}"
                        >
                            <div class="min-w-0 flex-1">{{ $constant }}</div>
                            {{ ($getAction('removeConstant'))(['constant' => $key]) }}
                        </div>
                    @endforeach
                    <div>{{ $getAction('addConstant') }}</div>
                </div>
            @endif
        </div>
    </div>
</x-dynamic-component>
