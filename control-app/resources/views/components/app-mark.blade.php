
@props(['class' => 'size-6'])
@php($gradientId = 'app-mark-bg-' . substr(uniqid(), -8))

<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" class="{{ $class }} shrink-0" aria-hidden="true">
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#312e81"/>
            <stop offset="1" stop-color="#1e1b4b"/>
        </linearGradient>
    </defs>
    <rect x="0" y="0" width="512" height="512" rx="112" fill="url(#{{ $gradientId }})"/>
    <rect x="128" y="168" width="256" height="60" rx="20" fill="#fb7185"/>
    <rect x="128" y="246" width="256" height="60" rx="20" fill="#fbbf24"/>
    <rect x="128" y="324" width="256" height="60" rx="20" fill="#2dd4bf"/>
</svg>
