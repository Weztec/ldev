@props(['seconds'])

@php
    $raw = number_format((float) $seconds, 2, '.', '') . 's';
    $total = (int) round((float) $seconds);
    $parts = array_filter([
        intdiv($total, 3600) ? intdiv($total, 3600) . ' h' : null,
        intdiv($total % 3600, 60) ? intdiv($total % 3600, 60) . ' min' : null,
        $total % 60 ? $total % 60 . ' s' : null,
    ]);
@endphp
@if((float) $seconds >= 60){{ implode(' ', $parts) }} <span {{ $attributes->merge(['class' => 'text-gray-400 dark:text-gray-500']) }}>({{ $raw }})</span>@else{{ $raw }}@endif
