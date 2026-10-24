@php
    $field = $getContainer()->getParentComponent();
    $keys = $field->getSourceStateKeys();
    $cards = $field->getChildSchemas()['sourceCards'];
@endphp

<div class="flex flex-col {{ $field->isDense() ? 'gap-2' : 'gap-3' }}" data-mapping-static-sources>
    @foreach (array_keys($field->getConstants()) as $source)
        {!! $cards->getComponent('card_'.$keys[$source])->toSchemaHtml() !!}
    @endforeach
    <div>{{ $field->getAction('addConstant') }}</div>
</div>
