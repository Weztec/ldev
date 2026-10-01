@props(['advice'])

@if($advice)
    @php
        $classes = [
            'blocked' => 'border-red-300 dark:border-red-800 text-red-700 dark:text-red-300',
            'action' => 'border-yellow-300 dark:border-yellow-800 text-yellow-800 dark:text-yellow-300',
            'unknown' => 'border-gray-300 dark:border-gray-700 text-gray-600 dark:text-gray-300',
            'ok' => 'border-green-300 dark:border-green-800 text-green-700 dark:text-green-400',
        ][$advice['level']] ?? 'border-gray-300 dark:border-gray-700 text-gray-600 dark:text-gray-300';
    @endphp
    <div {{ $attributes->merge(['class' => 'border-l-2 pl-2 font-sans text-xs space-y-0.5 ' . $classes]) }}>
        <p class="font-medium">{{ $advice['summary'] }}</p>
        @if(count($advice['lines']))
            <ul class="list-disc pl-4 text-gray-600 dark:text-gray-300">
                @foreach($advice['lines'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        @endif
        <p><span class="font-medium">Action:</span> <span class="text-gray-700 dark:text-gray-200">{{ $advice['action'] }}</span></p>
    </div>
@endif
