@php($size = $size ?? 30)
{{-- Routewise mark: one line in from the left, splitting into two routes (saffron up, light down). --}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 32 32" fill="none" role="img" aria-label="Routewise" xmlns="http://www.w3.org/2000/svg">
    <path d="M3 17h8.5l8-8.5H29" stroke="#E8A317" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M11.5 17l8 8.5H29" stroke="{{ $ink ?? '#FFFFFF' }}" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
