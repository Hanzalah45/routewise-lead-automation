@extends('layouts.app')
@section('title', 'Scoring rules')

@section('content')
@php($admin = auth()->user()->isAdmin())
<x-page-head title="Scoring rules" sub="Each rule adds or removes points. A lead's score is the sum, clamped to 0 to 100. Change a value and try a lead on the right."/>

<div class="row g-3">
    <div class="col-xl-7">
        <form method="post" action="{{ route('scoring.update') }}" class="panel panel-flush">
            @csrf @method('PUT')
            <div class="panel-head"><div><h2>Rules</h2><div class="sub">Hot from {{ $tiers['hot'] }} points, warm from {{ $tiers['warm'] }}. Spam signals override the tier.</div></div>
                @if($admin)<button class="btn btn-primary btn-sm" type="submit">Save rules</button>@endif</div>
            @unless($admin)<div class="px-3 pt-3"><div class="demo-banner sim-note mb-0" role="note"><i class="bi bi-lock"></i><div>Read-only. Only admins can edit rules.</div></div></div>@endunless
            <div class="table-scroll"><table class="table table-clean align-middle">
                <thead><tr><th>Rule</th><th style="width:110px">Points</th><th style="width:80px">On</th></tr></thead>
                <tbody>
                @foreach($rules as $rule)
                    <tr>
                        <td><span class="cell-main">{{ $rule->label }}</span><span class="cell-sub">{{ $rule->description }}</span></td>
                        <td><input type="number" class="form-control form-control-sm num" name="rules[{{ $rule->id }}][points]" value="{{ $rule->points }}" min="-100" max="100" @disabled(! $admin) aria-label="Points for {{ $rule->label }}"></td>
                        <td><div class="form-check form-switch"><input type="hidden" name="rules[{{ $rule->id }}][active]" value="0"><input class="form-check-input" type="checkbox" role="switch" name="rules[{{ $rule->id }}][active]" value="1" @checked($rule->active) @disabled(! $admin) aria-label="{{ $rule->label }} enabled"></div></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </form>
    </div>

    <div class="col-xl-5">
        <section class="panel mb-3">
            <div class="panel-head"><div><h2>Try a lead</h2><div class="sub">Scores a made-up lead. Nothing is stored.</div></div></div>
            <form class="panel-body" id="simulator" action="{{ route('scoring.simulate') }}" method="post">
                @csrf
                <div class="row g-2">
                    <div class="col-6"><label class="form-label" for="s-name">Name</label><input id="s-name" class="form-control form-control-sm" name="name" value="Elena Fischer"></div>
                    <div class="col-6"><label class="form-label" for="s-email">Email</label><input id="s-email" class="form-control form-control-sm" name="email" value="elena@northbeam-logistics.example"></div>
                    <div class="col-6"><label class="form-label" for="s-company">Company</label><input id="s-company" class="form-control form-control-sm" name="company" value="Northbeam Logistics"></div>
                    <div class="col-6"><label class="form-label" for="s-title">Job title</label><input id="s-title" class="form-control form-control-sm" name="job_title" value="Head of Operations"></div>
                    <div class="col-6"><label class="form-label" for="s-phone">Phone</label><input id="s-phone" class="form-control form-control-sm" name="phone" value="+49 30 5550 1187"></div>
                    <div class="col-6"><label class="form-label" for="s-country">Country</label><input id="s-country" class="form-control form-control-sm" name="country" value="DE"></div>
                    <div class="col-12"><label class="form-label" for="s-msg">Message</label><textarea id="s-msg" class="form-control form-control-sm" name="message" rows="3">We need pricing for about 40 seats and would like a demo this week.</textarea></div>
                </div>
            </form>
        </section>
        <section class="panel"><div class="panel-body" id="simResult" style="transition: opacity .15s"><div class="text-muted-2">Scoring…</div></div></section>
    </div>
</div>
@endsection
