@extends('layouts.app')
@section('title', $lead->fullName())

@section('content')
@php($canRetry = $lead->canRetry())
<nav aria-label="breadcrumb" class="mb-2"><a href="{{ route('leads.index') }}" class="fs-sm text-muted-2"><i class="bi bi-arrow-left me-1"></i>All leads</a></nav>

<div class="page-head">
    <div class="d-flex gap-3 align-items-center">
        <span class="avatar lg">{{ strtoupper(mb_substr($lead->first_name ?: ($lead->email ?? '?'), 0, 1)) }}{{ strtoupper(mb_substr($lead->last_name ?? '', 0, 1)) }}</span>
        <div>
            <h1 class="mb-1">{{ $lead->fullName() }}</h1>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <x-tier :tier="$lead->tier"/><x-status :status="$lead->status"/>
                <span class="text-muted-2 fs-sm">{{ collect([$lead->job_title, $lead->company])->filter()->implode(' at ') ?: 'No company given' }}</span>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if($canRetry)
            <form method="post" action="{{ route('leads.retry', $lead) }}" data-busy="Retrying">@csrf<button class="btn btn-accent btn-sm" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Retry CRM sync</button></form>
        @endif
        <form method="post" action="{{ route('leads.rescore', $lead) }}">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-calculator me-1"></i>Re-score</button></form>
        @unless($lead->status === 'spam')
            <form method="post" action="{{ route('leads.spam', $lead) }}" data-confirm="This lead will be marked as spam and removed from the retry queue. It stays in the CRM if it was already saved there." data-confirm-title="Mark as spam?" data-confirm-ok="Mark as spam">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-slash-circle me-1"></i>Mark as spam</button></form>
        @endunless
    </div>
</div>

@if($lead->status === 'dead')
    <div class="demo-banner" style="background:var(--bad-bg);border-color:#f1c4bd;color:#7c231a" role="alert"><i class="bi bi-x-octagon-fill"></i><div><strong>This lead is not in the CRM.</strong> {{ $lead->last_error }} Fix the cause, then use <b>Retry CRM sync</b>.</div></div>
@elseif($lead->status === 'retrying')
    <div class="demo-banner" role="status"><i class="bi bi-arrow-repeat"></i><div><strong>Waiting to retry.</strong> Attempt {{ $lead->sync_attempts }} of {{ config('routewise.crm.max_attempts') }} failed: {{ $lead->last_error }} Next attempt {{ $lead->next_retry_at?->diffForHumans() }}.</div></div>
@elseif($lead->status === 'held')
    <div class="demo-banner" role="note"><i class="bi bi-hand-index-thumb"></i><div><strong>Held.</strong> This person did not consent to be contacted, so the lead is stored but never routed or sent to the CRM.</div></div>
@elseif($lead->status === 'duplicate' && $lead->duplicateOf)
    <div class="demo-banner sim-note" role="note"><i class="bi bi-files"></i><div><strong>Duplicate.</strong> Same person as <a href="{{ route('leads.show', $lead->duplicateOf) }}">{{ $lead->duplicateOf->fullName() }}</a>. It was not sent to the CRM again.</div></div>
@endif

<div class="row g-3">
    <div class="col-xl-7">
        <section class="panel mb-3">
            <div class="panel-head"><h2>Contact</h2><span class="mono text-muted-2">{{ $lead->uuid }}</span></div>
            <div class="panel-body">
                <dl class="kv">
                    <dt>Email</dt><dd>{{ $lead->email ?? '—' }} @if($lead->is_free_email)<span class="tag ms-1">personal provider</span>@endif @if($lead->company_domain)<span class="tag ok ms-1">company domain</span>@endif</dd>
                    <dt>Phone</dt><dd>{{ $lead->phone ?? '—' }} @if($lead->phone_e164)<span class="mono text-muted-2 ms-1">{{ $lead->phone_e164 }}</span>@elseif($lead->phone)<span class="tag warn ms-1">could not normalise</span>@endif</dd>
                    <dt>Company</dt><dd>{{ $lead->company ?? '—' }}</dd>
                    <dt>Job title</dt><dd>{{ $lead->job_title ?? '—' }}</dd>
                    <dt>Country</dt><dd>{{ $lead->country ?? '—' }}</dd>
                    <dt>Consent</dt><dd>@if($lead->consent === true)<span class="tag ok">Given</span>@elseif($lead->consent === false)<span class="tag bad">Refused</span>@else<span class="tag">Not stated</span>@endif</dd>
                    <dt>Source</dt><dd>{{ $lead->source?->name }} @if($lead->external_id)<span class="mono text-muted-2 ms-1">{{ $lead->external_id }}</span>@endif</dd>
                    <dt>Received</dt><dd>{{ $lead->received_at->format('D j M Y, H:i:s') }} <span class="text-muted-2">({{ $lead->received_at->diffForHumans() }})</span></dd>
                </dl>
                @if($lead->message)
                    <hr>
                    <div class="eyebrow mb-1">Message</div>
                    <p class="mb-0" style="white-space: pre-line">{{ $lead->message }}</p>
                @endif
            </div>
        </section>

        <section class="panel mb-3">
            <div class="panel-head"><h2>Score</h2><span class="sub">Rule based. Every point is listed.</span></div>
            <div class="panel-body">
                <div class="score-total mb-3"><span class="big">{{ $lead->score }}</span><x-tier :tier="$lead->tier"/><span class="text-muted-2 fs-sm">hot from {{ config('routewise.tiers.hot') }}, warm from {{ config('routewise.tiers.warm') }}</span></div>
                @forelse($lead->score_breakdown ?? [] as $line)
                    <div class="score-line"><div><div class="fw-medium">{{ $line['label'] }}</div><div class="fs-xs text-muted-2">{{ $line['detail'] }}</div></div><div class="pts {{ $line['points'] >= 0 ? 'pos' : 'neg' }}">{{ $line['points'] > 0 ? '+' : '' }}{{ $line['points'] }}</div></div>
                @empty
                    <div class="text-muted-2">No scoring rule matched this lead.</div>
                @endforelse
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Original payload</h2><button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#payload">Show</button></div>
            <div class="collapse" id="payload"><div class="panel-body relative">
                <button class="btn btn-sm btn-outline-secondary copy-btn" type="button" data-copy="#payloadText" style="background:#fff"><i class="bi bi-clipboard"></i> Copy</button>
                <pre class="code" id="payloadText">{{ json_encode($lead->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </div></div>
        </section>
    </div>

    <div class="col-xl-5">
        <section class="panel mb-3">
            <div class="panel-head"><h2>Owner and CRM</h2></div>
            <div class="panel-body">
                <form method="post" action="{{ route('leads.assign', $lead) }}" class="d-flex gap-2 mb-3">
                    @csrf
                    <select name="owner_id" class="form-select form-select-sm" aria-label="Owner"><option value="">Unassigned</option>@foreach($owners as $o)<option value="{{ $o->id }}" @selected($lead->owner_id === $o->id)>{{ $o->name }}@if($o->regions) ({{ $o->regions }})@endif</option>@endforeach</select>
                    <button class="btn btn-outline-secondary btn-sm" type="submit">Reassign</button>
                </form>
                <dl class="kv">
                    <dt>Contact id</dt><dd>@if($lead->crm_contact_id)<span class="mono">{{ $lead->crm_contact_id }}</span>@else<span class="text-muted-2">Not created</span>@endif</dd>
                    <dt>Deal id</dt><dd>@if($lead->crm_deal_id)<span class="mono">{{ $lead->crm_deal_id }}</span>@else<span class="text-muted-2">Not created</span>@endif</dd>
                    <dt>Attempts</dt><dd>{{ $lead->sync_attempts }} of {{ config('routewise.crm.max_attempts') }}</dd>
                    @if($lead->synced_at)<dt>Saved</dt><dd>{{ $lead->synced_at->format('j M, H:i:s') }}</dd>@endif
                </dl>
            </div>
        </section>

        <section class="panel mb-3">
            <div class="panel-head"><h2>Timeline</h2></div>
            <ul class="timeline">
                @foreach($lead->events as $event)
                    <li class="t-{{ $event->type }}"><span class="when">{{ $event->created_at->format('j M, H:i:s') }}</span>{{ $event->message }}</li>
                @endforeach
            </ul>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>CRM attempts</h2></div>
            @if($lead->attempts->isEmpty())
                <div class="empty"><i class="bi bi-cloud-slash"></i>No CRM call has been made for this lead.</div>
            @else
                <div class="table-scroll"><table class="table table-clean">
                    <thead><tr><th>#</th><th>Result</th><th>HTTP</th><th class="text-end">Time</th></tr></thead>
                    <tbody>
                    @foreach($lead->attempts as $a)
                        <tr><td>{{ $a->attempt }}</td>
                            <td><span class="tag {{ $a->outcome === 'success' ? 'ok' : ($a->outcome === 'retry' ? 'warn' : 'bad') }}">{{ str_replace('_', ' ', $a->outcome) }}</span>@if($a->error)<span class="cell-sub">{{ $a->error }}</span>@endif</td>
                            <td class="mono">{{ $a->http_status ?? '—' }}</td><td class="text-end num">{{ $a->duration_ms }} ms</td></tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    </div>
</div>
@endsection
