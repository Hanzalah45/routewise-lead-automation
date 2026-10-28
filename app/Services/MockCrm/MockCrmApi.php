<?php

namespace App\Services\MockCrm;

use App\Models\MockCrmContact;
use App\Models\MockCrmDeal;
use App\Models\MockCrmRequest;
use App\Models\Setting;
use App\Services\Crm\CrmResponse;
use Illuminate\Support\Str;

/**
 * A small stand-in for a CRM REST API (contacts and deals), used for demos and tests.
 * Its failure switch ("next N requests answer 429 / 503 / 422") exists so retries, backoff and the dead-letter
 * queue can be shown on demand. It is not a real CRM and holds no real data.
 *
 *   POST /contacts   upsert by email          201 created | 200 updated | 422 invalid
 *   GET  /contacts   ?email=                  200 | 404
 *   POST /deals      needs Idempotency-Key    201 created | 200 same key seen before | 422
 */
class MockCrmApi
{
    public const MODES = ['ok', 'rate_limited', 'down', 'reject'];

    /**
     * @param  array<string,mixed>  $body
     * @param  array<string,string>  $headers  lower-case names
     */
    public function handle(string $method, string $path, array $body, array $headers): CrmResponse
    {
        $method = strtoupper($method);
        $path = '/'.ltrim($path, '/');
        $key = $headers['idempotency-key'] ?? null;

        $response = $this->route($method, $path, $body, $headers);

        MockCrmRequest::create([
            'method' => $method, 'path' => $path, 'status' => $response->status,
            'idempotency_key' => $key ? Str::limit($key, 80, '') : null,
            'note' => $response->body['error'] ?? null,
        ]);

        return $response;
    }

    private function route(string $method, string $path, array $body, array $headers): CrmResponse
    {
        if (($headers['authorization'] ?? '') !== 'Bearer '.config('routewise.crm.token')) {
            return new CrmResponse(401, ['error' => 'invalid_token']);
        }

        if ($injected = $this->injectedFailure()) {
            return $injected;
        }

        return match (true) {
            $method === 'POST' && $path === '/contacts' => $this->upsertContact($body),
            $method === 'GET' && $path === '/contacts' => $this->findContact($body),
            $method === 'POST' && $path === '/deals' => $this->createDeal($body, $headers['idempotency-key'] ?? null),
            default => new CrmResponse(404, ['error' => 'not_found']),
        };
    }

    /** Consumes one of the "fail the next N requests" credits and answers with the configured failure. */
    private function injectedFailure(): ?CrmResponse
    {
        $mode = Setting::get('mock_crm.mode', 'ok');
        $remaining = (int) Setting::get('mock_crm.fail_remaining', '0');
        if ($mode === 'ok' || $remaining === 0) {
            return null;
        }

        if ($remaining > 0) {
            Setting::put('mock_crm.fail_remaining', (string) ($remaining - 1));
        }

        return match ($mode) {
            'rate_limited' => new CrmResponse(429, ['error' => 'rate_limited'], ['retry-after' => '2']),
            'down' => new CrmResponse(503, ['error' => 'service_unavailable']),
            'reject' => new CrmResponse(422, ['error' => 'validation_failed', 'detail' => 'Simulated rejection']),
            default => null,
        };
    }

    private function upsertContact(array $body): CrmResponse
    {
        $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new CrmResponse(422, ['error' => 'validation_failed', 'fields' => ['email' => 'A valid email is required']]);
        }

        $fields = array_filter([
            'first_name' => $body['first_name'] ?? null, 'last_name' => $body['last_name'] ?? null,
            'phone' => $body['phone'] ?? null, 'company' => $body['company'] ?? null,
            'job_title' => $body['job_title'] ?? null, 'country' => $body['country'] ?? null,
            'owner_id' => $body['owner_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $contact = MockCrmContact::where('email', $email)->first();
        if ($contact) {
            $contact->update($fields + ['properties' => array_merge($contact->properties ?? [], is_array($body['properties'] ?? null) ? $body['properties'] : [])]);

            return new CrmResponse(200, $this->contact($contact));
        }

        $contact = MockCrmContact::create($fields + [
            'external_id' => 'ct_'.Str::lower(Str::random(10)), 'email' => $email,
            'properties' => is_array($body['properties'] ?? null) ? $body['properties'] : null,
        ]);

        return new CrmResponse(201, $this->contact($contact));
    }

    private function findContact(array $query): CrmResponse
    {
        $contact = MockCrmContact::where('email', strtolower((string) ($query['email'] ?? '')))->first();

        return $contact ? new CrmResponse(200, $this->contact($contact)) : new CrmResponse(404, ['error' => 'not_found']);
    }

    private function createDeal(array $body, ?string $key): CrmResponse
    {
        if ($key === null || $key === '') {
            return new CrmResponse(422, ['error' => 'validation_failed', 'fields' => ['Idempotency-Key' => 'Header required']]);
        }
        if ($existing = MockCrmDeal::where('idempotency_key', $key)->first()) {
            return new CrmResponse(200, $this->deal($existing));
        }
        if (! MockCrmContact::where('external_id', $body['contact_id'] ?? '')->exists()) {
            return new CrmResponse(422, ['error' => 'validation_failed', 'fields' => ['contact_id' => 'Unknown contact']]);
        }

        $deal = MockCrmDeal::create([
            'external_id' => 'dl_'.Str::lower(Str::random(10)), 'contact_external_id' => $body['contact_id'],
            'name' => Str::limit((string) ($body['name'] ?? 'Inbound lead'), 160, ''), 'stage' => $body['stage'] ?? 'new',
            'owner_id' => $body['owner_id'] ?? null, 'idempotency_key' => $key,
        ]);

        return new CrmResponse(201, $this->deal($deal));
    }

    /** @return array<string,mixed> */
    private function contact(MockCrmContact $c): array
    {
        return ['id' => $c->external_id, 'email' => $c->email, 'first_name' => $c->first_name, 'last_name' => $c->last_name,
            'company' => $c->company, 'owner_id' => $c->owner_id, 'properties' => $c->properties];
    }

    /** @return array<string,mixed> */
    private function deal(MockCrmDeal $d): array
    {
        return ['id' => $d->external_id, 'contact_id' => $d->contact_external_id, 'name' => $d->name, 'stage' => $d->stage, 'owner_id' => $d->owner_id];
    }
}
