<?php

namespace App\Services\Crm;

use App\Models\Lead;

/**
 * The only place that knows the CRM's API shape. Maps CRM responses onto two outcomes the sync service understands:
 * success (returns the CRM id) or a transient / permanent exception.
 */
class CrmClient implements CrmGateway
{
    public function __construct(private readonly CrmTransport $transport) {}

    /** Creates or updates the contact for this lead (matched by email) and returns the CRM contact id. */
    public function upsertContact(Lead $lead): string
    {
        $response = $this->transport->send('POST', '/contacts', [
            'email' => $lead->email,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'phone' => $lead->phone_e164 ?? $lead->phone,
            'company' => $lead->company,
            'job_title' => $lead->job_title,
            'country' => $lead->country,
            'owner_id' => $lead->owner?->crm_owner_id,
            'properties' => [
                'routewise_lead_id' => $lead->uuid,
                'lead_score' => $lead->score,
                'lead_tier' => $lead->tier,
                'lead_source' => $lead->source?->slug,
            ],
        ], ['Idempotency-Key' => $lead->uuid.':contact']);

        return $this->idFrom($response, 'contact');
    }

    /** Opens a deal for the contact; the idempotency key makes a repeated call return the same deal. */
    public function createDeal(Lead $lead, string $contactId): string
    {
        $response = $this->transport->send('POST', '/deals', [
            'contact_id' => $contactId,
            'name' => ($lead->company ?: $lead->fullName()).' - inbound lead',
            'stage' => 'new',
            'owner_id' => $lead->owner?->crm_owner_id,
        ], ['Idempotency-Key' => $lead->uuid.':deal']);

        return $this->idFrom($response, 'deal');
    }

    private function idFrom(CrmResponse $response, string $what): string
    {
        if ($response->successful() && isset($response->body['id'])) {
            return (string) $response->body['id'];
        }

        $detail = $response->body['detail'] ?? $response->body['error'] ?? $response->body['message'] ?? 'no detail';

        throw match (true) {
            $response->status === 429 => new CrmTransientException("CRM rate limit reached while saving the {$what}.", 429, $response->retryAfter()),
            $response->status >= 500 => new CrmTransientException("CRM server error ({$response->status}) while saving the {$what}.", $response->status, $response->retryAfter()),
            $response->status === 401 || $response->status === 403 => new CrmPermanentException("CRM rejected the credentials ({$response->status}).", $response->status),
            $response->successful() => new CrmPermanentException("CRM answered {$response->status} without an id for the {$what}.", $response->status),
            default => new CrmPermanentException("CRM rejected the {$what} ({$response->status}: {$detail}).", $response->status),
        };
    }
}
