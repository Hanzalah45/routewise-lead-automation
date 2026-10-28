@props(['owner' => null])
@if($owner)
    <span class="owner-cell"><span class="avatar" title="{{ $owner->name }}">{{ $owner->initials() }}</span><span>{{ $owner->name }}</span></span>
@else
    <span class="owner-cell text-muted-2"><span class="avatar ghost"><i class="bi bi-person-dash"></i></span><span>Unassigned</span></span>
@endif
