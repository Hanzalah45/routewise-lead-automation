<?php

namespace App\Services;

use App\Models\CrmSyncAttempt;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Support\Carbon;

/** Numbers for the dashboard. Everything is computed from stored leads, nothing is estimated. */
class DashboardMetrics
{
    public const DAYS = 14;

    /** @return array<string,mixed> */
    public function build(): array
    {
        $since = now()->subDays(self::DAYS - 1)->startOfDay();
        $recent = Lead::where('received_at', '>=', $since);

        $received = (clone $recent)->count();
        $qualified = (clone $recent)->whereNotIn('status', [Lead::STATUS_SPAM, Lead::STATUS_DUPLICATE, Lead::STATUS_HELD])->count();
        $routed = (clone $recent)->whereNotNull('owner_id')->whereNotIn('status', [Lead::STATUS_SPAM, Lead::STATUS_DUPLICATE, Lead::STATUS_HELD])->count();
        $synced = (clone $recent)->where('status', Lead::STATUS_SYNCED)->count();
        $hot = (clone $recent)->where('tier', 'hot')->count();

        $attempted = (clone $recent)->whereIn('status', [Lead::STATUS_SYNCED, Lead::STATUS_RETRYING, Lead::STATUS_DEAD])->count();

        $times = (clone $recent)->where('status', Lead::STATUS_SYNCED)->whereNotNull('synced_at')->get(['received_at', 'synced_at'])
            ->map(fn ($l) => $l->received_at->diffInSeconds($l->synced_at, true))->sort()->values();
        $median = $times->isEmpty() ? null : $times[(int) floor(($times->count() - 1) / 2)];

        return [
            'kpis' => [
                'received' => $received,
                'received_today' => Lead::where('received_at', '>=', now()->startOfDay())->count(),
                'hot' => $hot,
                'hot_rate' => $qualified > 0 ? round($hot / $qualified * 100) : 0,
                'sync_rate' => $attempted > 0 ? round($synced / $attempted * 100, 1) : null,
                'synced' => $synced,
                'median_seconds' => $median,
                'attention' => $this->attention()->count(),
            ],
            'series' => $series = $this->series($since),
            'spark' => [
                'received' => array_column($series, 'total'),
                'hot' => array_column($series, 'hot'),
                'synced' => $this->syncedPerDay($since),
            ],
            'attentionBreakdown' => [
                'dead' => Lead::where('status', Lead::STATUS_DEAD)->count(),
                'retrying' => Lead::where('status', Lead::STATUS_RETRYING)->count(),
                'held' => Lead::where('status', Lead::STATUS_HELD)->count(),
            ],
            'funnel' => [
                ['label' => 'Received', 'value' => $received],
                ['label' => 'Qualified', 'value' => $qualified, 'note' => 'not spam, duplicate or held'],
                ['label' => 'Routed to an owner', 'value' => $routed],
                ['label' => 'Saved in the CRM', 'value' => $synced],
            ],
            'tiers' => $this->tiers($since),
            'sources' => $this->sources($since),
            'attempts' => $this->attempts(),
            'attention' => $this->attention()->take(6),
        ];
    }

    /** @return list<array{date:Carbon,hot:int,warm:int,cold:int,other:int,total:int}> */
    private function series(Carbon $since): array
    {
        $rows = Lead::where('received_at', '>=', $since)->get(['received_at', 'tier']);
        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $since->copy()->addDays($i);
            $of = $rows->filter(fn ($l) => $l->received_at->isSameDay($day));
            $days[] = [
                'date' => $day,
                'hot' => $of->where('tier', 'hot')->count(), 'warm' => $of->where('tier', 'warm')->count(),
                'cold' => $of->where('tier', 'cold')->count(), 'other' => $of->where('tier', 'spam')->count(),
                'total' => $of->count(),
            ];
        }

        return $days;
    }

    /** @return list<int> leads saved to the CRM per day, oldest first */
    private function syncedPerDay(Carbon $since): array
    {
        $rows = Lead::whereNotNull('synced_at')->where('synced_at', '>=', $since)->get(['synced_at']);

        return collect(range(0, self::DAYS - 1))->map(fn ($i) => $rows->filter(fn ($l) => $l->synced_at->isSameDay($since->copy()->addDays($i)))->count())->all();
    }

    /** @return array<string,int> */
    private function tiers(Carbon $since): array
    {
        $counts = Lead::where('received_at', '>=', $since)->selectRaw('tier, count(*) c')->groupBy('tier')->pluck('c', 'tier');

        return ['hot' => (int) ($counts['hot'] ?? 0), 'warm' => (int) ($counts['warm'] ?? 0), 'cold' => (int) ($counts['cold'] ?? 0), 'spam' => (int) ($counts['spam'] ?? 0)];
    }

    /** @return list<array{name:string,count:int,hot:int}> */
    private function sources(Carbon $since): array
    {
        return LeadSource::orderBy('name')->get()->map(fn ($s) => [
            'name' => $s->name,
            'count' => $s->leads()->where('received_at', '>=', $since)->count(),
            'hot' => $s->leads()->where('received_at', '>=', $since)->where('tier', 'hot')->count(),
        ])->sortByDesc('count')->values()->all();
    }

    /** @return array{success:int,retry:int,failed:int} CRM calls in the last 24 hours */
    private function attempts(): array
    {
        $rows = CrmSyncAttempt::where('created_at', '>=', now()->subDay())->selectRaw('outcome, count(*) c')->groupBy('outcome')->pluck('c', 'outcome');

        return ['success' => (int) ($rows['success'] ?? 0), 'retry' => (int) ($rows['retry'] ?? 0), 'failed' => (int) ($rows['permanent_failure'] ?? 0)];
    }

    /** Leads that need a person: dead CRM syncs, retries waiting, no owner, no consent. */
    public function attention()
    {
        return Lead::with('owner')
            ->where(fn ($q) => $q->whereIn('status', [Lead::STATUS_DEAD, Lead::STATUS_RETRYING, Lead::STATUS_HELD])
                ->orWhere(fn ($w) => $w->whereNull('owner_id')->whereIn('status', [Lead::STATUS_ROUTED, Lead::STATUS_SYNCED])))
            ->orderByRaw("case status when 'dead' then 0 when 'retrying' then 1 when 'held' then 2 else 3 end")
            ->orderByDesc('received_at')->get();
    }
}
