@extends('layouts.app')
@section('title', 'Deliveries')

@section('content')
@php($outcomes = ['accepted' => 'tag ok', 'duplicate_delivery' => 'tag info', 'invalid_signature' => 'tag bad', 'expired' => 'tag bad', 'validation_failed' => 'tag warn', 'unknown_source' => 'tag bad', 'inactive_source' => 'tag', 'too_large' => 'tag warn'])
<x-page-head title="Deliveries" sub="Every request that reached a webhook endpoint, including the ones that were refused. Payload content is not stored here, only a hash and its size."/>

<section class="panel panel-flush">
    <div class="status-tabs">
        <a href="{{ route('deliveries.index') }}" class="{{ request('outcome') ? '' : 'active' }}">All<b>{{ $accepted + $rejected }}</b></a>
        <a href="{{ route('deliveries.index', ['outcome' => 'accepted']) }}" class="{{ request('outcome') === 'accepted' ? 'active' : '' }}">Accepted<b>{{ $accepted }}</b></a>
        <a href="{{ route('deliveries.index', ['outcome' => 'rejected']) }}" class="{{ request('outcome') === 'rejected' ? 'active' : '' }}">Rejected<b>{{ $rejected }}</b></a>
    </div>
    <div class="table-scroll"><table class="table table-clean align-middle">
        <thead><tr><th>Time</th><th>Source</th><th>Outcome</th><th>HTTP</th><th>Lead</th><th>Sender IP</th><th class="text-end">Size</th></tr></thead>
        <tbody>
        @forelse($deliveries as $d)
            <tr>
                <td class="text-nowrap"><span class="cell-main">{{ $d->created_at->format('j M, H:i:s') }}</span><span class="cell-sub">{{ $d->created_at->diffForHumans() }}</span></td>
                <td>{{ $d->source?->name ?? $d->source_slug }}</td>
                <td><span class="{{ $outcomes[$d->outcome] ?? 'tag' }}">{{ str_replace('_', ' ', $d->outcome) }}</span>@if($d->error && $d->outcome !== 'accepted')<span class="cell-sub">{{ $d->error }}</span>@endif</td>
                <td class="mono">{{ $d->http_status }}</td>
                <td>@if($d->lead)<a href="{{ route('leads.show', $d->lead) }}">{{ $d->lead->fullName() }}</a>@else<span class="text-muted-2">—</span>@endif</td>
                <td class="mono text-muted-2">{{ $d->ip }}</td>
                <td class="text-end num text-muted-2">{{ number_format($d->payload_bytes) }} B</td>
            </tr>
        @empty
            <tr><td colspan="7"><div class="empty"><i class="bi bi-arrow-down-up"></i>No deliveries yet.</div></td></tr>
        @endforelse
        </tbody>
    </table></div>
    @if($deliveries->hasPages())<div class="p-3 border-top">{{ $deliveries->links() }}</div>@endif
</section>
@endsection
