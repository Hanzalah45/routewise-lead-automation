@extends('layouts.app')
@section('title', 'Sources & webhooks')

@section('content')
@php($admin = auth()->user()->isAdmin())
@php($revealed = session('revealed', []))
@php($first = $sources->first())
<x-page-head title="Sources & webhooks" sub="Each source has its own endpoint and signing secret. Requests are rejected unless they carry a valid, recent signature.">
    @if($admin)<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#sourceModal"><i class="bi bi-plus-lg me-1"></i>New source</button>@endif
</x-page-head>

@unless($admin)<div class="demo-banner sim-note" role="note"><i class="bi bi-lock"></i><div>Read-only. Only admins can create sources, rotate secrets or send test leads.</div></div>@endunless

<div class="row g-3 mb-3">
    @foreach($sources as $s)
        <div class="col-xl-6">
            <section class="panel h-100">
                <div class="panel-head">
                    <div class="d-flex align-items-center gap-2"><h2>{{ $s->name }}</h2>@if($s->active)<span class="tag ok">Accepting</span>@else<span class="tag bad">Paused</span>@endif</div>
                    <span class="sub">{{ $s->leads_count }} leads · {{ $s->last_used_at ? 'last used '.$s->last_used_at->diffForHumans() : 'never used' }}</span>
                </div>
                <div class="panel-body">
                    <div class="eyebrow mb-1">Endpoint</div>
                    <div class="d-flex gap-2 align-items-center mb-3"><code class="mono flex-grow-1 text-break" id="ep-{{ $s->id }}">POST {{ $base }}/{{ $s->slug }}</code><button class="btn btn-outline-secondary btn-sm" data-copy="#ep-{{ $s->id }}" type="button"><i class="bi bi-clipboard"></i></button></div>

                    <div class="eyebrow mb-1">Signing secret</div>
                    @if(isset($revealed[$s->id]))
                        <div class="secret-box mb-1"><code id="sec-{{ $s->id }}">{{ $revealed[$s->id] }}</code><button class="btn btn-sm btn-outline-secondary" data-copy="#sec-{{ $s->id }}" type="button"><i class="bi bi-clipboard"></i> Copy</button></div>
                        <div class="fs-xs mb-3" style="color:#6d4b06">Shown once. Store it in your sender now; after you leave this page only the last four characters are visible.</div>
                    @else
                        <div class="mono mb-3">{{ $s->maskedSecret() }}</div>
                    @endif

                    @if($admin)
                    <div class="d-flex gap-2 flex-wrap">
                        <form method="post" action="{{ route('sources.test', $s) }}" data-busy="Sending">@csrf<button class="btn btn-accent btn-sm" type="submit" @disabled(! $s->active)><i class="bi bi-send me-1"></i>Send test lead</button></form>
                        <form method="post" action="{{ route('sources.toggle', $s) }}">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit">{{ $s->active ? 'Pause' : 'Resume' }}</button></form>
                        <form method="post" action="{{ route('sources.rotate', $s) }}" data-confirm="A new secret is generated and the current one stops working immediately. Update the sender straight after." data-confirm-title="Rotate the secret for {{ $s->name }}?" data-confirm-ok="Rotate secret">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Rotate secret</button></form>
                    </div>
                    @endif
                </div>
            </section>
        </div>
    @endforeach
</div>

<section class="panel mb-3">
    <div class="panel-head"><div><h2>How to sign a request</h2><div class="sub">Header <span class="mono">X-Routewise-Signature: t=&lt;unix seconds&gt;,v1=&lt;hex&gt;</span> where v1 is HMAC-SHA256 of <span class="mono">"&lt;t&gt;.&lt;raw body&gt;"</span> with the source secret. Requests more than {{ $tolerance }} s away from server time are rejected.</div></div></div>
    <div class="panel-body">
        <ul class="nav nav-pills mb-3 gap-1" role="tablist">
            <li class="nav-item"><button class="nav-link active btn-sm py-1" data-bs-toggle="pill" data-bs-target="#t-curl" type="button">curl</button></li>
            <li class="nav-item"><button class="nav-link btn-sm py-1" data-bs-toggle="pill" data-bs-target="#t-node" type="button">Node.js</button></li>
            <li class="nav-item"><button class="nav-link btn-sm py-1" data-bs-toggle="pill" data-bs-target="#t-php" type="button">PHP</button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active relative" id="t-curl"><button class="btn btn-sm btn-outline-secondary copy-btn" data-copy="#c-curl" type="button" style="background:#fff"><i class="bi bi-clipboard"></i></button><pre class="code" id="c-curl">SECRET='whsec_your_secret'
BODY='{"name":"Ada Lovelace","email":"ada@analytical.example","company":"Analytical Engines","message":"Pricing please","consent":true}'
TS=$(date +%s)
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.* //')

curl -X POST {{ $base }}/{{ $first?->slug ?? 'website' }} \
  -H "Content-Type: application/json" \
  -H "X-Routewise-Signature: t=$TS,v1=$SIG" \
  -d "$BODY"</pre></div>
            <div class="tab-pane fade relative" id="t-node"><button class="btn btn-sm btn-outline-secondary copy-btn" data-copy="#c-node" type="button" style="background:#fff"><i class="bi bi-clipboard"></i></button><pre class="code" id="c-node">import { createHmac } from 'node:crypto';

const body = JSON.stringify({ name: 'Ada Lovelace', email: 'ada@analytical.example', message: 'Pricing please', consent: true });
const t = Math.floor(Date.now() / 1000);
const v1 = createHmac('sha256', process.env.ROUTEWISE_SECRET).update(`${t}.${body}`).digest('hex');

const res = await fetch('{{ $base }}/{{ $first?->slug ?? 'website' }}', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-Routewise-Signature': `t=${t},v1=${v1}` },
  body, // send the exact string you signed
});
console.log(res.status, await res.json());</pre></div>
            <div class="tab-pane fade relative" id="t-php"><button class="btn btn-sm btn-outline-secondary copy-btn" data-copy="#c-php" type="button" style="background:#fff"><i class="bi bi-clipboard"></i></button><pre class="code" id="c-php">$body = json_encode(['name' => 'Ada Lovelace', 'email' => 'ada@analytical.example', 'message' => 'Pricing please', 'consent' => true]);
$t = time();
$v1 = hash_hmac('sha256', $t.'.'.$body, getenv('ROUTEWISE_SECRET'));

$ch = curl_init('{{ $base }}/{{ $first?->slug ?? 'website' }}');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-Routewise-Signature: t=$t,v1=$v1"]]);
echo curl_exec($ch);</pre></div>
        </div>
        <hr>
        <div class="row g-3 fs-sm">
            <div class="col-md-4"><b>Responses</b><div class="text-muted-2">201 accepted, 200 replay of a known external id, 401 bad or expired signature, 403 source paused, 404 unknown source, 413 too large, 422 invalid, 429 rate limited.</div></div>
            <div class="col-md-4"><b>Idempotency</b><div class="text-muted-2">Send <span class="mono">external_id</span> in the body or an <span class="mono">Idempotency-Key</span> header. The same value from the same source returns the original lead and creates nothing new.</div></div>
            <div class="col-md-4"><b>Fields</b><div class="text-muted-2">email or phone (one is required), name or first_name and last_name, company, job_title, country, message, consent, external_id. Unknown fields are kept in the stored payload.</div></div>
        </div>
    </div>
</section>

@if($admin)
<div class="modal fade" id="sourceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="{{ route('sources.store') }}">
        @csrf
        <div class="modal-header border-0 pb-0"><h2 class="modal-title fs-6">New source</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><label class="form-label" for="src-name">Name</label><input class="form-control" id="src-name" name="name" required maxlength="80" placeholder="e.g. LinkedIn lead gen forms"><div class="form-text">Creates an endpoint and a secret. The secret is shown once.</div></div>
        <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Create source</button></div>
    </form></div>
</div>
@endif
@endsection
