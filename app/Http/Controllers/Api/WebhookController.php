<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\WebhookDelivery;
use App\Services\Crm\CrmSyncService;
use App\Services\Leads\LeadPipeline;
use App\Services\Webhooks\SignatureVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/v1/webhooks/{source}
 * Order matters: find the source, check size, verify the signature over the RAW body, only then parse and validate.
 * Every request, accepted or not, leaves a row in webhook_deliveries (no payload content is stored there).
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $source, SignatureVerifier $signatures, LeadPipeline $pipeline, CrmSyncService $crm): JsonResponse
    {
        $body = $request->getContent();
        $log = fn (string $outcome, int $status, ?LeadSource $src = null, ?Lead $lead = null, ?string $error = null) => WebhookDelivery::create([
            'lead_source_id' => $src?->id, 'source_slug' => mb_substr($source, 0, 80), 'ip' => $request->ip(),
            'outcome' => $outcome, 'http_status' => $status, 'lead_id' => $lead?->id,
            'payload_sha256' => hash('sha256', $body), 'payload_bytes' => strlen($body), 'error' => $error,
        ]);

        $src = LeadSource::where('slug', $source)->first();
        if (! $src) {
            $log('unknown_source', 404);

            return response()->json(['error' => 'unknown_source'], 404);
        }
        if (! $src->active) {
            $log('inactive_source', 403, $src);

            return response()->json(['error' => 'source_disabled'], 403);
        }
        if (strlen($body) > (int) config('routewise.max_body_bytes')) {
            $log('too_large', 413, $src);

            return response()->json(['error' => 'payload_too_large'], 413);
        }

        $check = $signatures->verify($src->secret(), $body, $request->header('X-Routewise-Signature'));
        if ($check !== SignatureVerifier::OK) {
            $outcome = $check === SignatureVerifier::EXPIRED ? 'expired' : 'invalid_signature';
            $log($outcome, 401, $src, null, $check);

            return response()->json(['error' => $outcome, 'reason' => $check], 401);
        }
        $src->forceFill(['last_used_at' => now()])->save();

        $payload = json_decode($body, true);
        if (! is_array($payload) || array_is_list($payload)) {
            $log('validation_failed', 422, $src, null, 'Body is not a JSON object');

            return response()->json(['error' => 'invalid_json', 'message' => 'The body must be a JSON object.'], 422);
        }

        $validator = Validator::make($payload, [
            'email' => ['nullable', 'string', 'max:254', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:40'],
            'first_name' => ['nullable', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:160'], 'company' => ['nullable', 'string', 'max:160'],
            'job_title' => ['nullable', 'string', 'max:160'], 'country' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:5000'], 'external_id' => ['nullable', 'string', 'max:120'],
        ]);
        $validator->after(function ($v) use ($payload) {
            if (blank($payload['email'] ?? null) && blank($payload['phone'] ?? null)) {
                $v->errors()->add('email', 'Send at least an email or a phone number.');
            }
        });
        if ($validator->fails()) {
            $log('validation_failed', 422, $src, null, collect($validator->errors()->keys())->implode(', '));

            return response()->json(['error' => 'validation_failed', 'message' => 'The payload failed validation.', 'errors' => $validator->errors()], 422);
        }

        $externalId = $payload['external_id'] ?? $request->header('Idempotency-Key');
        $externalId = is_string($externalId) && $externalId !== '' ? mb_substr($externalId, 0, 120) : null;

        if ($externalId && ($existing = Lead::where('lead_source_id', $src->id)->where('external_id', $externalId)->first())) {
            $log('duplicate_delivery', 200, $src, $existing);

            return response()->json($this->present($existing, true), 200);
        }

        try {
            $lead = $pipeline->ingest($src, $payload, $externalId);
        } catch (UniqueConstraintViolationException) {
            $existing = Lead::where('lead_source_id', $src->id)->where('external_id', $externalId)->firstOrFail();
            $log('duplicate_delivery', 200, $src, $existing);

            return response()->json($this->present($existing, true), 200);
        }

        if ($lead->status === Lead::STATUS_ROUTED) {
            $lead = $crm->sync($lead);
        }

        $log('accepted', 201, $src, $lead);

        return response()->json($this->present($lead, false), 201);
    }

    /** @return array<string,mixed> */
    private function present(Lead $lead, bool $replay): array
    {
        $lead->loadMissing('owner');

        return [
            'id' => $lead->uuid,
            'replay' => $replay,
            'status' => $lead->status,
            'tier' => $lead->tier,
            'score' => $lead->score,
            'owner' => $lead->owner ? ['name' => $lead->owner->name, 'email' => $lead->owner->email] : null,
            'crm' => ['contact_id' => $lead->crm_contact_id, 'deal_id' => $lead->crm_deal_id, 'next_retry_at' => $lead->next_retry_at?->toIso8601String()],
        ];
    }
}
