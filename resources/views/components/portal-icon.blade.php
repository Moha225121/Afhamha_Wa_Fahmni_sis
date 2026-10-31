@props(['name'])
@php
    $paths = [
        'home' => ['M3 10 12 3l9 7', 'M5 9v12h5v-7h4v7h5V9'],
        'children' => ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0', 'M17 3a4 4 0 0 1 0 8', 'M22 21v-2a4 4 0 0 0-3-3.87'],
        'results' => ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z', 'M14 2v6h6', 'M8 17v-3m4 3v-6m4 6v-4'],
        'chat' => ['M21 11.5a8.5 8.5 0 0 1-8.5 8.5H4l-3 2 1.8-5.3A8.5 8.5 0 1 1 21 11.5Z', 'M7 9h8m-8 4h5'],
        'plus' => ['M12 5v14M5 12h14'],
        'search' => ['M21 21l-5-5', 'M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0'],
        'back' => ['M5 12h14m-6-6 6 6-6 6'],
        'send' => ['M21 3 3 12l18 9-4-9 4-9Z', 'M3 12h14'],
        'announcement' => ['M4 10 18 5v14L4 14Zm0 0H2v4h2m3 1 1 5h3l-1-4m11-7v6'],
    ][$name] ?? [];
@endphp
<svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">@foreach($paths as $path)<path d="{{ $path }}"/>@endforeach</svg>
