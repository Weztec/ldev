
@props(['route', 'icon', 'label', 'active' => false, 'badge' => null])

<a href="{{ route($route) }}" wire:navigate
    title="{{ $label }}"
    x-bind:title="collapsed ? @js($label) : null"
    class="flex items-center gap-3 px-4 py-2 text-sm {{ $active
        ? 'bg-gray-100 dark:bg-gray-700 font-medium'
        : 'hover:bg-gray-100 dark:hover:bg-gray-700' }}"
>
    <span class="relative shrink-0">
        <flux:icon name="{{ $icon }}" class="size-5" />
        @if($badge)
            <span x-show="collapsed" x-cloak class="absolute -top-1 -right-1 size-2 rounded-full bg-yellow-500"></span>
        @endif
    </span>
    <span x-show="!collapsed" x-cloak class="truncate">{{ $label }}</span>
    @if($badge)
        <span x-show="!collapsed" x-cloak class="ml-auto rounded-full bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 text-xs px-1.5">{{ $badge }}</span>
    @endif
</a>
