@extends('layouts.app')
@section('title', 'Mock CRM')

@section('content')
<x-page-head title="Mock CRM" sub="A stand-in for HubSpot, Pipedrive or Salesforce, so the whole path can be shown and tested without a real account."/>

<div class="row g-3 mb-3">
    <div class="col-xl-5">
        <section class="panel h-100">
            <div class="panel-head"><div><h2>Failure simulator</h2><div class="sub">Make the CRM misbehave to watch retries, backoff and the dead-letter state.</div></div>
                @if($mode === 'ok')<span class="tag ok">Healthy</span>@else<span class="tag bad">Failing: {{ str_replace('_', ' ', $mode) }} · {{ $remaining < 0 ? 'until reset' : $remaining.' left' }}</span>@endif</div>
            <form class="panel-body" method="post" action="{{ route('mock-crm.update') }}">
                @csrf @method('PUT')
                <div class="mb-3">
                    <label class="form-label" for="mode">Answer with</label>
                    <select class="form-select" name="mode" id="mode">
                        <option value="rate_limited" @selected($mode === 'rate_limited')>429 Too Many Requests (retry later)</option>
                        <option value="down" @selected($mode === 'down')>503 Service Unavailable (retry later)</option>
                        <option value="reject" @selected($mode === 'reject')>422 Rejected (permanent, no retry)</option>
                        <option value="ok" @selected($mode === 'ok')>Healthy (clear any failure)</option>
                    </select>
                </div>
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-5"><label class="form-label" for="count">For the next</label><input class="form-control" id="count" type="number" name="count" min="1" max="50" value="1"></div>
                    <div class="col-7"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="until_reset" value="1" id="until"><label class="form-check-label" for="until">Fail until I reset it</label></div></div>
                </div>
                <button class="btn btn-primary btn-sm" type="submit">Apply</button>
                <p class="fs-xs text-muted-2 mt-3 mb-0">Then send a test lead from <a href="{{ route('sources.index') }}">Sources</a>. A failing CRM never loses a lead: it is stored first and retried (30 s, 2 min, 10 min, 30 min) before it moves to Needs attention.</p>
            </form>
        </section>
    </div>
    <div class="col-xl-7">
        <section class="panel h-100">
            <div class="panel-head"><div><h2>API</h2><div class="sub">Bearer token auth. The pipeline calls it in-process; the same endpoints answer over HTTP.</div></div></div>
            <div class="panel-body">
                <div class="row g-3 mb-3 fs-sm">
                    <div class="col-4"><div class="eyebrow">Contacts</div><div class="fs-4 fw-semibold num">{{ $totals['contacts'] }}</div></div>
                    <div class="col-4"><div class="eyebrow">Deals</div><div class="fs-4 fw-semibold num">{{ $totals['deals'] }}</div></div>
                    <div class="col-4"><div class="eyebrow">API calls</div><div class="fs-4 fw-semibold num">{{ $totals['requests'] }}</div></div>
                </div>
                <pre class="code mb-0">Base URL   {{ $endpoint }}
Auth       Authorization: Bearer {{ $token }}

POST /contacts   upsert by email           201 | 200 | 422
GET  /contacts   ?email=                   200 | 404
POST /deals      Idempotency-Key required  201 | 200 | 422</pre>
            </div>
        </section>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-4"><section class="panel panel-flush h-100">
        <div class="panel-head"><h2>Recent API calls</h2></div>
        <div class="table-scroll"><table class="table table-clean">
            <thead><tr><th>Call</th><th>HTTP</th></tr></thead>
            <tbody>
            @forelse($requests as $r)
                <tr><td><span class="mono">{{ $r->method }} {{ $r->path }}</span><span class="cell-sub">{{ $r->created_at->diffForHumans() }}@if($r->note) · {{ $r->note }}@endif</span></td>
                    <td><span class="tag {{ $r->status < 300 ? 'ok' : ($r->status === 429 || $r->status >= 500 ? 'warn' : 'bad') }}">{{ $r->status }}</span></td></tr>
            @empty
                <tr><td colspan="2"><div class="empty">No calls yet.</div></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section></div>
    <div class="col-xl-4"><section class="panel panel-flush h-100">
        <div class="panel-head"><h2>Contacts</h2></div>
        <div class="table-scroll"><table class="table table-clean">
            <thead><tr><th>Contact</th><th>Owner</th></tr></thead>
            <tbody>
            @forelse($contacts as $c)
                <tr><td><span class="cell-main">{{ trim($c->first_name.' '.$c->last_name) ?: $c->email }}</span><span class="cell-sub">{{ $c->email }} · <span class="mono">{{ $c->external_id }}</span></span></td><td class="mono text-muted-2">{{ $c->owner_id ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="2"><div class="empty">No contacts yet.</div></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section></div>
    <div class="col-xl-4"><section class="panel panel-flush h-100">
        <div class="panel-head"><h2>Deals</h2></div>
        <div class="table-scroll"><table class="table table-clean">
            <thead><tr><th>Deal</th><th>Stage</th></tr></thead>
            <tbody>
            @forelse($deals as $d)
                <tr><td><span class="cell-main">{{ $d->name }}</span><span class="cell-sub mono">{{ $d->external_id }} → {{ $d->contact_external_id }}</span></td><td><span class="tag">{{ $d->stage }}</span></td></tr>
            @empty
                <tr><td colspan="2"><div class="empty">No deals yet.</div></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section></div>
</div>
@endsection
