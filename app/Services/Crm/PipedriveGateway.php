<?php

namespace App\Services\Crm;

use App\Models\Lead;

/**
 * Pipedrive adapter (API v1, personal API token sent in the x-api-token header).
 * Person: exact search by email, then update or create. Organisation: exact search by name, then create.
 * Deal: created with the person and organisation attached. Pipedrive has no idempotency key; as with HubSpot the
 * sync service saves each id as soon as it is returned.
 *
 * Built from Pipedrive's public API documentation and covered by tests against faked responses.
 * It has NOT been run against a live Pipedrive account. owners' crm_owner_id must be the numeric Pipedrive user id.
 */
class PipedriveGateway implements CrmGateway
{
    use MapsCrmErrors;

    public function __construct(private readonly string $token, private readonly string $baseUrl = 'https://api.pipedrive.com/v1') {}

    protected function vendor(): string
    {
        return 'Pipedrive';
    }

    private function request(string $method, string $path, string $what, array $data = []): array
    {
        $options = ['headers' => ['x-api-token' => $this->token]];
        $options[$method === 'GET' ? 'query' : 'json'] = $data;

        $response = $this->call($method, rtrim($this->baseUrl, '/').$path, $what, $options);
        if ($response->json('success') === false) {
            throw new CrmPermanentException("Pipedrive refused the {$what}: ".mb_substr((string) $response->json('error'), 0, 160), $response->status());
        }

        return (array) $response->json();
    }

    public function upsertContact(Lead $lead): string
    {
        $orgId = $lead->company ? $this->organisationId($lead->company) : null;
        $owner = $lead->owner?->crm_owner_id;

        $fields = array_filter([
            'name' => $lead->fullName(),
            'email' => [['value' => $lead->email, 'primary' => true]],
            'phone' => $lead->phone_e164 ? [['value' => $lead->phone_e164, 'primary' => true]] : null,
            'org_id' => $orgId,
            'owner_id' => ctype_digit((string) $owner) ? (int) $owner : null,
        ], fn ($v) => $v !== null && $v !== '');

        $found = $this->request('GET', '/persons/search', 'contact', ['term' => $lead->email, 'fields' => 'email', 'exact_match' => 'true', 'limit' => 1]);
        $existing = $found['data']['items'][0]['item']['id'] ?? null;

        $saved = $existing
            ? $this->request('PUT', "/persons/{$existing}", 'contact', $fields)
            : $this->request('POST', '/persons', 'contact', $fields);

        $id = $saved['data']['id'] ?? null;
        if ($id === null) {
            throw new CrmPermanentException('Pipedrive answered without a person id.');
        }

        return (string) $id;
    }

    public function createDeal(Lead $lead, string $contactId): string
    {
        $owner = $lead->owner?->crm_owner_id;
        $orgId = $lead->company ? $this->organisationId($lead->company) : null;

        $saved = $this->request('POST', '/deals', 'deal', array_filter([
            'title' => ($lead->company ?: $lead->fullName()).' - inbound lead',
            'person_id' => (int) $contactId,
            'org_id' => $orgId,
            'user_id' => ctype_digit((string) $owner) ? (int) $owner : null,
        ], fn ($v) => $v !== null));

        $id = $saved['data']['id'] ?? null;
        if ($id === null) {
            throw new CrmPermanentException('Pipedrive answered without a deal id.');
        }

        return (string) $id;
    }

    private function organisationId(string $name): int
    {
        $found = $this->request('GET', '/organizations/search', 'organisation', ['term' => $name, 'exact_match' => 'true', 'limit' => 1]);
        if ($id = $found['data']['items'][0]['item']['id'] ?? null) {
            return (int) $id;
        }

        return (int) $this->request('POST', '/organizations', 'organisation', ['name' => $name])['data']['id'];
    }
}
