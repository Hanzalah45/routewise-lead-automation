<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Owner;
use App\Services\Crm\CrmGateway;
use App\Services\Crm\CrmException;
use App\Services\Crm\CrmSyncService;
use App\Services\Leads\LeadNormalizer;
use App\Services\Leads\LeadScorer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeadController extends Controller
{
    public function index(Request $request): View
    {
        $q = Lead::with(['owner', 'source']);

        if ($status = $request->query('status')) {
            $status === 'attention'
                ? $q->whereIn('status', [Lead::STATUS_DEAD, Lead::STATUS_RETRYING, Lead::STATUS_HELD])
                : $q->where('status', $status);
        }
        if ($tier = $request->query('tier')) {
            $q->where('tier', $tier);
        }
        if ($source = $request->query('source')) {
            $q->where('lead_source_id', $source);
        }
        if ($owner = $request->query('owner')) {
            $owner === 'none' ? $q->whereNull('owner_id') : $q->where('owner_id', $owner);
        }
        if ($term = trim((string) $request->query('q'))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $q->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('company', 'like', $like)->orWhere('uuid', 'like', $like));
        }

        $counts = Lead::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return view('leads.index', [
            'leads' => $q->orderByDesc('received_at')->orderByDesc('id')->paginate(15)->withQueryString(),
            'counts' => $counts,
            'attention' => ($counts[Lead::STATUS_DEAD] ?? 0) + ($counts[Lead::STATUS_RETRYING] ?? 0) + ($counts[Lead::STATUS_HELD] ?? 0),
            'total' => $counts->sum(),
            'sources' => LeadSource::orderBy('name')->get(),
            'owners' => Owner::orderBy('name')->get(),
        ]);
    }

    public function show(Lead $lead): View
    {
        $lead->load(['owner', 'source', 'events', 'attempts', 'duplicateOf']);

        return view('leads.show', [
            'lead' => $lead,
            'owners' => Owner::where('active', true)->orderBy('name')->get(),
            'duplicates' => Lead::where('duplicate_of_id', $lead->id)->orderBy('id')->get(),
        ]);
    }

    public function retry(Request $request, Lead $lead, CrmSyncService $sync): RedirectResponse
    {
        if (! $lead->canRetry()) {
            return back()->with('error', 'This lead is not waiting on a CRM sync.');
        }
        $lead = $sync->retryNow($lead, $request->user()->name);

        return back()->with($lead->status === Lead::STATUS_SYNCED ? 'status' : 'error', $lead->status === Lead::STATUS_SYNCED
            ? 'Saved to the CRM.'
            : 'The CRM still failed: '.$lead->last_error);
    }

    public function assign(Request $request, Lead $lead, CrmGateway $crm): RedirectResponse
    {
        $data = $request->validate(['owner_id' => ['nullable', 'exists:owners,id']]);
        $owner = $data['owner_id'] ? Owner::findOrFail($data['owner_id']) : null;

        $lead->update(['owner_id' => $owner?->id]);
        $lead->record('reassigned', $owner ? "Reassigned to {$owner->name} by {$request->user()->name}." : "Unassigned by {$request->user()->name}.");

        if ($lead->crm_contact_id) {
            try {
                $crm->upsertContact($lead->refresh()->load('owner', 'source'));
                $lead->record('crm_owner_updated', 'CRM contact owner updated.');
            } catch (CrmException $e) {
                $lead->record('crm_owner_update_failed', 'Owner changed here, but the CRM update failed: '.$e->getMessage());

                return back()->with('error', 'Owner changed, but the CRM update failed. It will not be retried automatically.');
            }
        }

        return back()->with('status', $owner ? "Assigned to {$owner->name}." : 'Lead unassigned.');
    }

    public function spam(Request $request, Lead $lead): RedirectResponse
    {
        $lead->update(['status' => Lead::STATUS_SPAM, 'tier' => 'spam', 'next_retry_at' => null]);
        $lead->record('spam', "Marked as spam by {$request->user()->name}. It stays in the CRM if it was already synced.");

        return back()->with('status', 'Marked as spam.');
    }

    public function rescore(Request $request, Lead $lead, LeadNormalizer $normalizer, LeadScorer $scorer): RedirectResponse
    {
        $result = $scorer->score($normalizer->normalise($lead->raw_payload ?? []));
        $before = $lead->score;
        $lead->update(['score' => $result['score'], 'tier' => $result['spam'] ? 'spam' : $result['tier'], 'score_breakdown' => $result['breakdown']]);
        $lead->record('rescored', "Re-scored by {$request->user()->name}: {$before} to {$lead->score}.");

        return back()->with('status', "Score is now {$lead->score} ({$lead->tier}).");
    }
}
