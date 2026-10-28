<?php

namespace App\Http\Controllers;

use App\Models\MockCrmContact;
use App\Models\MockCrmDeal;
use App\Models\MockCrmRequest;
use App\Models\Setting;
use App\Services\MockCrm\MockCrmApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Dashboard view of the bundled Mock CRM, plus the failure switch that makes retries demonstrable. */
class MockCrmAdminController extends Controller
{
    public function index(): View
    {
        return view('mock-crm.index', [
            'contacts' => MockCrmContact::orderByDesc('id')->limit(8)->get(),
            'deals' => MockCrmDeal::orderByDesc('id')->limit(8)->get(),
            'requests' => MockCrmRequest::orderByDesc('id')->limit(8)->get(),
            'totals' => ['contacts' => MockCrmContact::count(), 'deals' => MockCrmDeal::count(), 'requests' => MockCrmRequest::count()],
            'mode' => Setting::get('mock_crm.mode', 'ok'),
            'remaining' => (int) Setting::get('mock_crm.fail_remaining', '0'),
            'endpoint' => url('/api/mock-crm/v1'),
            'token' => config('routewise.crm.token'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(MockCrmApi::MODES)],
            'count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'until_reset' => ['nullable', 'boolean'],
        ]);

        if ($data['mode'] === 'ok') {
            Setting::put('mock_crm.mode', 'ok');
            Setting::put('mock_crm.fail_remaining', '0');

            return back()->with('status', 'The Mock CRM is healthy again.');
        }

        $remaining = $request->boolean('until_reset') ? -1 : (int) ($data['count'] ?? 1);
        Setting::put('mock_crm.mode', $data['mode']);
        Setting::put('mock_crm.fail_remaining', (string) $remaining);

        return back()->with('status', 'Next '.($remaining < 0 ? 'requests will' : "{$remaining} request".($remaining === 1 ? '' : 's').' will').' fail with '.match ($data['mode']) {
            'rate_limited' => '429 Too Many Requests', 'down' => '503 Service Unavailable', default => '422 Unprocessable',
        }.'.');
    }
}
