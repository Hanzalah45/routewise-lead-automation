<?php

namespace Tests\Feature;

use App\Models\MockCrmContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsUpRoutewise;
use Tests\TestCase;

class MockCrmApiTest extends TestCase
{
    use RefreshDatabase, SetsUpRoutewise;

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.config('routewise.crm.token'), 'Accept' => 'application/json'];
    }

    public function test_requests_without_the_token_are_unauthorised(): void
    {
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'])->assertUnauthorized();
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
        $this->assertSame(0, MockCrmContact::count());
    }

    public function test_contacts_are_upserted_by_email(): void
    {
        $created = $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'A@B.example', 'first_name' => 'Ana'], $this->auth())->assertCreated();
        $updated = $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example', 'company' => 'Acme'], $this->auth())->assertOk();

        $this->assertSame($created->json('id'), $updated->json('id'));
        $this->assertSame(1, MockCrmContact::count());
        $this->assertSame(['Ana', 'Acme'], [MockCrmContact::first()->first_name, MockCrmContact::first()->company]);
    }

    public function test_invalid_contacts_get_a_422_with_the_field(): void
    {
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'nope'], $this->auth())->assertUnprocessable()->assertJsonPath('fields.email', 'A valid email is required');
    }

    public function test_deals_need_a_contact_and_an_idempotency_key_and_are_idempotent(): void
    {
        $contact = $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth())->json('id');

        $this->postJson('/api/mock-crm/v1/deals', ['contact_id' => $contact], $this->auth())->assertUnprocessable();
        $this->postJson('/api/mock-crm/v1/deals', ['contact_id' => 'ct_missing'], $this->auth() + ['Idempotency-Key' => 'k1'])->assertUnprocessable();

        $first = $this->postJson('/api/mock-crm/v1/deals', ['contact_id' => $contact, 'name' => 'Acme'], $this->auth() + ['Idempotency-Key' => 'k2'])->assertCreated();
        $again = $this->postJson('/api/mock-crm/v1/deals', ['contact_id' => $contact, 'name' => 'Acme'], $this->auth() + ['Idempotency-Key' => 'k2'])->assertOk();

        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, \App\Models\MockCrmDeal::count());
    }

    public function test_a_contact_can_be_looked_up_by_email(): void
    {
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth());

        $this->getJson('/api/mock-crm/v1/contacts?email=A@B.example', $this->auth())->assertOk()->assertJsonPath('email', 'a@b.example');
        $this->getJson('/api/mock-crm/v1/contacts?email=nobody@b.example', $this->auth())->assertNotFound();
        $this->getJson('/api/mock-crm/v1/unknown', $this->auth())->assertNotFound();
    }

    public function test_the_failure_switch_answers_429_with_retry_after_for_the_next_n_requests_then_recovers(): void
    {
        $this->crmMode('rate_limited', 2);

        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth())->assertStatus(429)->assertHeader('Retry-After', '2');
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth())->assertStatus(429);
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth())->assertCreated();
    }

    public function test_the_other_failure_modes_and_the_until_reset_setting(): void
    {
        $this->crmMode('down', -1);
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth())->assertStatus(503);
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth())->assertStatus(503);

        $this->crmMode('reject', 1);
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth())->assertStatus(422);

        $this->crmMode('ok', 0);
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth())->assertCreated();
    }

    public function test_every_call_is_logged_including_failures(): void
    {
        $this->crmMode('down', 1);
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth() + ['Idempotency-Key' => 'x:contact']);
        $this->postJson('/api/mock-crm/v1/contacts', ['email' => 'a@b.example'], $this->auth() + ['Idempotency-Key' => 'x:contact']);

        $this->assertDatabaseHas('mock_crm_requests', ['status' => 503, 'idempotency_key' => 'x:contact']);
        $this->assertDatabaseHas('mock_crm_requests', ['status' => 201]);
    }
}
