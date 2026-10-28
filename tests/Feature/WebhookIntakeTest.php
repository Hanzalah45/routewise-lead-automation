<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsUpRoutewise;
use Tests\TestCase;

class WebhookIntakeTest extends TestCase
{
    use RefreshDatabase, SetsUpRoutewise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedScoring();
    }

    public function test_a_signed_lead_is_accepted_scored_routed_and_saved_to_the_crm(): void
    {
        $source = $this->source();
        $owner = $this->owner('Priya Nair', 'DE,GB');

        $response = $this->webhook($source, $this->goodLead())->assertCreated();

        $response->assertJsonPath('status', 'synced')->assertJsonPath('tier', 'hot')->assertJsonPath('owner.name', 'Priya Nair');
        $lead = Lead::firstOrFail();
        $this->assertSame($owner->id, $lead->owner_id);
        $this->assertNotNull($lead->crm_contact_id);
        $this->assertNotNull($lead->crm_deal_id);
        $this->assertSame('+493055501187', $lead->phone_e164);
        $this->assertSame(['received', 'scored', 'routed', 'synced'], $lead->events->pluck('type')->all());
        $this->assertDatabaseHas('webhook_deliveries', ['outcome' => 'accepted', 'http_status' => 201, 'lead_id' => $lead->id]);
        $this->assertDatabaseHas('mock_crm_contacts', ['email' => 'elena@northbeam-logistics.example', 'owner_id' => 'own_pri']);
        $this->assertDatabaseHas('outbox_messages', ['channel' => 'slack', 'lead_id' => $lead->id]);
        $this->assertDatabaseHas('outbox_messages', ['channel' => 'email', 'recipient' => 'priya.nair@routewise.test']);
    }

    public function test_a_wrong_signature_is_rejected_and_nothing_is_stored(): void
    {
        $source = $this->source();

        $this->webhook($source, $this->goodLead(), secret: 'wrong-secret')->assertUnauthorized()->assertJsonPath('error', 'invalid_signature');

        $this->assertSame(0, Lead::count());
        $this->assertDatabaseHas('webhook_deliveries', ['outcome' => 'invalid_signature', 'http_status' => 401, 'lead_id' => null]);
    }

    public function test_a_missing_signature_header_is_rejected(): void
    {
        $source = $this->source();

        $this->postJson("/api/v1/webhooks/{$source->slug}", $this->goodLead())->assertUnauthorized();

        $this->assertDatabaseHas('webhook_deliveries', ['outcome' => 'invalid_signature', 'error' => 'missing']);
        $this->assertSame(0, Lead::count());
    }

    public function test_a_replayed_old_request_is_rejected_as_expired(): void
    {
        $source = $this->source();

        $this->webhook($source, $this->goodLead(), timestamp: time() - 3600)->assertUnauthorized()->assertJsonPath('error', 'expired');

        $this->assertDatabaseHas('webhook_deliveries', ['outcome' => 'expired']);
        $this->assertSame(0, Lead::count());
    }

    public function test_the_signature_covers_the_raw_body_so_tampering_is_caught(): void
    {
        $source = $this->source();
        $signed = json_encode($this->goodLead());
        $tampered = json_encode($this->goodLead(['email' => 'attacker@evil.example']));

        $signature = app(\App\Services\Webhooks\SignatureVerifier::class)->sign($this->secret, $signed);
        $this->call('POST', "/api/v1/webhooks/{$source->slug}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_ROUTEWISE_SIGNATURE' => $signature,
        ]), $tampered)->assertUnauthorized();

        $this->assertSame(0, Lead::count());
    }

    public function test_unknown_and_disabled_sources_are_refused(): void
    {
        $source = $this->source('website');
        $off = $this->source('paused', active: false);

        $this->postJson('/api/v1/webhooks/nope', [])->assertNotFound();
        $this->webhook($off, $this->goodLead())->assertForbidden();

        $this->assertDatabaseHas('webhook_deliveries', ['outcome' => 'unknown_source']);
        $this->assertDatabaseHas('webhook_deliveries', ['outcome' => 'inactive_source']);
        $this->assertSame(0, Lead::count());
        $this->assertNotNull($source);
    }

    public function test_invalid_payloads_get_field_errors_and_are_logged(): void
    {
        $source = $this->source();

        $this->webhook($source, ['name' => 'No contact details'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->webhook($source, ['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->webhook($source, [], rawBody: '[1,2,3]')->assertUnprocessable()->assertJsonPath('error', 'invalid_json');
        $this->webhook($source, [], rawBody: 'not json at all')->assertUnprocessable();

        $this->assertSame(0, Lead::count());
        $this->assertSame(4, WebhookDelivery::where('outcome', 'validation_failed')->count());
    }

    public function test_oversized_bodies_are_refused_before_parsing(): void
    {
        $source = $this->source();

        $this->webhook($source, [], rawBody: json_encode(['message' => str_repeat('a', 70000)]))->assertStatus(413);

        $this->assertDatabaseHas('webhook_deliveries', ['outcome' => 'too_large']);
    }

    public function test_the_same_external_id_is_idempotent(): void
    {
        $source = $this->source();
        $this->owner('Sam Ortega');

        $first = $this->webhook($source, $this->goodLead(['external_id' => 'form-991']))->assertCreated();
        $second = $this->webhook($source, $this->goodLead(['external_id' => 'form-991']))->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertTrue($second->json('replay'));
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, \App\Models\MockCrmContact::count());
        $this->assertDatabaseHas('webhook_deliveries', ['outcome' => 'duplicate_delivery']);
    }

    public function test_the_idempotency_key_header_works_like_external_id(): void
    {
        $source = $this->source();

        $this->webhook($source, $this->goodLead(), ['Idempotency-Key' => 'abc-1'])->assertCreated();
        $this->webhook($source, $this->goodLead(), ['Idempotency-Key' => 'abc-1'])->assertOk();

        $this->assertSame(1, Lead::count());
    }

    public function test_a_second_submission_from_the_same_person_is_marked_duplicate_and_not_sent_again(): void
    {
        $source = $this->source();
        $this->owner('Sam Ortega');

        $this->webhook($source, $this->goodLead())->assertCreated();
        $dup = $this->webhook($source, $this->goodLead(['email' => 'Elena+promo@Northbeam-Logistics.example', 'phone' => '']))->assertCreated();

        $dup->assertJsonPath('status', 'duplicate');
        $original = Lead::orderBy('id')->first();
        $copy = Lead::orderByDesc('id')->first();
        $this->assertSame($original->id, $copy->duplicate_of_id);
        $this->assertNull($copy->crm_contact_id);
        $this->assertSame(1, \App\Models\MockCrmContact::count());
        $this->assertContains('duplicate_seen', $original->events->pluck('type')->all());
    }

    public function test_the_same_phone_number_also_counts_as_a_duplicate(): void
    {
        $source = $this->source();
        $this->owner('Sam Ortega');

        $this->webhook($source, $this->goodLead())->assertCreated();
        $this->webhook($source, $this->goodLead(['email' => 'other@elsewhere.example', 'phone' => '0049 30 5550 1187']))->assertJsonPath('status', 'duplicate');
    }

    public function test_spam_is_flagged_and_never_reaches_the_crm(): void
    {
        $source = $this->source();

        $this->webhook($source, ['email' => 'bot@seo-boost.example', 'message' => 'Cheap backlinks, click here'])->assertCreated()->assertJsonPath('status', 'spam')->assertJsonPath('tier', 'spam');
        $this->webhook($source, ['email' => 'bot2@x.example', 'website' => 'http://bot.example'])->assertJsonPath('status', 'spam');

        $this->assertSame(0, \App\Models\MockCrmContact::count());
        $this->assertSame(0, \App\Models\OutboxMessage::count());
    }

    public function test_a_lead_without_consent_is_held_not_routed_or_sent(): void
    {
        $source = $this->source();
        $this->owner('Sam Ortega');

        $this->webhook($source, $this->goodLead(['consent' => false]))->assertCreated()->assertJsonPath('status', 'held');

        $lead = Lead::firstOrFail();
        $this->assertNull($lead->owner_id);
        $this->assertSame(0, \App\Models\MockCrmContact::count());
    }

    public function test_a_lead_with_no_owner_available_is_stored_unassigned_but_still_synced(): void
    {
        $source = $this->source();

        $this->webhook($source, $this->goodLead())->assertCreated()->assertJsonPath('owner', null)->assertJsonPath('status', 'synced');

        $this->assertContains('unassigned', Lead::first()->events->pluck('type')->all());
    }

    public function test_a_phone_only_lead_without_email_is_accepted_but_cannot_reach_the_crm(): void
    {
        $source = $this->source();

        $this->webhook($source, ['name' => 'Walk In', 'phone' => '+1 415 555 0100', 'consent' => true])->assertCreated()->assertJsonPath('status', 'dead');

        $this->assertStringContainsString('no email', Lead::first()->last_error);
    }

    public function test_the_source_secret_is_never_returned_or_logged(): void
    {
        $source = $this->source();

        $json = $this->webhook($source, $this->goodLead())->getContent();

        $this->assertStringNotContainsString($this->secret, $json);
        $this->assertStringNotContainsString($this->secret, json_encode(WebhookDelivery::all()->toArray()));
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString($this->secret, (string) \Illuminate\Support\Facades\DB::table('lead_sources')->value('secret'));
        $this->assertSame($this->secret, $source->fresh()->secret());
    }
}
