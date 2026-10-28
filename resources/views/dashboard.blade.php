@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<x-page-head title="Dashboard" sub="What came in over the last 14 days, where it went, and what needs a person.">
    <a href="{{ route('leads.index', ['status' => 'attention']) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-exclamation-triangle me-1"></i>Needs attention</a>
    <a href="{{ route('sources.index') }}" class="btn btn-primary btn-sm"><i class="bi bi-plug me-1"></i>Webhook sources</a>
</x-page-head>

<div class="kpis">
    <div class="kpi"><div class="label">Leads received</div><div class="value">{{ $kpis['received'] }}</div><div class="note">{{ $kpis['received_today'] }} today</div><x-spark :values="$spark['received']"/></div>
    <div class="kpi"><div class="label">Hot leads</div><div class="value">{{ $kpis['hot'] }}</div><div class="note">{{ $kpis['hot_rate'] }}% of qualified leads</div><x-spark :values="$spark['hot']" color="#B77D08"/></div>
    <div class="kpi"><div class="label">CRM sync success</div><div class="value">@if($kpis['sync_rate'] === null)&mdash;@else{{ $kpis['sync_rate'] }}<small>%</small>@endif</div>
        <div class="note">{{ $kpis['synced'] }} saved{{ $kpis['median_seconds'] !== null ? ', median '.$kpis['median_seconds'].' s to CRM' : '' }}</div><x-spark :values="$spark['synced']" color="#1F8A5B"/></div>
    <div class="kpi {{ $kpis['attention'] > 0 ? 'alert' : '' }}"><div class="label">Needs attention</div><div class="value">{{ $kpis['attention'] }}</div>
        <div class="note d-flex gap-1 flex-wrap mt-1">@foreach(['dead' => 'bad', 'retrying' => 'warn', 'held' => ''] as $k => $c)@if($attentionBreakdown[$k] > 0)<span class="tag {{ $c }}">{{ $attentionBreakdown[$k] }} {{ $k }}</span>@endif @endforeach</div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <section class="panel h-100">
            <div class="panel-head">
                <div><h2>Lead flow</h2><div class="sub">Leads received per day, by tier</div></div>
                <div class="legend"><span><i style="background:var(--saffron)"></i>Hot</span><span><i style="background:#f3d58b"></i>Warm</span><span><i style="background:#c7cfde"></i>Cold</span><span><i style="background:#e7a89f"></i>Spam</span></div>
            </div>
            <div class="panel-body">
                @php
                    $max = max(4, collect($series)->max('total'));
                    $w = 720; $h = 300; $padL = 28; $padB = 24; $plotH = $h - $padB - 8; $step = ($w - $padL) / count($series); $bw = $step * 0.58;
                @endphp
                <svg class="chart" viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="Leads received per day for the last 14 days">
                    @foreach([0, 0.5, 1] as $g)
                        @php($y = 8 + $plotH * (1 - $g))
                        <line class="grid" x1="{{ $padL }}" x2="{{ $w }}" y1="{{ $y }}" y2="{{ $y }}"/>
                        <text x="0" y="{{ $y + 3 }}">{{ (int) round($max * $g) }}</text>
                    @endforeach
                    @foreach($series as $i => $d)
                        @php($x = $padL + $i * $step + ($step - $bw) / 2)
                        @php($base = 8 + $plotH)
                        @foreach(['hot' => 'b-hot', 'warm' => 'b-warm', 'cold' => 'b-cold', 'other' => 'b-spam'] as $key => $class)
                            @if($d[$key] > 0)
                                @php($bh = $plotH * $d[$key] / $max)
                                @php($base -= $bh)
                                <rect class="{{ $class }}" x="{{ round($x, 1) }}" y="{{ round($base, 1) }}" width="{{ round($bw, 1) }}" height="{{ round($bh, 1) }}" rx="2"><title>{{ $d['date']->format('M j') }}: {{ $d[$key] }} {{ $key === 'other' ? 'spam' : $key }}</title></rect>
                            @endif
                        @endforeach
                        @if($i % 2 === 0)<text x="{{ round($x + $bw / 2, 1) }}" y="{{ $h - 6 }}" text-anchor="middle">{{ $d['date']->format('M j') }}</text>@endif
                    @endforeach
                </svg>
            </div>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="panel h-100">
            <div class="panel-head"><div><h2>Funnel</h2><div class="sub">Last 14 days</div></div></div>
            <div class="panel-body">
                @php($top = max(1, $funnel[0]['value']))
                @foreach($funnel as $row)
                    <div class="funnel-row {{ $loop->last ? 'final' : '' }}">
                        <div>{{ $row['label'] }}@isset($row['note'])<div class="fs-xs text-muted-2">{{ $row['note'] }}</div>@endisset</div>
                        <div class="track"><i style="width: {{ round($row['value'] / $top * 100) }}%"></i></div>
                        <div class="v">{{ $row['value'] }}</div>
                    </div>
                @endforeach
                <hr class="my-3">
                <div class="eyebrow mb-2">Tier mix</div>
                @php($tt = max(1, array_sum($tiers)))
                <div class="stack-bar mb-2" aria-hidden="true">
                    <i style="width:{{ $tiers['hot'] / $tt * 100 }}%;background:var(--saffron)"></i><i style="width:{{ $tiers['warm'] / $tt * 100 }}%;background:#f3d58b"></i><i style="width:{{ $tiers['cold'] / $tt * 100 }}%;background:#c7cfde"></i><i style="width:{{ $tiers['spam'] / $tt * 100 }}%;background:#e7a89f"></i>
                </div>
                <div class="tier-grid">
                    @foreach($tiers as $name => $count)<div class="mix-row"><span>{{ ucfirst($name) }}</span><b>{{ $count }}</b></div>@endforeach
                </div>
            </div>
        </section>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-5">
        <section class="panel h-100">
            <div class="panel-head"><div><h2>Needs attention</h2><div class="sub">A person has to look at these</div></div><a href="{{ route('leads.index', ['status' => 'attention']) }}" class="fs-sm">View all</a></div>
            @forelse($attention as $lead)
                <a class="attention-item" href="{{ route('leads.show', $lead) }}">
                    <span class="avatar ghost"><i class="bi {{ $lead->status === 'dead' ? 'bi-x-octagon' : ($lead->status === 'retrying' ? 'bi-arrow-repeat' : 'bi-hand-index') }}"></i></span>
                    <div class="grow"><span class="cell-main text-truncate">{{ $lead->fullName() }}</span><span class="cell-sub text-truncate">{{ $lead->status === 'held' ? 'No consent to contact' : (\Illuminate\Support\Str::limit($lead->last_error, 70) ?: 'No owner available') }}</span></div>
                    <x-status :status="$lead->status"/>
                </a>
            @empty
                <div class="empty"><i class="bi bi-check2-circle"></i>Nothing needs attention.</div>
            @endforelse
        </section>
    </div>
    <div class="col-xl-4">
        <section class="panel h-100">
            <div class="panel-head"><div><h2>Sources</h2><div class="sub">Leads in the last 14 days</div></div></div>
            <div class="panel-body">
                @php($smax = max(1, collect($sources)->max('count')))
                @foreach($sources as $s)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between fs-sm mb-1"><span class="fw-medium">{{ $s['name'] }}</span><span class="num"><b>{{ $s['count'] }}</b> <span class="text-muted-2">· {{ $s['hot'] }} hot</span></span></div>
                        <div class="cap"><i style="width: {{ $s['count'] / $smax * 100 }}%"></i></div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
    <div class="col-xl-3">
        <section class="panel h-100">
            <div class="panel-head"><div><h2>CRM calls</h2><div class="sub">Last 24 hours</div></div></div>
            <div class="panel-body">
                <div class="mix-row"><span><i class="bi bi-check-circle text-success me-1"></i>Succeeded</span><b>{{ $attempts['success'] }}</b></div>
                <div class="mix-row"><span><i class="bi bi-arrow-repeat me-1" style="color:var(--warn)"></i>Failed, will retry</span><b>{{ $attempts['retry'] }}</b></div>
                <div class="mix-row"><span><i class="bi bi-x-circle text-danger me-1"></i>Rejected</span><b>{{ $attempts['failed'] }}</b></div>
                @php($age = \App\Services\SchedulerHealth::ageSeconds())
                <div class="mt-3 pt-3 border-top fs-xs">
                    <div class="d-flex justify-content-between align-items-center"><span class="fw-medium">Retry checker</span>
                        @if($age === null)<span class="tag warn">never ran</span>@elseif(\App\Services\SchedulerHealth::isStale())<span class="tag warn">idle {{ (int) round($age / 60) }} min</span>@else<span class="tag ok">ran {{ $age }} s ago</span>@endif</div>
                    <p class="text-muted-2 mt-2 mb-0">Backoff is 30 s, 2 min, 10 min, 30 min; after five tries a lead needs attention. Retries run from the scheduler (<span class="mono">schedule:work</span>) or the worker (<span class="mono">leads:sync-pending --loop</span>), and also after page visits and incoming leads.</p>
                </div>
            </div>
        </section>
    </div>
</div>
@endsection
