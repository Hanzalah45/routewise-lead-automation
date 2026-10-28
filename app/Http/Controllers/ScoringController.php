<?php

namespace App\Http\Controllers;

use App\Models\ScoringRule;
use App\Services\Leads\LeadNormalizer;
use App\Services\Leads\LeadRouter;
use App\Services\Leads\LeadScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScoringController extends Controller
{
    public function index(): View
    {
        return view('scoring.index', ['rules' => ScoringRule::orderBy('sort')->get(), 'tiers' => config('routewise.tiers')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage');
        $data = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.points' => ['required', 'integer', 'between:-100,100'],
            'rules.*.active' => ['nullable', 'boolean'],
        ]);

        foreach (ScoringRule::all() as $rule) {
            if (isset($data['rules'][$rule->id])) {
                $rule->update(['points' => (int) $data['rules'][$rule->id]['points'], 'active' => (bool) ($data['rules'][$rule->id]['active'] ?? false)]);
            }
        }

        return redirect()->route('scoring.index')->with('status', 'Scoring rules saved. New leads use them straight away; existing leads keep their score until re-scored.');
    }

    /** Scores a hypothetical lead without storing anything. */
    public function simulate(Request $request, LeadNormalizer $normalizer, LeadScorer $scorer): JsonResponse
    {
        $input = $request->validate([
            'name' => ['nullable', 'string', 'max:160'], 'email' => ['nullable', 'string', 'max:254'], 'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['nullable', 'string', 'max:160'], 'job_title' => ['nullable', 'string', 'max:160'], 'country' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $normalised = $normalizer->normalise($input);
        $result = $scorer->score($normalised);

        $lead = new \App\Models\Lead(['country' => $normalised['country'], 'job_title' => $normalised['job_title'], 'tier' => $result['tier']]);
        $route = $result['spam'] ? ['owner' => null, 'reason' => 'Spam is never routed.'] : app(LeadRouter::class)->choose($lead);

        return response()->json($result + ['segment' => app(LeadRouter::class)->segment($lead), 'owner' => $route['owner']?->name, 'route_reason' => $route['reason']]);
    }
}
