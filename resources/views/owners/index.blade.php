@extends('layouts.app')
@section('title', 'Owners')

@section('content')
@php($admin = auth()->user()->isAdmin())
@php($blankOwner = json_encode(['id' => null]))
<x-page-head title="Owners" sub="Who leads can be routed to. The router picks by region, then segment, then the lightest load today.">
    @if($admin)<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ownerModal" data-owner="{{ $blankOwner }}"><i class="bi bi-plus-lg me-1"></i>Add owner</button>@endif
</x-page-head>

@unless($admin)<div class="demo-banner sim-note" role="note"><i class="bi bi-lock"></i><div>Read-only. Only admins can add or change owners.</div></div>@endunless

<section class="panel panel-flush">
    <div class="table-scroll">
    <table class="table table-clean align-middle">
        <thead><tr><th>Owner</th><th>Regions</th><th>Segments</th><th style="min-width:170px">Today</th><th class="text-end">Last 7 days</th><th>CRM id</th>@if($admin)<th></th>@endif</tr></thead>
        <tbody>
        @foreach($owners as $o)
            @php($t = $today[$o->id] ?? 0)
            <tr class="{{ $o->active ? '' : 'opacity-50' }}">
                <td><div class="owner-cell"><span class="avatar">{{ $o->initials() }}</span><div><span class="cell-main">{{ $o->name }}</span><span class="cell-sub">{{ $o->email }}</span></div></div></td>
                <td>@forelse($o->regionList() as $r)<span class="chip">{{ $r }}</span>@empty<span class="text-muted-2 fs-sm">Anywhere</span>@endforelse</td>
                <td>@forelse($o->segmentList() as $s)<span class="chip">{{ $s }}</span>@empty<span class="text-muted-2 fs-sm">Any</span>@endforelse</td>
                <td><div class="d-flex justify-content-between fs-xs mb-1"><span>{{ $t }} of {{ $o->capacity_per_day }}</span>@unless($o->active)<span class="tag">paused</span>@endunless</div><div class="cap {{ $t >= $o->capacity_per_day ? 'full' : '' }}"><i style="width: {{ min(100, $t / max(1, $o->capacity_per_day) * 100) }}%"></i></div></td>
                <td class="text-end num">{{ $week[$o->id] ?? 0 }} <span class="text-muted-2">({{ $hot[$o->id] ?? 0 }} hot)</span></td>
                <td class="mono text-muted-2">{{ $o->crm_owner_id ?? '—' }}</td>
                @if($admin)<td class="text-end"><button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#ownerModal" data-owner="{{ json_encode($o->only(['id', 'name', 'email', 'regions', 'segments', 'capacity_per_day', 'crm_owner_id', 'active'])) }}">Edit</button></td>@endif
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</section>

<p class="fs-sm text-muted-2 mt-3">Regions are two-letter country codes. An owner with no regions covers anywhere and is used when nobody covers the lead's country. Segments are <b>enterprise</b> (hot leads and senior titles) and <b>smb</b>.</p>

@if($admin)
<div class="modal fade" id="ownerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" id="ownerForm" action="{{ route('owners.store') }}">
        @csrf <input type="hidden" name="_method" value="POST" id="ownerMethod">
        <div class="modal-header border-0 pb-0"><h2 class="modal-title fs-6" id="ownerTitle">Add owner</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-6"><label class="form-label" for="o-name">Name</label><input class="form-control" id="o-name" name="name" required maxlength="120"></div>
                <div class="col-6"><label class="form-label" for="o-email">Email</label><input class="form-control" id="o-email" type="email" name="email" required></div>
                <div class="col-6"><label class="form-label" for="o-regions">Regions</label><input class="form-control" id="o-regions" name="regions" placeholder="US, CA"><div class="form-text">Empty = anywhere</div></div>
                <div class="col-6"><label class="form-label" for="o-segments">Segments</label><input class="form-control" id="o-segments" name="segments" placeholder="enterprise, smb"><div class="form-text">Empty = any</div></div>
                <div class="col-6"><label class="form-label" for="o-cap">Leads per day</label><input class="form-control" id="o-cap" type="number" min="1" max="500" name="capacity_per_day" value="20" required></div>
                <div class="col-6"><label class="form-label" for="o-crm">CRM owner id</label><input class="form-control" id="o-crm" name="crm_owner_id" placeholder="own_101"></div>
                <div class="col-12" id="activeRow"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="active" value="1" id="o-active" checked><label class="form-check-label" for="o-active">Receives new leads</label></div></div>
            </div>
        </div>
        <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Save owner</button></div>
    </form></div>
</div>
<script>
document.getElementById('ownerModal').addEventListener('show.bs.modal', (e) => {
    const o = JSON.parse(e.relatedTarget.dataset.owner), f = document.getElementById('ownerForm'), isNew = !o.id;
    f.action = isNew ? @json(route('owners.store')) : @json(url('/owners')) + '/' + o.id;
    document.getElementById('ownerMethod').value = isNew ? 'POST' : 'PUT';
    document.getElementById('ownerTitle').textContent = isNew ? 'Add owner' : 'Edit ' + o.name;
    document.getElementById('activeRow').style.display = isNew ? 'none' : '';
    for (const [id, key, d] of [['o-name', 'name', ''], ['o-email', 'email', ''], ['o-regions', 'regions', ''], ['o-segments', 'segments', ''], ['o-cap', 'capacity_per_day', 20], ['o-crm', 'crm_owner_id', '']]) document.getElementById(id).value = o[key] ?? d;
    document.getElementById('o-active').checked = isNew ? true : !!o.active;
});
</script>
@endif
@endsection
