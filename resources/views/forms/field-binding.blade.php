@php
    $sources = $getSources();
    $bindings = $getBindings();
    $childSchemas = $getChildSchemas();
    $sourceStateKeys = $getSourceStateKeys();
    $constants = $getConstants();
    $sourceActions = $getExtraSourceActions();
    $sourceLabel = $getSourceLabel();
    $targetLabel = $getTargetLabel();
    $hasFields = $sources !== [];
    $searchable = $isSearchable();
    $contained = $isContained();
    $compact = $isCompact();
    $warnings = $getWarnings();
    $unavailableWarnings = array_diff_key($warnings, array_column($sources, null, 'id'));
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        {{ $getExtraAttributeBag()->class([
            'field-binding isolate',
            'rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => $contained,
            'fi-compact' => $compact,
        ]) }}
        x-data="{ search: '', unboundOnly: false }"
    >
        @if ($contained)
            <div @class(['flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 dark:border-white/10', 'px-3 py-2' => $compact, 'px-5 py-4' => ! $compact])>
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                    <input type="checkbox" x-model="unboundOnly" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800" />
                    {{ __('phpinnacle-ferry::resources.binding.show_unbound') }}
                </label>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('phpinnacle-ferry::resources.binding.bound_count', ['bound' => count($bindings) - count($constants), 'total' => count($sources)]) }}</span>
            </div>
        @endif

        @foreach ($unavailableWarnings as $id => $warning)
            <div @class(['field-mapping-warning', 'px-3 pt-2' => $compact, 'px-5 pt-4' => ! $compact]) role="alert">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                <span>{{ $id }}: {{ $warning }}</span>
            </div>
        @endforeach

        <div @class(['space-y-3 p-3' => $compact, 'space-y-5 px-5 py-5' => ! $compact])>
            @if (! $hasFields)
                <x-filament::empty-state
                    icon="heroicon-o-rectangle-stack"
                    :heading="__('phpinnacle-ferry::resources.binding.empty')"
                    :contained="false"
                />
            @else
                <div @class(['grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]', 'gap-3' => $compact, 'gap-5' => ! $compact])>
                    <div class="min-w-0">
                        <h3 @class(['text-sm font-semibold text-gray-950 dark:text-white', 'mb-2' => $compact, 'mb-3' => ! $compact])>{{ $sourceLabel }}</h3>
                        @if ($searchable)
                            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                                <x-filament::input type="search" x-model="search" placeholder="{{ __('phpinnacle-ferry::resources.binding.search_placeholder') }}" aria-label="{{ __('phpinnacle-ferry::resources.binding.search_label', ['label' => $sourceLabel]) }}" />
                            </x-filament::input.wrapper>
                        @endif
                    </div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $targetLabel }}</h3>
                </div>

                @foreach ($sources as $source)
                    @php
                        $key = $getBindingKey($source['id']);
                        $binding = $bindings[$key] ?? null;
                        $warning = $warnings[$source['id']] ?? null;
                        $searchText = strip_tags($source['label'].' '.($source['description'] ?? '')).' '.$source['id'].' '.$source['presentation']['type'];
                    @endphp
                    <div
                        @class(['grid grid-cols-1 items-stretch md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]', 'gap-2 md:gap-3' => $compact, 'gap-3 md:gap-5' => ! $compact])
                        wire:key="{{ $getId() }}-{{ $key }}"
                        data-binding-source="{{ $source['id'] }}"
                        @if ($searchable)
                            x-show="@js($searchText).toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()) && (!unboundOnly || @js($binding === null))"
                        @else
                            x-show="!unboundOnly || @js($binding === null)"
                        @endif
                    >
                        <div @class([
                            'field-mapping-row relative flex min-w-0 flex-wrap items-start rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900',
                            'min-h-14 gap-2 px-3 py-2' => $compact,
                            'min-h-20 gap-3 px-4 py-3' => ! $compact,
                            'is-mapped' => $binding !== null,
                            'has-warning' => $warning !== null,
                        ])>
                            <div class="flex w-full items-start gap-2">
                                <x-phpinnacle-ferry::mapping-item :item="$source" :warning="$warning" />
                                @if ($binding !== null)
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="mt-0.5 text-primary-600 dark:text-primary-400" />
                                @endif
                            </div>
                            @if (isset($childSchemas['settings_'.$sourceStateKeys[$source['id']]]))
                                <div class="w-full" data-binding-source-settings="{{ $source['id'] }}">
                                    {{ $childSchemas['settings_'.$sourceStateKeys[$source['id']]] }}
                                </div>
                            @endif
                            @if ($sourceActions !== [])
                                <div class="flex w-full flex-wrap items-center gap-2" data-binding-source-actions="{{ $source['id'] }}">
                                    @foreach ($sourceActions as $sourceAction)
                                        @php
                                            $action = $sourceAction(['source' => $source['id']]);
                                        @endphp
                                        @if ($action->isVisible())
                                            {{ $action }}
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div @class(['flex min-w-0 items-start rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900', 'min-h-14 gap-2 px-3 py-2' => $compact, 'min-h-20 gap-3 px-4 py-3' => ! $compact])>
                            @if ($binding !== null)
                                <div class="min-w-0 flex-1">{{ $binding }}</div>
                                {{ ($getAction('unbind'))(['source' => $source['id']]) }}
                            @else
                                {{ ($getAction('bind'))(['source' => $source['id']]) }}
                            @endif
                        </div>
                    </div>
                @endforeach
            @endif

            @if ($hasConstants())
                <div @class(['space-y-2' => $compact, 'space-y-3' => ! $compact]) data-binding-constants>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('phpinnacle-ferry::resources.binding.constants') }}</h3>
                    @foreach ($constants as $key => $constant)
                        <div
                            @class(['flex items-start rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900', 'gap-2 px-3 py-2' => $compact, 'gap-3 px-4 py-3' => ! $compact])
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
