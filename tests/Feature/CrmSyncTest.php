<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MockCrmContact;
use App\Models\MockCrmDeal;
use App\Services\Crm\CrmSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SetsUpRoutewise;
use Tests\TestCase;

class CrmSyncTest extends TestCase
{
    use RefreshDatabase, SetsUpRoutewise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedScoring();
        $this->owner('Sam Ortega');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function send(array $override = []): Lead
    {
        $this->webhook($this->source(), $this->goodLead($override))->assertCreated();

        return Lead::latest('id')->firstOrFail();
    }

    public function test_a_rate_limited_crm_schedules_a_retry_that_honours_retry_after_and_backoff(): void
    {
        $this->crmMode('rate_limited', 1);
        Carbon::setTestNow('2026-10-28 10:00:00');

        $lead = $this->send();

        $this->assertSame(Lead::STATUS_RETRYING, $lead->status);
        $this->assertSame(1, $lead->sync_attempts);
        $this->assertSame('2026-10-28 10:00:30', $lead->next_retry_at->toDateTimeString(), 'First backoff is 30s (longer than Retry-After: 2)');
        $this->assertStringContainsString('rate limit', $lead->last_error);
        $this->assertDatabaseHas('crm_sync_attempts', ['lead_id' => $lead->id, 'outcome' => 'retry', 'http_status' => 429]);
    }

    public function test_the_retry_command_waits_for_the_backoff_and_then_succeeds(): void
    {
        $this->crmMode('down', 1);
        Carbon::setTestNow('2026-10-28 10:00:00');
        $lead = $this->send();
        $this->assertSame(Lead::STATUS_RETRYING, $lead->status);

        Carbon::setTestNow('2026-10-28 10:00:10');
        $this->artisan('leads:sync-pending')->expectsOutputToContain('0 lead(s)')->assertSuccessful();
        $this->assertSame(Lead::STATUS_RETRYING, $lead->fresh()->status);

        Carbon::setTestNow('2026-10-28 10:00:31');
        $this->artisan('leads:sync-pending')->expectsOutputToContain('1 lead(s)')->assertSuccessful();

        $lead->refresh();
        $this->assertSame(Lead::STATUS_SYNCED, $lead->status);
        $this->assertSame(2, $lead->sync_attempts);
        $this->assertNull($lead->next_retry_at);
        $this->assertSame(['retry', 'success'], $lead->attempts->pluck('outcome')->all());
    }

    public function test_backoff_grows_and_the_lead_goes_dead_after_the_last_attempt(): void
    {
        $this->crmMode('down', -1);
        Carbon::setTestNow('2026-10-28 10:00:00');
        $lead = $this->send();
        $sync = app(CrmSyncService::class);

        $waits = [];
        $clock = Carbon::now();
        for ($i = 0; $i < 4; $i++) {
            $waits[] = (int) $clock->diffInSeconds($lead->fresh()->next_retry_at);
            $clock = $lead->fresh()->next_retry_at->copy();
            Carbon::setTestNow($clock);
            $sync->runDue();
        }

        $this->assertSame([30, 120, 600, 1800], $waits);
        $lead->refresh();
        $this->assertSame(Lead::STATUS_DEAD, $lead->status);
        $this->assertSame(5, $lead->sync_attempts);
        $this->assertNull($lead->next_retry_at);
        $this->assertContains('dead', $lead->events->pluck('type')->all());
        $this->assertSame(0, MockCrmContact::count());
    }

    public function test_a_permanent_rejection_is_not_retried(): void
    {
        $this->crmMode('reject', 1);

        $lead = $this->send();

        $this->assertSame(Lead::STATUS_DEAD, $lead->status);
        $this->assertSame(1, $lead->sync_attempts);
        $this->assertNull($lead->next_retry_at);
        $this->assertDatabaseHas('crm_sync_attempts', ['lead_id' => $lead->id, 'outcome' => 'permanent_failure', 'http_status' => 422]);
    }

    public function test_bad_credentials_are_permanent(): void
    {
        config(['routewise.crm.token' => 'the-real-token']);
        $this->app->bind(\App\Services\Crm\CrmTransport::class, fn ($app) => new \App\Services\Crm\InProcessMockTransport($app->make(\App\Services\MockCrm\MockCrmApi::class), 'wrong-token'));

        $lead = $this->send();

        $this->assertSame(Lead::STATUS_DEAD, $lead->status);
        $this->assertStringContainsString('credentials', $lead->last_error);
    }

    public function test_retry_now_gives_a_dead_lead_a_fresh_set_of_attempts(): void
    {
        $this->crmMode('reject', 1);
        $lead = $this->send();
        $this->assertSame(Lead::STATUS_DEAD, $lead->status);

        $lead = app(CrmSyncService::class)->retryNow($lead, 'Amara Okafor');

        $this->assertSame(Lead::STATUS_SYNCED, $lead->status);
        $this->assertSame(1, $lead->sync_attempts, 'Counter restarted at the manual retry');
        $this->assertContains('manual_retry', $lead->events->pluck('type')->all());
    }

    public function test_retry_now_does_nothing_for_leads_that_are_not_retryable(): void
    {
        $lead = $this->send();
        $before = $lead->events()->count();

        app(CrmSyncService::class)->retryNow($lead, 'Someone');

        $this->assertSame($before, $lead->events()->count());
    }

    public function test_a_failure_after_the_contact_was_saved_does_not_create_a_second_contact(): void
    {
        // contact call succeeds, deal call is the one that fails
        $this->crmMode('ok', 0);
        $source = $this->source();
        $lead = app(\App\Services\Leads\LeadPipeline::class)->ingest($source, $this->goodLead());
        $transport = new class(app(\App\Services\MockCrm\MockCrmApi::class)) implements \App\Services\Crm\CrmTransport {
            public int $calls = 0;
            public function __construct(private $api) {}
            public function send(string $method, string $path, array $body = [], array $headers = []): \App\Services\Crm\CrmResponse
            {
                $this->calls++;
                if ($path === '/deals' && $this->calls <= 2) {
                    return new \App\Services\Crm\CrmResponse(503, ['error' => 'down']);
                }

                return $this->api->handle($method, $path, $body, ['authorization' => 'Bearer '.config('routewise.crm.token')] + array_change_key_case($headers));
            }
        };
        $sync = new CrmSyncService(new \App\Services\Crm\CrmClient($transport));

        $lead = $sync->sync($lead);
        $this->assertSame(Lead::STATUS_RETRYING, $lead->status);
        $this->assertNotNull($lead->crm_contact_id, 'Progress is saved');
        $contactId = $lead->crm_contact_id;

        $lead = $sync->sync($lead->fill(['next_retry_at' => now()->subMinute()]));
        $lead = $sync->sync($lead);

        $this->assertSame(Lead::STATUS_SYNCED, $lead->status);
        $this->assertSame($contactId, $lead->crm_contact_id);
        $this->assertSame(1, MockCrmContact::count());
        $this->assertSame(1, MockCrmDeal::count());
    }

    public function test_the_crm_receives_the_documented_fields_and_a_stable_idempotency_key(): void
    {
        $lead = $this->send();

        $contact = MockCrmContact::firstOrFail();
        $this->assertSame('elena@northbeam-logistics.example', $contact->email);
        $this->assertSame('Elena', $contact->first_name);
        $this->assertSame('+493055501187', $contact->phone);
        $this->assertSame($lead->uuid, $contact->properties['routewise_lead_id']);
        $this->assertSame('hot', $contact->properties['lead_tier']);
        $this->assertDatabaseHas('mock_crm_requests', ['method' => 'POST', 'path' => '/contacts', 'status' => 201, 'idempotency_key' => $lead->uuid.':contact']);
        $this->assertSame($lead->uuid.':deal', MockCrmDeal::first()->idempotency_key);
    }

    public function test_runs_nothing_when_no_lead_is_due(): void
    {
        $this->artisan('leads:sync-pending')->expectsOutputToContain('0 lead(s)')->assertSuccessful();
    }
}
