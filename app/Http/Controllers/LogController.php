<?php

namespace App\Http\Controllers;

use App\Models\OutboxMessage;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only logs: inbound webhook deliveries and the simulated notification outbox. */
class LogController extends Controller
{
    public function deliveries(Request $request): View
    {
        $q = WebhookDelivery::with(['source', 'lead']);
        if ($outcome = $request->query('outcome')) {
            $outcome === 'rejected' ? $q->where('http_status', '>=', 400) : $q->where('outcome', $outcome);
        }

        return view('logs.deliveries', [
            'deliveries' => $q->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'accepted' => WebhookDelivery::where('http_status', '<', 400)->count(),
            'rejected' => WebhookDelivery::where('http_status', '>=', 400)->count(),
        ]);
    }

    public function outbox(Request $request): View
    {
        $q = OutboxMessage::with('lead');
        if ($channel = $request->query('channel')) {
            $q->where('channel', $channel);
        }

        return view('logs.outbox', ['messages' => $q->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString()]);
    }
}
