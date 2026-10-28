@props(['lead'])
<span class="score {{ $lead->tier }}"><span>{{ $lead->score }}</span><span class="bar"><i style="width: {{ min(100, max(0, $lead->score)) }}%"></i></span></span>
