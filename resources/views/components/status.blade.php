@props(['value'])
@php($label = $value instanceof \BackedEnum ? $value->value : (string) $value)
<span class="badge badge-{{ $label }}"><span></span>{{ ucfirst(str_replace('_', ' ', $label)) }}</span>
