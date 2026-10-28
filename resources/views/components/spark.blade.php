@props(['values', 'color' => '#0E1726'])
@php
    $w = 120; $h = 34; $n = max(2, count($values)); $max = max(1, max($values));
    $pts = collect($values)->values()->map(fn ($v, $i) => [round($i * ($w / ($n - 1)), 1), round($h - 3 - ($v / $max) * ($h - 8), 1)]);
    $line = $pts->map(fn ($p, $i) => ($i === 0 ? 'M' : 'L').$p[0].' '.$p[1])->implode(' ');
    $area = $line.' L'.$w.' '.$h.' L0 '.$h.' Z';
    $id = 'sp'.substr(md5(json_encode($values).$color), 0, 6);
@endphp
<svg class="spark" viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" aria-hidden="true">
    <defs><linearGradient id="{{ $id }}" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="{{ $color }}" stop-opacity=".16"/><stop offset="1" stop-color="{{ $color }}" stop-opacity="0"/></linearGradient></defs>
    <path d="{{ $area }}" fill="url(#{{ $id }})"/>
    <path d="{{ $line }}" fill="none" stroke="{{ $color }}" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
    <circle cx="{{ $pts->last()[0] }}" cy="{{ $pts->last()[1] }}" r="2.4" fill="{{ $color }}"/>
</svg>
