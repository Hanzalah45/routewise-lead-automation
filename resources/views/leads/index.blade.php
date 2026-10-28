@extends('layouts.app')
@section('title', 'Leads')

@section('content')
@php
    $tabs = ['' => ['All', $total], 'attention' => ['Needs attention', $attention], 'synced' => ['In CRM', $counts['synced'] ?? 0], 'retrying' => ['Retrying', $counts['retrying'] ?? 0],
             'dead' => ['Dead', $counts['dead'] ?? 0], 'held' => ['Held', $counts['held'] ?? 0], 'duplicate' => ['Duplicates', $counts['duplicate'] ?? 0], 'spam' => ['Spam', $counts['spam'] ?? 0]];
    $current = request('status', '');
@endphp
<x-page-head title="Leads" sub="Every lead that reached a webhook source, with its score, owner and CRM status."/>

<section class="panel panel-flush">
    <div class="status-tabs" role="tablist">
        @foreach($tabs as $key => [$label, $n])
            <a href="{{ route('leads.index', array_filter(['status' => $key] + request()->except('status', 'page'))) }}" class="{{ $current === (string) $key ? 'active' : '' }}">{{ $label }}<b>{{ $n }}</b></a>
        @endforeach
    </div>

    <form method="get" class="filter-bar" data-autosubmit>
        @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        <input type="search" name="q" class="form-control" placeholder="Name, email, company" value="{{ request('q') }}" aria-label="Search">
        <select name="tier" class="form-select" aria-label="Tier"><option value="">Any tier</option>@foreach(['hot', 'warm', 'cold', 'spam'] as $t)<option value="{{ $t }}" @selected(request('tier') === $t)>{{ ucfirst($t) }}</option>@endforeach</select>
        <select name="source" class="form-select" aria-label="Source"><option value="">Any source</option>@foreach($sources as $s)<option value="{{ $s->id }}" @selected(request('source') == $s->id)>{{ $s->name }}</option>@endforeach</select>
        <select name="owner" class="form-select" aria-label="Owner"><option value="">Any owner</option><option value="none" @selected(request('owner') === 'none')>Unassigned</option>@foreach($owners as $o)<option value="{{ $o->id }}" @selected(request('owner') == $o->id)>{{ $o->name }}</option>@endforeach</select>
        <button class="btn btn-primary btn-sm">Apply</button>
        @if(request()->hasAny(['q', 'tier', 'source', 'owner']))<a href="{{ route('leads.index', array_filter(['status' => request('status')])) }}" class="btn btn-link btn-sm">Clear</a>@endif
    </form>

    <div class="table-scroll">
    <table class="table table-clean table-hover align-middle">
        <thead><tr><th>Lead</th><th>Score</th><th>Source</th><th>Owner</th><th>Status</th><th class="text-end">Received</th></tr></thead>
        <tbody>
        @forelse($leads as $lead)
            <tr class="row-link" data-href="{{ route('leads.show', $lead) }}">
                <td>
                    <a class="cell-main" href="{{ route('leads.show', $lead) }}">{{ $lead->fullName() }}</a>
                    <span class="cell-sub">{{ $lead->company ?: ($lead->email ?? 'No email') }}@if($lead->company && $lead->email) · {{ $lead->email }}@endif</span>
                </td>
                <td><div class="d-flex align-items-center gap-2"><x-score :lead="$lead"/><x-tier :tier="$lead->tier"/></div></td>
                <td class="text-nowrap">{{ $lead->source?->name }}</td>
                <td><x-owner :owner="$lead->owner"/></td>
                <td>
                    <x-status :status="$lead->status"/>
                    @if($lead->status === 'retrying' && $lead->next_retry_at)<span class="cell-sub mt-1">next try {{ $lead->next_retry_at->diffForHumans() }}</span>@endif
                </td>
                <td class="text-end text-nowrap"><span class="cell-sub" title="{{ $lead->received_at }}">{{ $lead->received_at->diffForHumans() }}</span></td>
            </tr>
        @empty
            <tr><td colspan="6"><div class="empty"><i class="bi bi-inbox"></i>No leads match these filters.</div></td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    @if($leads->hasPages())<div class="p-3 border-top">{{ $leads->links() }}</div>@endif
</section>
@endsection
