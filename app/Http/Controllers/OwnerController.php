<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OwnerController extends Controller
{
    public function index(): View
    {
        $today = Lead::whereNotNull('owner_id')->where('routed_at', '>=', now()->startOfDay())->selectRaw('owner_id, count(*) c')->groupBy('owner_id')->pluck('c', 'owner_id');
        $week = Lead::whereNotNull('owner_id')->where('routed_at', '>=', now()->subDays(7))->selectRaw('owner_id, count(*) c')->groupBy('owner_id')->pluck('c', 'owner_id');
        $hot = Lead::whereNotNull('owner_id')->where('tier', 'hot')->where('routed_at', '>=', now()->subDays(7))->selectRaw('owner_id, count(*) c')->groupBy('owner_id')->pluck('c', 'owner_id');

        return view('owners.index', ['owners' => Owner::orderByDesc('active')->orderBy('name')->get(), 'today' => $today, 'week' => $week, 'hot' => $hot]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage');
        $owner = Owner::create($this->validated($request) + ['active' => true]);

        return redirect()->route('owners.index')->with('status', "{$owner->name} added.");
    }

    public function update(Request $request, Owner $owner): RedirectResponse
    {
        $this->authorize('manage');
        $owner->update($this->validated($request, $owner) + ['active' => $request->boolean('active')]);

        return redirect()->route('owners.index')->with('status', "{$owner->name} updated.");
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?Owner $owner = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('owners', 'email')->ignore($owner?->id)],
            'regions' => ['nullable', 'string', 'max:120', 'regex:/^\s*([A-Za-z]{2}\s*(,\s*[A-Za-z]{2}\s*)*)?$/'],
            'segments' => ['nullable', 'string', 'max:60', 'regex:/^\s*((enterprise|smb)\s*(,\s*(enterprise|smb)\s*)*)?$/i'],
            'capacity_per_day' => ['required', 'integer', 'min:1', 'max:500'],
            'crm_owner_id' => ['nullable', 'string', 'max:60'],
        ], ['regions.regex' => 'Use two-letter country codes separated by commas, for example US, CA.', 'segments.regex' => 'Use enterprise, smb, or both separated by a comma.']);

        $data['regions'] = $this->clean($data['regions'] ?? null, true);
        $data['segments'] = $this->clean($data['segments'] ?? null, false);

        return $data;
    }

    private function clean(?string $value, bool $upper): ?string
    {
        $items = array_filter(array_map('trim', explode(',', (string) $value)));

        return $items === [] ? null : implode(',', array_map(fn ($i) => $upper ? strtoupper($i) : strtolower($i), $items));
    }
}
