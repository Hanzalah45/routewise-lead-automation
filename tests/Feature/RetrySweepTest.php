<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Services\SchedulerHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SetsUpRoutewise;
use Tests\TestCase;

class RetrySweepTest extends TestCase
{
    use RefreshDatabase, SetsUpRoutewise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedScoring();
        $this->owner('Sam Ortega');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function failedLead(): Lead
    {
        $this->crmMode('down', 1);
        Carbon::setTestNow('2026-10-28 10:00:00');
        $this->webhook($this->source(), $this->goodLead())->assertCreated()->assertJsonPath('status', 'retrying');
        $this->crmMode('ok', 0);

        return Lead::firstOrFail();
    }

    public function test_a_page_visit_retries_a_due_lead_even_when_no_scheduler_is_running(): void
    {
        $lead = $this->failedLead();
        Carbon::setTestNow('2026-10-28 10:05:00');
        Cache::flush();

        $this->actingAs(User::factory()->agent()->create())->get('/dashboard')->assertOk();

        $this->assertSame(Lead::STATUS_SYNCED, $lead->fresh()->status);
        $this->assertSame(2, $lead->fresh()->sync_attempts);
    }

    public function test_an_incoming_webhook_also_triggers_the_sweep(): void
    {
        $lead = $this->failedLead();
        Carbon::setTestNow('2026-10-28 10:05:00');
        Cache::flush();

        $this->webhook($this->source(), $this->goodLead(['email' => 'new@new-co.example', 'phone' => '+1 415 555 0190']))->assertCreated();

        $this->assertSame(Lead::STATUS_SYNCED, $lead->fresh()->status);
    }

    public function test_leads_that_are_not_due_yet_are_left_alone(): void
    {
        $lead = $this->failedLead();
        Carbon::setTestNow('2026-10-28 10:00:10');
        Cache::flush();

        $this->actingAs(User::factory()->agent()->create())->get('/dashboard');

        $this->assertSame(Lead::STATUS_RETRYING, $lead->fresh()->status);
    }

    public function test_the_sweep_runs_at_most_once_per_lock_window(): void
    {
        $lead = $this->failedLead();
        Carbon::setTestNow('2026-10-28 10:05:00');
        Cache::flush();
        Cache::put('routewise:sweep-lock', 1, 20);     // another request just swept

        $this->actingAs(User::factory()->agent()->create())->get('/dashboard');

        $this->assertSame(Lead::STATUS_RETRYING, $lead->fresh()->status);
    }

    public function test_the_health_check_endpoint_does_not_sweep(): void
    {
        $lead = $this->failedLead();
        Carbon::setTestNow('2026-10-28 10:05:00');
        Cache::flush();

        $this->get('/up')->assertOk();

        $this->assertSame(Lead::STATUS_RETRYING, $lead->fresh()->status);
    }

    public function test_the_heartbeat_is_recorded_by_the_command_and_reported_on_the_dashboard(): void
    {
        $this->assertNull(SchedulerHealth::lastRun());
        $this->assertTrue(SchedulerHealth::isStale());

        $this->artisan('leads:sync-pending')->assertSuccessful();

        $this->assertNotNull(SchedulerHealth::lastRun());
        $this->assertFalse(SchedulerHealth::isStale());
        Cache::flush();
        $this->actingAs(User::factory()->agent()->create())->get('/dashboard')->assertSee('Retry checker')->assertSee('ran');

        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertTrue(SchedulerHealth::isStale());
    }
}
