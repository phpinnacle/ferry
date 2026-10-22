@php
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\View\ComponentAttributeBag;
    use Filament\Support\View\Components\BadgeComponent;
    use PHPinnacle\Ferry\FerryServiceProvider;

    $disabled = $action->getSchemaComponent()->isDisabled();
@endphp

<div
    class="flex flex-wrap items-center gap-2"
    data-field-transforms="{{ $action->getSchemaComponent()->getId() }}"
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('field-transforms', FerryServiceProvider::PACKAGE) }}"
    x-data="fieldTransforms({ action: @js($action->getName()), context: @js($action->getContext()), disabled: @js($disabled) })"
>
    @if ($addable !== [])
        <x-filament::dropdown placement="bottom-start" teleport>
            <x-slot name="trigger">
                <x-filament::icon-button
                    :icon="$action->getIcon()"
                    :label="$action->getLabel()"
                    :tooltip="$action->getLabel()"
                    :disabled="$disabled"
                    color="gray"
                    size="sm"
                />
            </x-slot>
            <x-filament::dropdown.list>
                @foreach ($addable as $transform)
                    @php
                        $button = $action(['transform' => $transform->key()]);
                    @endphp
                    {{ $button->grouped()->label($transform->getLabel())->icon($transform->getIcon()) }}
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
    <div
        class="flex flex-wrap items-center gap-2"
        x-ref="items"
        @if (! $disabled)
            x-sortable
            x-on:end.stop="reorder($event)"
        @endif
    >
        @foreach ($settings as $index => $setting)
            @php
                $transform = $available[$setting['key']];
                $hasSettings = $transform->getSchema($object) !== [];
                $labelTag = $hasSettings ? 'button' : 'span';
                $edit = $action(['transform' => $transform->key(), 'index' => $index]);
                $remove = $action(['transform' => $transform->key(), 'index' => $index, 'remove' => true]);
                $removeLabel = __('phpinnacle-ferry::resources.sync.fields.remove_transform', ['transform' => $transform->getLabel()]);
            @endphp
            <span
                {{ (new ComponentAttributeBag)->class(['ferry-transform', 'is-disabled' => $disabled])->color(BadgeComponent::class, $transform->getColor()) }}
                role="group"
                aria-label="{{ $transform->getLabel() }}"
                x-sortable-item="{{ $index }}"
                wire:key="{{ $action->getSchemaComponent()->getId() }}-{{ $action->getName() }}-{{ $index }}"
            >
                <{{ $labelTag }}
                    class="ferry-transform-label"
                    x-sortable-handle
                    x-on:keydown.alt.arrow-left.prevent="move(@js((string) $index), -1)"
                    x-on:keydown.alt.arrow-right.prevent="move(@js((string) $index), 1)"
                    @if ($hasSettings)
                        type="button"
                        wire:click="{{ $edit->getLivewireClickHandler() }}"
                        wire:loading.attr="disabled"
                        wire:target="{{ $edit->getLivewireClickHandler() }}"
                        @disabled($disabled)
                    @else
                        tabindex="{{ $disabled ? -1 : 0 }}"
                        aria-disabled="{{ $disabled ? 'true' : 'false' }}"
                    @endif
                >
                    @if ($transform->getIcon())
                        <x-filament::icon :icon="$transform->getIcon()" />
                    @endif
                    <span class="ferry-transform-text">{{ $transform->getLabel() }}</span>
                </{{ $labelTag }}>
                <button
                    type="button"
                    class="ferry-transform-remove"
                    aria-label="{{ $removeLabel }}"
                    title="{{ $removeLabel }}"
                    wire:click="{{ $remove->getLivewireClickHandler() }}"
                    wire:loading.attr="disabled"
                    wire:target="{{ $remove->getLivewireClickHandler() }}"
                    @disabled($disabled)
                >
                    <x-filament::icon icon="heroicon-m-x-mark" />
                </button>
            </span>
        @endforeach
    </div>
</div>
