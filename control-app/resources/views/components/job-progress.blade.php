@props(['site', 'job', 'status', 'unit' => 'step'])

@if(($status['state'] ?? null) === 'running')
    <div wire:ignore wire:key="job-progress-{{ $job }}-{{ $status['startedAt'] ?? '' }}"
        x-data="ldevJobProgress(@js(route('site-progress', $site)), @js($job), @js($unit), @js(($status['progress'] ?? []) + ['startedAt' => $status['startedAt'] ?? null]))"
        {{ $attributes->merge(['class' => 'space-y-1']) }}>
        <div class="flex justify-between gap-3 text-xs">
            <span class="truncate text-gray-700 dark:text-gray-200" x-text="label"></span>
            <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400" x-text="[position, percent + '%', time].filter(Boolean).join(' · ')"></span>
        </div>
        <div class="h-2 overflow-hidden rounded bg-gray-200 dark:bg-gray-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent">
            <div class="h-full rounded bg-blue-500 dark:bg-blue-400 transition-[width] duration-700" :style="'width: ' + percent + '%'"></div>
        </div>
    </div>
@endif
