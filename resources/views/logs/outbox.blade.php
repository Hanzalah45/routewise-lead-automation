@extends('layouts.app')
@section('title', 'Outbox')

@section('content')
<x-page-head title="Outbox" sub="Notifications the pipeline would send when a lead is routed."/>
<div class="demo-banner sim-note" role="note"><i class="bi bi-shield-lock"></i><div><strong>Simulated.</strong> Nothing is sent to Slack or by email. Messages are recorded here so you can see exactly what a real integration would send and to whom.</div></div>

<section class="panel panel-flush">
    <div class="status-tabs">
        <a href="{{ route('outbox.index') }}" class="{{ request('channel') ? '' : 'active' }}">All</a>
        <a href="{{ route('outbox.index', ['channel' => 'slack']) }}" class="{{ request('channel') === 'slack' ? 'active' : '' }}">Slack</a>
        <a href="{{ route('outbox.index', ['channel' => 'email']) }}" class="{{ request('channel') === 'email' ? 'active' : '' }}">Email</a>
    </div>
    @forelse($messages as $m)
        <div class="attention-item" style="align-items:flex-start">
            <span class="avatar ghost"><i class="bi {{ $m->channel === 'slack' ? 'bi-slack' : 'bi-envelope' }}"></i></span>
            <div class="grow">
                <div class="d-flex justify-content-between gap-3"><span class="cell-main">{{ $m->subject }}</span><span class="cell-sub text-nowrap">{{ $m->created_at->diffForHumans() }}</span></div>
                <span class="cell-sub">To <span class="mono">{{ $m->recipient }}</span></span>
                <div class="mt-1 fs-sm">{{ $m->body }}</div>
                @if($m->lead)<a class="fs-xs" href="{{ route('leads.show', $m->lead) }}">Open lead</a>@endif
            </div>
        </div>
    @empty
        <div class="empty"><i class="bi bi-send"></i>No notifications yet.</div>
    @endforelse
    @if($messages->hasPages())<div class="p-3 border-top">{{ $messages->links() }}</div>@endif
</section>
@endsection
