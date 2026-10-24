@php
    $field = $getContainer()->getParentComponent();
    $modes = $field->getConnectionModes();
    $sourceIds = array_column($field->getSources(), 'id');
    $sourceTypes = array_map(fn (array $presentation) => $presentation['type'], $field->getSourcePresentations()) + array_fill_keys($sourceIds, 'any');
    $key = md5(serialize([$modes, $sourceIds, $sourceTypes]));
@endphp

<span hidden wire:key="{{ $field->getId() }}-configuration-{{ $key }}" x-init="updateConfiguration(@js($modes), @js($sourceIds), @js($sourceTypes))"></span>
