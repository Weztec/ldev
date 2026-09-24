
@props(['site', 'active'])

@php
    $tabs = [
        'detail' => ['label' => 'Overview', 'route' => 'site-detail', 'icon' => 'squares-2x2'],
        'settings' => ['label' => 'Project settings', 'route' => 'site-settings', 'icon' => 'cog-6-tooth'],
    ];
@endphp

<nav class="flex gap-1 border-b border-gray-200 dark:border-gray-700" aria-label="Project sections">
    @foreach($tabs as $key => $tab)
        @php $isActive = $active === $key;@endphp
        <a href="{{ route($tab['route'], $site) }}" wire:navigate
            @if($isActive) aria-current="page" @endif
            class="-mb-px flex items-center gap-2 px-4 py-2.5 text-sm border-b-2 {{ $isActive
                ? 'border-blue-500 text-blue-600 dark:text-blue-400 font-semibold'
                : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600' }}">
            <flux:icon name="{{ $tab['icon'] }}" class="size-4 shrink-0" />
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
