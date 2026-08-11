@props(['name'])

@php
    $paths = [
        'google' => '<path d="M21.35 10.1h-9.18v3.72h5.27c-.23 1.2-.91 2.22-1.94 2.9v2.4h3.14c1.84-1.69 2.91-4.18 2.91-7.02 0-.67-.06-1.31-.2-1.92Z"/><path d="M12.17 21.63c2.63 0 4.84-.87 6.46-2.36l-3.14-2.4c-.87.58-1.98.92-3.32.92-2.55 0-4.71-1.72-5.49-4.03H3.44v2.48a9.76 9.76 0 0 0 8.73 5.39Z"/><path d="M6.68 13.76a5.87 5.87 0 0 1 0-3.72V7.56H3.44a9.75 9.75 0 0 0 0 8.68l3.24-2.48Z"/><path d="M12.17 6.01c1.43 0 2.72.49 3.74 1.45l2.8-2.8C17 3.11 14.8 2.2 12.17 2.2a9.76 9.76 0 0 0-8.73 5.36l3.24 2.48c.78-2.31 2.94-4.03 5.49-4.03Z"/>',
        'clipboard' => '<rect x="8" y="4" width="8" height="4" rx="1"/><path d="M6 6H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-1"/>',
        'download' => '<path d="M12 3v11"/><path d="m8 10 4 4 4-4"/><path d="M5 21h14a2 2 0 0 0 2-2v-2"/><path d="M3 17v2a2 2 0 0 0 2 2"/>',
        'map' => '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Z"/><path d="M9 3v15M15 6v15"/>',
        'play' => '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4V8Z"/>',
        'upload' => '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M5 20h14a2 2 0 0 0 2-2v-3M3 15v3a2 2 0 0 0 2 2"/>',
        'turn' => '<path d="M5 5h8a5 5 0 0 1 5 5v9"/><path d="m14 16 4 4 4-4"/><circle cx="5" cy="5" r="2"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
        'trash' => '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'trasee-icon', 'width' => '18', 'height' => '18', 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true']) }}>
    {!! $paths[$name] ?? '' !!}
</svg>
