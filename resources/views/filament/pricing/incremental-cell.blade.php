@php
    $alignment = $column->getAlignment();
    $verticalAlignment = $column->getVerticalAlignment();
@endphp
<td wire:key="{{ $this->getId() }}.table.record.{{ $record->id }}.column.{{ $column->getName() }}"
    {{ $column->getExtraCellAttributeBag()->class([
        'fi-ta-cell',
        'fi-ta-cell-'.str($column->getName())->camel()->kebab(),
        $alignment instanceof \BackedEnum ? 'fi-align-'.$alignment->value : (is_string($alignment) ? $alignment : ''),
        $verticalAlignment instanceof \BackedEnum ? 'fi-vertical-align-'.$verticalAlignment->value : (is_string($verticalAlignment) ? $verticalAlignment : ''),
        filled($column->getHiddenFrom()) ? $column->getHiddenFrom().':fi-hidden' : '',
        filled($column->getVisibleFrom()) ? $column->getVisibleFrom().':fi-visible' : '',
    ]) }}>
    <div class="fi-ta-col">{{ $column }}</div>
</td>
