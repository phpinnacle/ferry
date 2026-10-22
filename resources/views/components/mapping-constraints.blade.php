@props(['constraints', 'parentScope' => []])

@foreach ($constraints as $constraint)
    @php
        $scope = implode(' → ', array_map(fn (string $part) => $part === 'items' ? __('phpinnacle-ferry::resources.mapping.constraints.items') : $part, [...$parentScope, ...$constraint['scope']]));
        $constraintLabel = __('phpinnacle-ferry::resources.mapping.constraints.'.$constraint['kind']);
        $tooltip = ($scope === '' ? '' : $scope.': ').$constraintLabel.($constraint['value'] === '' ? '' : ': '.$constraint['value']);
    @endphp
    <span
        @class(['ferry-mapping-constraint', 'is-unique' => $constraint['kind'] === 'unique', 'is-pattern' => $constraint['kind'] === 'pattern'])
        data-mapping-constraint="{{ $constraint['kind'] }}"
        role="img"
        aria-label="{{ $tooltip }}"
        title="{{ $tooltip }}"
    >
        @if ($constraint['scope'] !== [])
            <span class="ferry-mapping-scope">{{ implode(' → ', $constraint['scope']) }}</span>
        @endif
        @if ($constraint['kind'] === 'length')
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                <rect x="3" y="7" width="18" height="10" rx="1.5" />
                <path d="M7 7v4m4-4v6m4-6v4m4-4v6" />
            </svg>
        @elseif ($constraint['kind'] === 'items')
            <x-filament::icon icon="heroicon-o-bars-3" aria-hidden="true" />
        @elseif ($constraint['kind'] === 'unique')
            <span>{{ $constraintLabel }}</span>
        @endif
        @if ($constraint['value'] !== '')
            <span class="ferry-mapping-constraint-value">{{ $constraint['value'] }}</span>
        @endif
    </span>
@endforeach
