@php
    use Illuminate\Support\Str;
    use PHPinnacle\Ferry\Forms\FieldBinding;

    /** @var FieldBinding $field */
    $field = $getContainer()->getParentComponent();
    $dense = $field->isDense();
@endphp
<div @class(['space-y-2' => $dense, 'space-y-3' => ! $dense]) data-binding-constants>
    @foreach ($field->getConstants() as $key => $constant)
        @php
            $property = $field->getConstantTarget($key);
            $remove = ($field->getAction('removeConstant'))(['constant' => $key]);
            $removeLabel = $remove->getLabel().' '.$property;
        @endphp
        <div class="field-binding-columns grid grid-cols-1 items-stretch gap-y-2 md:gap-0" wire:key="{{ $field->getId() }}-{{ $key }}" data-binding-constant="{{ $key }}" x-show="matchesFilter(true)">
            <div @class(['field-mapping-row is-mapped relative min-w-0 space-y-2 rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900', 'px-3 py-2' => $dense, 'px-4 py-3' => ! $dense])>
                <div class="ferry-mapping-heading">
                    <span class="ferry-mapping-names">
                        <span class="ferry-mapping-name has-title">{{ Str::headline($property) }}</span>
                        <span class="ferry-mapping-property">{{ $property }}</span>
                    </span>
                    <span class="ferry-mapping-types">
                        <span class="ferry-mapping-type">{{ $field->getConstantInputSchema($key)->singleType() }}</span>
                    </span>
                </div>
                {{ $constant->getComponent('input') }}
            </div>
            <button
                type="button"
                class="field-binding-connection field-connection-control relative flex h-8 w-full items-center justify-center self-center md:col-start-2"
                aria-label="{{ $removeLabel }}"
                title="{{ $removeLabel }}"
                wire:click="{{ $remove->getLivewireClickHandler() }}"
                wire:loading.attr="disabled"
                wire:target="{{ $remove->getLivewireClickHandler() }}"
                @disabled($remove->isDisabled())
            >
                <svg class="hidden h-4 w-full md:block" viewBox="0 0 100 16" preserveAspectRatio="none" aria-hidden="true"><path class="field-mapping-line" d="M 0 8 H 100" vector-effect="non-scaling-stroke" /></svg>
                <svg class="h-8 w-4 md:hidden" viewBox="0 0 16 100" preserveAspectRatio="none" aria-hidden="true"><path class="field-mapping-line" d="M 8 0 V 100" vector-effect="non-scaling-stroke" /></svg>
                <span class="field-connection-indicator" aria-hidden="true">
                    <x-filament::icon :icon="$remove->getIcon()" />
                </span>
            </button>
            <div @class(['flex min-w-0 items-start rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 md:col-start-3', 'gap-2 px-3 py-2' => $dense, 'gap-3 px-4 py-3' => ! $dense])>
                <div class="min-w-0 flex-1">{{ $constant->getComponent('output') }}</div>
            </div>
        </div>
    @endforeach
    <div>{{ $field->getAction('addConstant') }}</div>
</div>
