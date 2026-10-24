@php
    use Filament\Support\Facades\FilamentAsset;
    use PHPinnacle\Ferry\FerryServiceProvider;

    $sources = $getSources();
    $constants = $getConstants();
    $targets = $getTargets();
    $outputPresentation = $getSourcePresentations();
    $connectionModes = $getConnectionModes();
    $sourceLabel = $getSourceLabel();
    $targetLabel = $getTargetLabel();
    $hasFields = ($sources !== [] || $hasStaticValues()) && $targets !== [];
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
    $dense = $isDense();
    $compact = $isCompact();
    $columnSpans = $getColumnSpans();
    $statePath = $getStatePath();
    $configurationKey = md5(serialize([$sources, $targets, $outputPresentation, $connectionModes, $multipleTargets, $stateKeys, $staticDefaults, $inverse, $disabled, $searchable, $contained, $dense, $compact, $columnSpans, $sourceLabel, $targetLabel, $sourceWarnings, $targetWarnings]));
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
            'fi-dense' => $dense,
            'fi-compact' => $compact,
        ])->style([
            '--ferry-left-column: '.$columnSpans[$inverse ? 'target' : 'source'].'fr',
            '--ferry-gap-column: '.$columnSpans['gap'].'fr',
            '--ferry-right-column: '.$columnSpans[$inverse ? 'source' : 'target'].'fr',
        ]) }}
        wire:key="{{ $getId() }}-{{ $configurationKey }}"
        @if (! $hasStaticForms() && ! $hasSourceSettings() && ! $hasStaticValues())
            wire:ignore
        @endif
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('field-mapping', FerryServiceProvider::PACKAGE) }}"
        x-data="fieldMapping({
            element: $el,
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
        {!! $staticSchemas['mappingUi']->getComponent('configuration')->toSchemaHtml() !!}

        @if ($contained)
            <div @class(['flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 dark:border-white/10', 'px-3 py-2' => $dense, 'px-5 py-4' => ! $dense])>
                <fieldset class="ferry-mapping-filter">
                    <legend class="sr-only">{{ __('phpinnacle-ferry::resources.mapping.filter.label') }}</legend>
                    @foreach (['all', 'mapped', 'unmapped'] as $filter)
                        <label>
                            <input
                                type="radio"
                                name="{{ $getId() }}-visibility-filter"
                                value="{{ $filter }}"
                                x-model="visibilityFilter"
                                class="sr-only"
                                @checked($filter === 'all')
                            />
                            <span>{{ __('phpinnacle-ferry::resources.mapping.filter.'.$filter) }}</span>
                        </label>
                    @endforeach
                </fieldset>
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
                <div @class(['field-mapping-warning', 'px-3 pt-2' => $dense, 'px-5 pt-4' => ! $dense]) role="alert">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                    <span>{{ $id }}: {{ $warning }}</span>
                </div>
            @endforeach
        @endforeach

        <div class="overflow-x-auto" x-on:scroll="refresh()">
            <div @class(['field-mapping-canvas relative grid gap-0', 'p-3' => $dense, 'px-5 py-5' => ! $dense]) x-ref="canvas" :class="{ 'is-connecting': selected !== null }">
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
                            <h3 @class(['text-sm font-semibold text-gray-950 dark:text-white', 'mb-2' => $dense, 'mb-3' => ! $dense])>{{ $panel['label'] }}</h3>
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

                            <div @class(['flex flex-col', 'gap-2' => $dense, 'gap-3' => ! $dense, 'mt-3' => $searchable && $dense, 'mt-5' => $searchable && ! $dense])>
                                @foreach ($panel['items'] as $item)
                                    @if ($side === 'source')
                                        @if (! isset($constants[$item['id']]))
                                            {!! $staticSchemas['sourceCards']->getComponent('card_'.$sourceStateKeys[$item['id']])->toSchemaHtml() !!}
                                        @endif
                                    @else
                                        @include('phpinnacle-ferry::forms.field-mapping-row', ['field' => $field, 'presentation' => $item['presentation']])
                                    @endif
                                @endforeach
                                @if ($side === 'source' && $hasStaticValues())
                                    {!! $staticSchemas['mappingUi']->getComponent('staticSources')->toSchemaHtml() !!}
                                @endif
                            </div>
                        </section>
                    @endforeach

                    <div class="field-mapping-lines absolute inset-0">
                        <template x-for="connection in connections" :key="connection.source">
                            <svg class="absolute inset-0 size-full" :viewBox="`0 0 ${width} ${height}`" role="group" aria-label="Field connection">
                                <g
                                    class="field-connection-control"
                                    :aria-label="connection.label"
                                    :aria-disabled="disabled"
                                    :tabindex="disabled ? -1 : 0"
                                    role="button"
                                    x-on:mouseenter="hoveredConnection = connection"
                                    x-on:mouseleave="hoveredConnection = null"
                                    x-on:focus="focusedConnection = connection"
                                    x-on:blur="focusedConnection = null"
                                    x-on:click="remove(connection.target, connection.source)"
                                    x-on:keydown.enter.prevent="remove(connection.target, connection.source)"
                                    x-on:keydown.space.prevent="remove(connection.target, connection.source)"
                                >
                                    <path class="field-mapping-line" :d="connection.path" />
                                    <path class="field-mapping-hit" :d="connection.path" />
                                    <g class="field-connection-indicator" :transform="`translate(${connection.midpoint.x}, ${connection.midpoint.y})`" aria-hidden="true">
                                        <circle class="field-connection-disc" r="12" />
                                        <path class="field-connection-cross" d="M -4 -4 L 4 4 M -4 4 L 4 -4" />
                                    </g>
                                </g>
                            </svg>
                        </template>
                    </div>
                @endif
            </div>
        </div>

        <span class="sr-only" role="status" aria-live="polite" x-text="announcement"></span>
    </div>
</x-dynamic-component>
