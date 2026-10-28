<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Owner;

/**
 * Picks the owner for a lead.
 *   1. only active owners with capacity left today
 *   2. owners covering the lead's country (an owner with no regions covers anywhere); if none match, owners covering anywhere
 *   3. owners handling the lead's segment (hot leads and senior titles are "enterprise", the rest "smb"); if none match, keep step 2
 *   4. the owner with the fewest leads routed today, ties broken by id (even load, stable order)
 */
class LeadRouter
{
    /** @return array{owner:?Owner,reason:string} */
    public function choose(Lead $lead): array
    {
        $segment = $this->segment($lead);

        $load = Lead::whereNotNull('owner_id')->where('routed_at', '>=', now()->startOfDay())
            ->selectRaw('owner_id, count(*) as total')->groupBy('owner_id')->pluck('total', 'owner_id');

        $pool = Owner::where('active', true)->orderBy('id')->get()
            ->filter(fn (Owner $o) => ($load[$o->id] ?? 0) < $o->capacity_per_day);

        if ($pool->isEmpty()) {
            return ['owner' => null, 'reason' => 'No active owner has capacity left today, so the lead is unassigned.'];
        }

        $country = $lead->country;
        $byRegion = $pool->filter(fn (Owner $o) => $country !== null && in_array($country, $o->regionList(), true));
        $regionNote = 'covers '.($country ?? 'unknown country');
        if ($byRegion->isEmpty()) {
            $byRegion = $pool->filter(fn (Owner $o) => $o->regionList() === []);
            $regionNote = 'covers any region';
        }
        if ($byRegion->isEmpty()) {
            return ['owner' => null, 'reason' => 'No owner covers '.($country ?? 'an unknown country').', so the lead is unassigned.'];
        }

        $bySegment = $byRegion->filter(fn (Owner $o) => $o->segmentList() === [] || in_array($segment, $o->segmentList(), true));
        $segmentNote = $bySegment->isEmpty() ? 'segment not matched, region only' : "handles {$segment}";
        $candidates = $bySegment->isEmpty() ? $byRegion : $bySegment;

        $owner = $candidates->sort(fn (Owner $a, Owner $b) => [$load[$a->id] ?? 0, $a->id] <=> [$load[$b->id] ?? 0, $b->id])->first();

        return ['owner' => $owner, 'reason' => "{$owner->name}: {$regionNote}, {$segmentNote}, {$this->loadNote($load[$owner->id] ?? 0)}."];
    }

    public function segment(Lead $lead): string
    {
        $title = strtolower((string) $lead->job_title);
        $senior = collect(config('routewise.seniority_keywords'))->contains(fn ($k) => $title !== '' && str_contains($title, $k));

        return ($lead->tier === 'hot' || $senior) ? 'enterprise' : 'smb';
    }

    private function loadNote(int $today): string
    {
        return $today === 0 ? 'no leads yet today' : "{$today} lead".($today === 1 ? '' : 's').' today';
    }
}
