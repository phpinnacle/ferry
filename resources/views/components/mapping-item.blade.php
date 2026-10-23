@props(['item', 'presentation' => null, 'multiple' => false, 'warning' => null, 'compact' => false])

@php
    $presentation ??= $item['presentation'];
    $hasTitle = strip_tags($item['label']) !== $item['id'];
    $color = $item['color'];
    $literalColor = $color !== null && (str_starts_with($color, '#') || str_starts_with($color, 'rgb'));
    $hasItems = $presentation['items'] !== [];
@endphp

<span
    {{ $attributes->class(['ferry-mapping-item', 'has-icon' => $item['icon'] !== null]) }}
    @if ($color !== null)
        style="--mapping-field-color: {{ $literalColor ? $color : 'var(--'.$color.'-600)' }}; --mapping-field-color-dark: {{ $literalColor ? $color : 'var(--'.$color.'-400)' }}"
    @endif
    @if ($hasItems)
        x-data="{ itemsOpen: false }"
        x-id="['mapping-items']"
    @endif
    data-mapping-item
>
    <span class="ferry-mapping-heading" data-mapping-heading>
        @if ($item['icon'] !== null)
            <span class="ferry-mapping-icon" data-mapping-icon aria-hidden="true">
                <x-filament::icon :icon="$item['icon']" />
            </span>
        @endif
        <span class="ferry-mapping-names">
            <span
                @class(['ferry-mapping-name', 'has-title' => $hasTitle])
                @if ($compact && $item['description'] !== null)
                    x-tooltip="{ content: @js($item['description']), theme: $store.theme }"
                    tabindex="0"
                @endif
            >{{ $item['label'] }}</span>
            @if ($item['required'])
                <span class="ferry-mapping-required"><span aria-hidden="true">*</span><span class="fi-sr-only">{{ __('phpinnacle-ferry::resources.mapping.required') }}</span></span>
            @endif
            @if ($hasTitle)
                <span class="ferry-mapping-property">{{ $item['id'] }}</span>
            @endif
        </span>
        <span class="ferry-mapping-types" data-mapping-badges>
            @foreach ($item['badges'] as $badge)
                <x-filament::badge :color="$badge['color']" size="sm">{{ $badge['label'] }}</x-filament::badge>
            @endforeach
            <x-phpinnacle-ferry::mapping-constraints :constraints="$presentation['constraints']" />
            @if ($presentation['nullable'])
                <span class="ferry-mapping-type is-null">null</span>
            @endif
            @if ($multiple)
                <span class="fi-sr-only">{{ __('phpinnacle-ferry::resources.mapping.multiple') }}</span>
            @endif
            <span
                @class(['ferry-mapping-type', 'is-null' => $presentation['type'] === 'null'])
                @if ($presentation['enum'] !== [])
                    title="{{ __('phpinnacle-ferry::resources.mapping.allowed_values') }}: {{ implode(', ', $presentation['enum']) }}"
                @endif
            >{{ $presentation['type'] }}</span>
            @if ($hasItems)
                <button
                    type="button"
                    class="ferry-mapping-details-toggle"
                    data-mapping-details-toggle
                    x-on:click.stop="itemsOpen = !itemsOpen"
                    :aria-expanded="itemsOpen"
                    :aria-controls="$id('mapping-items')"
                    aria-label="{{ __('phpinnacle-ferry::resources.mapping.show_item_types', ['field' => strip_tags($item['label'])]) }}"
                    :aria-label="itemsOpen ? @js(__('phpinnacle-ferry::resources.mapping.hide_item_types', ['field' => strip_tags($item['label'])])) : @js(__('phpinnacle-ferry::resources.mapping.show_item_types', ['field' => strip_tags($item['label'])]))"
                >
                    <x-filament::icon icon="heroicon-m-chevron-down" aria-hidden="true" />
                </button>
            @endif
        </span>
    </span>
    @if (! $compact && $item['description'] !== null)
        <span class="ferry-mapping-description" data-mapping-description>{{ $item['description'] }}</span>
    @endif
    @if ($hasItems)
        <span class="ferry-mapping-details" role="group" aria-label="{{ strip_tags($item['label']) }}" data-mapping-details x-show="itemsOpen" x-cloak :id="$id('mapping-items')">
            @foreach ($presentation['items'] as $level)
                <span class="ferry-mapping-details-row">
                    <span class="ferry-mapping-details-label">
                        <span>{{ implode(' → ', array_fill(0, count($level['scope']), __('phpinnacle-ferry::resources.mapping.constraints.items'))) }}</span>
                        @if ($level['nullable'])
                            <span class="ferry-mapping-type is-null">null</span>
                        @endif
                        <span class="ferry-mapping-type">{{ $level['type'] }}</span>
                    </span>
                    <span class="ferry-mapping-details-values">
                        <x-phpinnacle-ferry::mapping-constraints :constraints="$level['constraints']" :parent-scope="$level['scope']" />
                        @foreach ($level['enum'] as $value)
                            <span class="ferry-mapping-type">{{ $value }}</span>
                        @endforeach
                    </span>
                </span>
            @endforeach
        </span>
    @endif
    @if ($warning !== null)
        <span class="field-mapping-warning" role="alert">
            <x-filament::icon icon="heroicon-m-exclamation-triangle" />
            <span>{{ $warning }}</span>
        </span>
    @endif
    {{ $slot }}
</span>
