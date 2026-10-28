<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\Crm\CrmClient;
use App\Services\Crm\CrmGateway;
use App\Services\Crm\CrmPermanentException;
use App\Services\Crm\CrmSyncService;
use App\Services\Crm\CrmTransientException;
use App\Services\Crm\HubSpotGateway;
use App\Services\Crm\PipedriveGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SetsUpRoutewise;
use Tests\TestCase;

/**
 * These adapters were written from the vendors' public API docs and are tested here against FAKED responses.
 * Nothing in this file proves they work against a live HubSpot or Pipedrive account.
 */
class RealCrmAdaptersTest extends TestCase
{
    use RefreshDatabase, SetsUpRoutewise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedScoring();
    }

    private function freshHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
    }

    private function lead(): lead
    {
        $this->owner('Dan Cole', 'DE');
        $owner = \App\Models\Owner::first();
        $owner->update(['crm_owner_id' => '4321']);
        $this->webhook($this->source(), $this->goodLead())->assertCreated();

        return Lead::firstOrFail()->load('owner', 'source');
    }

    // ---------------------------------------------------------------- driver selection

    public function test_the_driver_setting_chooses_the_adapter(): void
    {
        $this->assertInstanceOf(CrmClient::class, app(CrmGateway::class));

        config(['routewise.crm.driver' => 'hubspot', 'routewise.crm.hubspot.token' => 't']);
        $this->assertInstanceOf(HubSpotGateway::class, app(CrmGateway::class));

        config(['routewise.crm.driver' => 'pipedrive', 'routewise.crm.pipedrive.token' => 't']);
        $this->assertInstanceOf(PipedriveGateway::class, app(CrmGateway::class));
    }

    // ---------------------------------------------------------------- HubSpot

    public function test_hubspot_contact_is_upserted_by_email_with_the_documented_body(): void
    {
        $lead = $this->lead();
        Http::fake(['api.hubapi.com/*' => Http::response(['results' => [['id' => '501', 'properties' => []]]], 200)]);

        $id = (new HubSpotGateway('hs-token'))->upsertContact($lead);

        $this->assertSame('501', $id);
        Http::assertSent(function (Request $r) use ($lead) {
            $input = $r->data()['inputs'][0];

            return $r->url() === 'https://api.hubapi.com/crm/v3/objects/contacts/batch/upsert'
                && $r->hasHeader('Authorization', 'Bearer hs-token')
                && $input['idProperty'] === 'email' && $input['id'] === $lead->email
                && $input['properties']['firstname'] === 'Elena' && $input['properties']['jobtitle'] === 'Head of Operations'
                && $input['properties']['hubspot_owner_id'] === '4321' && $input['properties']['phone'] === $lead->phone_e164;
        });
    }

    public function test_hubspot_deal_is_associated_with_the_contact(): void
    {
        $lead = $this->lead();
        Http::fake(['api.hubapi.com/*' => Http::response(['id' => '9001'], 201)]);

        $id = (new HubSpotGateway('hs-token', pipeline: 'sales', dealStage: 'qualified'))->createDeal($lead, '501');

        $this->assertSame('9001', $id);
        Http::assertSent(function (Request $r) {
            $d = $r->data();

            return $r->url() === 'https://api.hubapi.com/crm/v3/objects/deals'
                && $d['properties']['dealname'] === 'Northbeam Logistics - inbound lead' && $d['properties']['pipeline'] === 'sales' && $d['properties']['dealstage'] === 'qualified'
                && $d['associations'][0]['to']['id'] === '501' && $d['associations'][0]['types'][0]['associationTypeId'] === 3;
        });
    }

    public function test_hubspot_status_codes_become_transient_or_permanent_errors(): void
    {
        $lead = $this->lead();
        $hs = new HubSpotGateway('t');

        foreach ([[429, ['Retry-After' => '7'], CrmTransientException::class], [502, [], CrmTransientException::class], [401, [], CrmPermanentException::class], [403, [], CrmPermanentException::class], [400, [], CrmPermanentException::class], [409, [], CrmPermanentException::class]] as [$status, $headers, $class]) {
            $this->freshHttp();
            http::fake(['api.hubapi.com/*' => http::response(['message' => 'nope'], $status, $headers)]);
            try {
                $hs->upsertContact($lead);
                $this->fail("HTTP {$status} should throw");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e, "HTTP {$status}");
                $this->assertSame($status, $e->status);
            }
        }

        $this->freshHttp();

        http::fake(['api.hubapi.com/*' => http::response([], 429, ['Retry-After' => '7'])]);
        try {
            $hs->upsertContact($lead);
        } catch (CrmTransientException $e) {
            $this->assertSame(7, $e->retryAfter);
        }
    }

    public function test_hubspot_without_an_id_in_the_answer_is_a_permanent_failure(): void
    {
        Http::fake(['api.hubapi.com/*' => Http::response(['results' => []], 200)]);

        $this->expectException(CrmPermanentException::class);
        (new HubSpotGateway('t'))->upsertContact($this->lead());
    }

    public function test_a_connection_failure_is_transient(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->expectException(CrmTransientException::class);
        (new HubSpotGateway('t'))->upsertContact($this->lead());
    }

    // ---------------------------------------------------------------- Pipedrive

    public function test_pipedrive_creates_the_organisation_and_person_when_they_do_not_exist(): void
    {
        $lead = $this->lead();
        Http::fake([
            'api.pipedrive.com/v1/organizations/search*' => Http::response(['success' => true, 'data' => ['items' => []]]),
            'api.pipedrive.com/v1/organizations' => Http::response(['success' => true, 'data' => ['id' => 77]]),
            'api.pipedrive.com/v1/persons/search*' => Http::response(['success' => true, 'data' => ['items' => []]]),
            'api.pipedrive.com/v1/persons' => Http::response(['success' => true, 'data' => ['id' => 301]]),
        ]);

        $id = (new PipedriveGateway('pd-token'))->upsertContact($lead);

        $this->assertSame('301', $id);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/persons')
            && $r->hasHeader('x-api-token', 'pd-token') && $r['name'] === 'Elena Fischer' && $r['org_id'] === 77 && $r['owner_id'] === 4321
            && $r['email'][0]['value'] === $lead->email && $r['phone'][0]['value'] === $lead->phone_e164);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/persons/search') && $r['exact_match'] === 'true' && $r['fields'] === 'email');
    }

    public function test_pipedrive_updates_an_existing_person_instead_of_creating_one(): void
    {
        $lead = $this->lead();
        Http::fake([
            'api.pipedrive.com/v1/organizations/search*' => Http::response(['success' => true, 'data' => ['items' => [['item' => ['id' => 88]]]]]),
            'api.pipedrive.com/v1/persons/search*' => Http::response(['success' => true, 'data' => ['items' => [['item' => ['id' => 302]]]]]),
            'api.pipedrive.com/v1/persons/302' => Http::response(['success' => true, 'data' => ['id' => 302]]),
        ]);

        $this->assertSame('302', (new PipedriveGateway('t'))->upsertContact($lead));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/persons/302') && $r['org_id'] === 88);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_pipedrive_deal_carries_person_organisation_and_owner(): void
    {
        $lead = $this->lead();
        Http::fake([
            'api.pipedrive.com/v1/organizations/search*' => Http::response(['success' => true, 'data' => ['items' => [['item' => ['id' => 88]]]]]),
            'api.pipedrive.com/v1/deals' => Http::response(['success' => true, 'data' => ['id' => 6001]]),
        ]);

        $this->assertSame('6001', (new PipedriveGateway('t'))->createDeal($lead, '302'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deals') && $r['person_id'] === 302 && $r['org_id'] === 88 && $r['user_id'] === 4321 && $r['title'] === 'Northbeam Logistics - inbound lead');
    }

    public function test_pipedrive_error_mapping_and_success_false(): void
    {
        $lead = $this->lead();
        $pd = new PipedriveGateway('t');

        foreach ([[429, CrmTransientException::class], [500, CrmTransientException::class], [401, CrmPermanentException::class], [400, CrmPermanentException::class]] as [$status, $class]) {
            $this->freshHttp();
            http::fake(['api.pipedrive.com/*' => http::response(['success' => false, 'error' => 'bad'], $status)]);
            try {
                $pd->upsertContact($lead);
                $this->fail("HTTP {$status} should throw");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e, "HTTP {$status}");
            }
        }

        $this->freshHttp();

        http::fake(['api.pipedrive.com/*' => http::response(['success' => false, 'error' => 'invalid field'], 200)]);
        $this->expectException(CrmPermanentException::class);
        $pd->upsertContact($lead);
    }

    // ---------------------------------------------------------------- end to end with a real adapter

    public function test_the_whole_pipeline_works_through_the_hubspot_adapter_including_retry(): void
    {
        config(['routewise.crm.driver' => 'hubspot', 'routewise.crm.hubspot.token' => 'hs']);
        $this->owner('Sam Ortega');
        Http::fakeSequence('api.hubapi.com/*')
            ->push(['message' => 'busy'], 429, ['Retry-After' => '1'])          // contact attempt 1: rate limited
            ->push(['results' => [['id' => '501']]], 200)                         // contact attempt 2
            ->push(['id' => '9001'], 201);                                        // deal

        $this->webhook($this->source(), $this->goodLead())->assertCreated()->assertJsonPath('status', 'retrying');
        $lead = Lead::firstOrFail();
        $this->assertNull($lead->crm_contact_id);

        app(CrmSyncService::class)->runDue(now()->addMinute());

        $lead->refresh();
        $this->assertSame([Lead::STATUS_SYNCED, '501', '9001'], [$lead->status, $lead->crm_contact_id, $lead->crm_deal_id]);
        $this->assertSame(['retry', 'success'], $lead->attempts->pluck('outcome')->all());
    }
}
