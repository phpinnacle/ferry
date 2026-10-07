@php
    use Filament\Support\Facades\FilamentAsset;
    use PHPinnacle\Ferry\FerryServiceProvider;

    $sources = $getSources();
    $bindings = $getBindings();
    $sourceLabel = $getSourceLabel();
    $targetLabel = $getTargetLabel();
    $hasFields = $sources !== [];
    $warnings = $getWarnings();
    $unavailableWarnings = array_diff_key($warnings, array_column($sources, null, 'id'));
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        {{ $getExtraAttributeBag()->class(['field-binding isolate rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900']) }}
        x-data="{ search: '', unboundOnly: false }"
        x-load-css="[@js(FilamentAsset::getStyleHref('field-mapping', FerryServiceProvider::PACKAGE))]"
    >
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-white/10">
            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                <input type="checkbox" x-model="unboundOnly" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800" />
                {{ __('phpinnacle-ferry::resources.binding.show_unbound') }}
            </label>
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('phpinnacle-ferry::resources.binding.bound_count', ['bound' => count($bindings), 'total' => count($sources)]) }}</span>
        </div>

        @foreach ($unavailableWarnings as $id => $warning)
            <div class="field-mapping-warning px-5 pt-4" role="alert">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                <span>{{ $id }}: {{ $warning }}</span>
            </div>
        @endforeach

        <div class="space-y-5 px-5 py-5">
            @if (! $hasFields)
                <x-filament::empty-state
                    icon="heroicon-o-rectangle-stack"
                    :heading="__('phpinnacle-ferry::resources.binding.empty')"
                    :contained="false"
                />
            @else
                <div class="grid grid-cols-1 gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <div class="min-w-0">
                        <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">{{ $sourceLabel }}</h3>
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input type="search" x-model="search" placeholder="{{ __('phpinnacle-ferry::resources.binding.search_placeholder') }}" aria-label="{{ __('phpinnacle-ferry::resources.binding.search_label', ['label' => $sourceLabel]) }}" />
                        </x-filament::input.wrapper>
                    </div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $targetLabel }}</h3>
                </div>

                @foreach ($sources as $source)
                    @php
                        $key = $getBindingKey($source['id']);
                        $binding = $bindings[$key] ?? null;
                        $warning = $warnings[$source['id']] ?? null;
                        $plainLabel = strip_tags($source['label'] instanceof \Illuminate\Contracts\Support\Htmlable ? $source['label']->toHtml() : $source['label']);
                        $descriptionText = $source['description'] instanceof \Illuminate\Contracts\Support\Htmlable ? $source['description']->toHtml() : ($source['description'] ?? '');
                        $searchText = $plainLabel.' '.strip_tags($descriptionText).' '.$source['id'];
                    @endphp
                    <div
                        class="grid grid-cols-1 items-stretch gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] md:gap-5"
                        wire:key="{{ $getId() }}-{{ $key }}"
                        data-binding-source="{{ $source['id'] }}"
                        x-show="@js($searchText).toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()) && (!unboundOnly || @js($binding === null))"
                    >
                        <div @class([
                            'field-mapping-row relative flex min-h-20 min-w-0 items-start gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-gray-900',
                            'is-mapped' => $binding !== null,
                            'has-warning' => $warning !== null,
                        ])>
                            @if ($source['icon'] !== null)
                                <x-filament::icon :icon="$source['icon']" class="mt-0.5 text-gray-500 dark:text-gray-400" />
                            @endif
                            <div class="min-w-0 flex-1">
                                <span class="text-sm font-medium wrap-anywhere text-gray-950 dark:text-white">{{ $source['label'] }}</span>
                                @if ($source['description'] !== null)
                                    <span class="mt-1 block text-xs/5 wrap-anywhere text-gray-500 dark:text-gray-400">{{ $source['description'] }}</span>
                                @endif
                                @if ($warning !== null)
                                    <span class="field-mapping-warning mt-1" role="alert">
                                        <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                                        <span>{{ $warning }}</span>
                                    </span>
                                @endif
                            </div>
                            @if ($binding !== null)
                                <x-filament::icon icon="heroicon-m-arrow-right" class="mt-0.5 text-teal-600 dark:text-teal-400" />
                            @endif
                        </div>
                        <div class="flex min-h-20 min-w-0 items-start gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-gray-900">
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
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 px-5 py-4 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            <span class="flex items-center gap-2"><span class="size-2 rounded-full bg-teal-600 dark:bg-teal-400"></span> {{ __('phpinnacle-ferry::resources.binding.legend_bound') }}</span>
            <p>{{ __('phpinnacle-ferry::resources.binding.hint') }}</p>
        </div>
    </div>
</x-dynamic-component>
