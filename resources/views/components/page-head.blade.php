@props(['title', 'sub' => null])
<div class="page-head">
    <div>
        <h1>{{ $title }}</h1>
        @if($sub)<p>{{ $sub }}</p>@endif
    </div>
    @if(trim((string) $slot) !== '')<div class="d-flex gap-2 flex-wrap">{{ $slot }}</div>@endif
</div>
