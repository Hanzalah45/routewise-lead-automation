<?php

namespace App\Services\Crm;

use App\Models\Lead;

/**
 * HubSpot adapter (CRM API v3, private app token with crm.objects.contacts.write and crm.objects.deals.write).
 * Contacts use the batch upsert keyed on email, so a repeat call updates instead of duplicating.
 * Deals have no idempotency key in HubSpot: the sync service stores the deal id as soon as it is returned, which
 * prevents duplicates on retries, but a crash between HubSpot creating the deal and us saving its id could duplicate one.
 *
 * Built from HubSpot's public API documentation and covered by tests against faked responses.
 * It has NOT been run against a live HubSpot account.
 */
class HubSpotGateway implements CrmGateway
{
    use MapsCrmErrors;

    private const DEAL_TO_CONTACT = 3;   // HUBSPOT_DEFINED association type id

    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://api.hubapi.com',
        private readonly string $pipeline = 'default',
        private readonly string $dealStage = 'appointmentscheduled',
    ) {}

    protected function vendor(): string
    {
        return 'HubSpot';
    }

    public function upsertContact(Lead $lead): string
    {
        $properties = array_filter([
            'email' => $lead->email,
            'firstname' => $lead->first_name,
            'lastname' => $lead->last_name,
            'phone' => $lead->phone_e164 ?? $lead->phone,
            'company' => $lead->company,
            'jobtitle' => $lead->job_title,
            'country' => $lead->country,
            'hubspot_owner_id' => $lead->owner?->crm_owner_id,
        ], fn ($v) => $v !== null && $v !== '');

        $response = $this->call('POST', "{$this->baseUrl}/crm/v3/objects/contacts/batch/upsert", 'contact', [
            'headers' => ['Authorization' => 'Bearer '.$this->token],
            'json' => ['inputs' => [['idProperty' => 'email', 'id' => $lead->email, 'properties' => $properties]]],
        ]);

        $id = $response->json('results.0.id');
        if ($id === null) {
            throw new CrmPermanentException('HubSpot answered without a contact id.', $response->status());
        }

        return (string) $id;
    }

    public function createDeal(Lead $lead, string $contactId): string
    {
        $response = $this->call('POST', "{$this->baseUrl}/crm/v3/objects/deals", 'deal', [
            'headers' => ['Authorization' => 'Bearer '.$this->token],
            'json' => [
                'properties' => array_filter([
                    'dealname' => ($lead->company ?: $lead->fullName()).' - inbound lead',
                    'pipeline' => $this->pipeline,
                    'dealstage' => $this->dealStage,
                    'hubspot_owner_id' => $lead->owner?->crm_owner_id,
                ], fn ($v) => $v !== null && $v !== ''),
                'associations' => [[
                    'to' => ['id' => $contactId],
                    'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => self::DEAL_TO_CONTACT]],
                ]],
            ],
        ]);

        $id = $response->json('id');
        if ($id === null) {
            throw new CrmPermanentException('HubSpot answered without a deal id.', $response->status());
        }

        return (string) $id;
    }
}
