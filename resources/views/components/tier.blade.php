@props(['tier'])
<span class="tier {{ $tier }}">{{ $tier === 'hot' ? '●' : '' }} {{ ucfirst($tier) }}</span>
