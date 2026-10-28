<?php

namespace App\Http\Controllers;

use App\Models\LeadSource;
use App\Services\Webhooks\SignatureVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function index(): View
    {
        return view('sources.index', [
            'sources' => LeadSource::withCount('leads')->orderBy('name')->get(),
            'base' => url('/api/v1/webhooks'),
            'tolerance' => config('routewise.webhook_tolerance_seconds'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        $slug = Str::slug($data['name']);
        $slug = LeadSource::where('slug', $slug)->exists() ? $slug.'-'.Str::lower(Str::random(4)) : $slug;
        $source = new LeadSource(['name' => $data['name'], 'slug' => $slug, 'active' => true]);
        $secret = LeadSource::generateSecret();
        $source->setSecret($secret);
        $source->save();

        return redirect()->route('sources.index')->with('status', "Source \"{$source->name}\" created.")->with('revealed', [$source->id => $secret]);
    }

    public function rotate(LeadSource $source): RedirectResponse
    {
        $this->authorize('manage');
        $secret = LeadSource::generateSecret();
        $source->setSecret($secret);
        $source->save();

        return redirect()->route('sources.index')->with('status', "New secret generated for {$source->name}. The old one stopped working immediately.")->with('revealed', [$source->id => $secret]);
    }

    public function toggle(LeadSource $source): RedirectResponse
    {
        $this->authorize('manage');
        $source->update(['active' => ! $source->active]);

        return redirect()->route('sources.index')->with('status', $source->active ? "{$source->name} is accepting leads." : "{$source->name} is paused. Requests are refused with 403.");
    }

    /** Sends a real, correctly signed request through the public endpoint so the whole path is exercised. */
    public function test(Request $request, LeadSource $source, SignatureVerifier $signatures): RedirectResponse
    {
        $this->authorize('manage');
        $stamp = Str::lower(Str::random(5));
        $payload = [
            'name' => 'Test Lead', 'email' => "test.lead+{$stamp}@routewise-demo.example", 'phone' => '+1 415 555 0100',
            'company' => 'Routewise Test Co', 'job_title' => 'Director of Sales', 'country' => 'US',
            'message' => 'Test lead sent from the Routewise dashboard. Please ignore. We would like pricing and a demo.', 'consent' => true,
            'external_id' => "dashboard-test-{$stamp}",
        ];
        $body = json_encode($payload);

        $original = app('request');
        $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle(\Illuminate\Http\Request::create(
            url("/api/v1/webhooks/{$source->slug}"), 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_ROUTEWISE_SIGNATURE' => $signatures->sign($source->secret(), $body),
            ], $body,
        ));
        app()->instance('request', $original);

        $json = json_decode($response->getContent(), true) ?? [];
        if ($response->getStatusCode() === 201 && isset($json['id'])) {
            $lead = \App\Models\Lead::where('uuid', $json['id'])->first();

            return redirect()->route('leads.show', $lead)->with('status', "Test lead accepted (HTTP 201): scored {$json['score']}, status {$json['status']}.");
        }

        return redirect()->route('sources.index')->with('error', 'Test lead was refused (HTTP '.$response->getStatusCode().'): '.($json['error'] ?? 'unknown error').'.');
    }
}
