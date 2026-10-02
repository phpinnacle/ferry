@php
    use Filament\Support\Facades\FilamentAsset;
    use PHPinnacle\Ferry\FerryServiceProvider;

    $sources = $getSources();
    $targets = $getTargets();
    $sourceLabel = $getSourceLabel();
    $targetLabel = $getTargetLabel();
    $requiredTargets = array_column(array_filter($targets, fn (array $target) => $target['required']), 'id');
    $multipleTargets = $getMultipleTargets();
    $inverse = $isInverse();
    $disabled = $isDisabled();
    $statePath = $getStatePath();
    $configurationKey = md5(serialize([$sources, $targets, $multipleTargets, $inverse, $disabled, $sourceLabel, $targetLabel]));
    $panels = ['source' => ['label' => $sourceLabel, 'items' => $sources], 'target' => ['label' => $targetLabel, 'items' => $targets]];

    if ($inverse) {
        $panels = array_reverse($panels, true);
    }
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        {{ $getExtraAttributeBag()->class(['field-mapping rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900']) }}
        wire:key="{{ $getId() }}-{{ $configurationKey }}"
        wire:ignore
        x-load
        x-load-css="[@js(FilamentAsset::getStyleHref('field-mapping', FerryServiceProvider::PACKAGE))]"
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('field-mapping', FerryServiceProvider::PACKAGE) }}"
        x-data="fieldMapping({
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            disabled: @js($disabled),
            inverse: @js($inverse),
            requiredTargets: @js($requiredTargets),
            multipleTargets: @js($multipleTargets),
        })"
        x-on:keydown.escape.window="cancel()"
        x-on:resize.window="refresh()"
    >
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-white/10">
            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                <input type="checkbox" x-model="unmappedOnly" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800" />
                Show unmapped
            </label>
            <span class="text-sm text-gray-500 dark:text-gray-400">
                <span x-text="mappedTargetCount()"></span> of {{ count($targets) }} destinations mapped
                @if ($requiredTargets !== [])
                    <span class="ml-3 text-amber-600 dark:text-amber-400" x-show="missingRequired().length" x-cloak>
                        <span x-text="missingRequired().length"></span> required remaining
                    </span>
                @endif
            </span>
        </div>

        <div class="overflow-x-auto" x-on:scroll="refresh()">
            <div class="field-mapping-canvas relative grid grid-cols-[minmax(0,1fr)_minmax(72px,0.45fr)_minmax(0,1fr)] gap-0 px-5 py-5" x-ref="canvas">
                @foreach ($panels as $side => $panel)
                    @php
                        $isLeft = $side === ($inverse ? 'target' : 'source');
                    @endphp
                    <section class="{{ $isLeft ? 'col-start-1' : 'col-start-3' }} row-start-1 min-w-0" aria-label="{{ $panel['label'] }}" wire:key="{{ $getId() }}-{{ $side }}">
                        <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">{{ $panel['label'] }}</h3>
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input
                                type="search"
                                x-model="{{ $side }}Search"
                                placeholder="Search fields…"
                                aria-label="{{ 'Search '.$panel['label'] }}"
                            />
                        </x-filament::input.wrapper>

                        <div class="mt-5 flex flex-col gap-3">
                            @foreach ($panel['items'] as $item)
                                @php
                                    $plainLabel = strip_tags($item['label'] instanceof \Illuminate\Contracts\Support\Htmlable ? $item['label']->toHtml() : $item['label']);
                                    $descriptionText = $item['description'] instanceof \Illuminate\Contracts\Support\Htmlable ? $item['description']->toHtml() : ($item['description'] ?? '');
                                    $searchText = $plainLabel.' '.strip_tags($descriptionText).' '.$item['id'].' '.($item['type'] ?? '').' '.implode(' ', array_column($item['badges'], 'label'));
                                    $isMultiple = $side === 'target' && in_array($item['id'], $multipleTargets, true);
                                @endphp
                                <div
                                    class="field-mapping-row relative flex min-h-20 items-center gap-2 rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900"
                                    data-mapping-row
                                    wire:key="{{ $getId() }}-{{ $side }}-{{ $item['id'] }}"
                                    x-show="isVisible(@js($side), @js($item['id']), @js($searchText))"
                                    :class="rowClasses(@js($side), @js($item['id']), @js($item['required']))"
                                >
                                    <button
                                        type="button"
                                        class="flex min-w-0 flex-1 items-start gap-3 rounded-lg px-4 py-3 text-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-default"
                                        x-bind="fieldBindings(@js($side), @js($item['id']))"
                                        aria-label="{{ 'Select '.$plainLabel.' as '.$side }}"
                                        @disabled($disabled)
                                    >
                                        @if ($item['icon'] !== null)
                                            <x-filament::icon :icon="$item['icon']" class="mt-0.5 text-gray-500 dark:text-gray-400" />
                                        @endif
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center justify-between gap-2">
                                                <span class="text-sm font-medium wrap-anywhere text-gray-950 dark:text-white">{{ $item['label'] }}</span>
                                                @if ($item['required'] || $isMultiple || $item['type'] !== null || $item['badges'] !== [])
                                                    <span class="flex flex-wrap gap-1.5">
                                                        @if ($item['required'])
                                                            <x-filament::badge color="warning" size="sm">Required</x-filament::badge>
                                                        @endif
                                                        @if ($isMultiple)
                                                            <x-filament::badge color="info" size="sm">Multiple</x-filament::badge>
                                                        @endif
                                                        @if ($item['type'] !== null)
                                                            <x-filament::badge color="gray" size="sm">{{ $item['type'] }}</x-filament::badge>
                                                        @endif
                                                        @foreach ($item['badges'] as $badge)
                                                            <x-filament::badge :color="$badge['color']" size="sm" wire:key="{{ $getId() }}-{{ $side }}-{{ $item['id'] }}-badge-{{ $loop->index }}">{{ $badge['label'] }}</x-filament::badge>
                                                        @endforeach
                                                    </span>
                                                @endif
                                            </span>
                                            @if ($item['description'] !== null)
                                                <span class="mt-1 block text-xs/5 wrap-anywhere text-gray-500 dark:text-gray-400">{{ $item['description'] }}</span>
                                            @endif
                                            @if ($side === 'target')
                                                <span class="mt-1 block text-xs text-danger-600 dark:text-danger-400" x-show="hasTypeConflict(@js($item['id']))" x-cloak>Incompatible types</span>
                                            @endif
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        class="field-mapping-port {{ $isLeft ? 'is-left' : 'is-right' }}"
                                        data-mapping-port
                                        data-side="{{ $side }}"
                                        data-id="{{ $item['id'] }}"
                                        data-label="{{ $plainLabel }}"
                                        data-type="{{ $item['type'] ?? '' }}"
                                        x-bind="fieldBindings(@js($side), @js($item['id']))"
                                        aria-label="{{ 'Connect '.$plainLabel.' ('.$side.')' }}"
                                        @disabled($disabled)
                                    ></button>
                                    @if (! $isLeft)
                                        <button
                                            type="button"
                                            class="mr-3 rounded-md p-1 text-teal-600 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-teal-500 dark:text-teal-400 dark:hover:bg-teal-400/10"
                                            x-show="isMapped(@js($side), @js($item['id']))"
                                            @if ($inverse)
                                                x-on:click="remove(mappings()[@js($item['id'])], @js($item['id']))"
                                            @else
                                                x-on:click="remove(@js($item['id']))"
                                            @endif
                                            aria-label="{{ 'Disconnect '.$plainLabel }}"
                                            @disabled($disabled)
                                            x-cloak
                                        >
                                            <x-filament::icon icon="heroicon-m-x-mark" />
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                            @if ($panel['items'] === [])
                                <p class="py-6 text-sm text-gray-500 dark:text-gray-400">No fields available.</p>
                            @endif
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
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 px-5 py-4 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            <span class="flex items-center gap-2"><span class="size-2 rounded-full bg-teal-600 dark:bg-teal-400"></span> Connected</span>
            <p>Select two fields to connect. Click a line to disconnect. Escape cancels.</p>
        </div>
        <p class="px-5 pb-4 text-sm text-danger-600 dark:text-danger-400" role="alert" x-show="connectionError" x-text="connectionError" x-cloak></p>
        <span class="sr-only" role="status" aria-live="polite" x-text="announcement"></span>
    </div>
</x-dynamic-component>
