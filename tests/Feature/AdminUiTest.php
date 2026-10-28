<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Owner;
use App\Models\ScoringRule;
use App\Models\Setting;
use App\Models\User;
use App\Services\Webhooks\SignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsUpRoutewise;
use Tests\TestCase;

class AdminUiTest extends TestCase
{
    use RefreshDatabase, SetsUpRoutewise;

    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedScoring();
        $this->admin = User::factory()->admin()->create();
        $this->agent = User::factory()->agent()->create();
    }

    private function lead(array $override = []): Lead
    {
        $this->webhook($this->source(), $this->goodLead($override))->assertSuccessful();

        return Lead::latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ access

    public function test_every_page_requires_a_login(): void
    {
        foreach (['/dashboard', '/leads', '/owners', '/scoring', '/sources', '/deliveries', '/outbox', '/mock-crm'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $lead = $this->lead();
        $this->get("/leads/{$lead->uuid}")->assertRedirect('/login');
        $this->post("/leads/{$lead->uuid}/retry")->assertRedirect('/login');
    }

    public function test_the_root_redirects_to_the_dashboard_and_login_page_renders(): void
    {
        $this->actingAs($this->agent)->get('/')->assertRedirect('/dashboard');
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Every lead scored, owned and in the CRM.');
    }

    public function test_every_page_renders_for_an_admin_with_data_in_the_system(): void
    {
        $this->owner('Sam Ortega');
        $lead = $this->lead();
        $this->crmMode('down', 1);
        $this->lead(['email' => 'second@elsewhere.example', 'phone' => '+1 415 555 0111']);
        $this->crmMode('ok', 0);

        foreach (['/dashboard', '/leads', '/leads?status=attention', '/owners', '/scoring', '/sources', '/deliveries', '/deliveries?outcome=rejected', '/outbox', '/outbox?channel=slack', '/mock-crm', "/leads/{$lead->uuid}"] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['email' => 'me@routewise.test', 'password' => 'secret-pass']);

        $this->post('/login', ['email' => 'me@routewise.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'me@routewise.test', 'password' => 'secret-pass'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_missing_lead_is_a_404_and_leads_are_addressed_by_uuid_not_id(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->agent)->get('/leads/999999')->assertNotFound();
        $this->actingAs($this->agent)->get("/leads/{$lead->id}")->assertNotFound();
        $this->actingAs($this->agent)->get("/leads/{$lead->uuid}")->assertOk()->assertSee($lead->fullName());
    }

    // ------------------------------------------------------------------ roles

    public function test_agents_cannot_change_owners_rules_or_sources(): void
    {
        $owner = $this->owner('Sam Ortega');
        $source = $this->source();
        $rule = ScoringRule::first();

        $this->actingAs($this->agent);
        $this->post('/owners', ['name' => 'X', 'email' => 'x@y.example', 'capacity_per_day' => 5])->assertForbidden();
        $this->put("/owners/{$owner->id}", ['name' => 'X', 'email' => 'x@y.example', 'capacity_per_day' => 5])->assertForbidden();
        $this->put('/scoring', ['rules' => [$rule->id => ['points' => 99]]])->assertForbidden();
        $this->post('/sources', ['name' => 'New'])->assertForbidden();
        $this->post("/sources/{$source->id}/rotate")->assertForbidden();
        $this->post("/sources/{$source->id}/toggle")->assertForbidden();
        $this->post("/sources/{$source->id}/test")->assertForbidden();

        $this->assertSame('Sam Ortega', $owner->fresh()->name);
        $this->assertNotSame(99, $rule->fresh()->points);
        $this->assertTrue($source->fresh()->active);
        $this->assertSame($this->secret, $source->fresh()->secret());
    }

    public function test_agents_see_read_only_notices_and_no_secret_in_full(): void
    {
        $this->source();
        $this->actingAs($this->agent);

        $this->get('/owners')->assertOk()->assertSee('Read-only')->assertDontSee('Add owner');
        $this->get('/scoring')->assertOk()->assertSee('Read-only');
        $this->get('/sources')->assertOk()->assertSee('Read-only')->assertDontSee($this->secret)->assertSee('whsec_••••••••cret');
    }

    // ------------------------------------------------------------------ owners

    public function test_an_admin_can_add_edit_and_pause_an_owner(): void
    {
        $this->actingAs($this->admin);

        $this->post('/owners', ['name' => 'Ada Wong', 'email' => 'ada@routewise.test', 'regions' => 'us, ca', 'segments' => 'Enterprise', 'capacity_per_day' => 10, 'crm_owner_id' => 'own_900'])->assertRedirect('/owners');
        $owner = Owner::firstWhere('email', 'ada@routewise.test');
        $this->assertSame(['US,CA', 'enterprise', true], [$owner->regions, $owner->segments, $owner->active]);

        $this->put("/owners/{$owner->id}", ['name' => 'Ada W.', 'email' => 'ada@routewise.test', 'regions' => '', 'capacity_per_day' => 30])->assertRedirect('/owners');
        $owner->refresh();
        $this->assertSame(['Ada W.', null, 30, false], [$owner->name, $owner->regions, $owner->capacity_per_day, $owner->active], 'Unchecked "active" pauses the owner');
    }

    public function test_owner_input_is_validated(): void
    {
        $this->actingAs($this->admin);
        $this->owner('Sam Ortega');

        $this->post('/owners', ['name' => '', 'email' => 'bad', 'capacity_per_day' => 0])->assertSessionHasErrors(['name', 'email', 'capacity_per_day']);
        $this->post('/owners', ['name' => 'A', 'email' => 'a@b.example', 'regions' => 'USA', 'capacity_per_day' => 5])->assertSessionHasErrors('regions');
        $this->post('/owners', ['name' => 'A', 'email' => 'a@b.example', 'segments' => 'gold', 'capacity_per_day' => 5])->assertSessionHasErrors('segments');
        $this->post('/owners', ['name' => 'A', 'email' => 'sam.ortega@routewise.test', 'capacity_per_day' => 5])->assertSessionHasErrors('email');
    }

    // ------------------------------------------------------------------ scoring rules

    public function test_an_admin_can_change_points_and_switch_rules_and_new_leads_use_them(): void
    {
        $this->actingAs($this->admin);
        $payload = ScoringRule::all()->mapWithKeys(fn ($r) => [$r->id => ['points' => $r->points, 'active' => '1']])->all();
        $senior = ScoringRule::firstWhere('key', 'senior_title');
        $phone = ScoringRule::firstWhere('key', 'phone_provided');
        $payload[$senior->id] = ['points' => 40, 'active' => '1'];
        $payload[$phone->id] = ['points' => 5, 'active' => '0'];

        $this->put('/scoring', ['rules' => $payload])->assertRedirect('/scoring');

        $this->assertSame(40, $senior->fresh()->points);
        $this->assertFalse($phone->fresh()->active);
        $lead = $this->lead();
        $this->assertSame(80 + 20 - 5, $lead->score);
    }

    public function test_rule_points_must_be_between_minus_100_and_100(): void
    {
        $rule = ScoringRule::first();

        $this->actingAs($this->admin)->put('/scoring', ['rules' => [$rule->id => ['points' => 500]]])->assertSessionHasErrors('rules.'.$rule->id.'.points');
        $this->put('/scoring', ['rules' => [$rule->id => ['points' => 'abc']]])->assertSessionHasErrors();
    }

    public function test_the_simulator_scores_without_storing_anything(): void
    {
        $this->owner('Priya Nair', 'DE');

        $this->actingAs($this->agent)->postJson('/scoring/simulate', ['name' => 'Elena', 'email' => 'elena@northbeam-logistics.example', 'company' => 'Northbeam', 'job_title' => 'CTO', 'country' => 'DE', 'message' => 'Pricing and a demo please, we have a budget approved for this quarter already.'])
            ->assertOk()->assertJsonPath('tier', 'hot')->assertJsonPath('segment', 'enterprise')->assertJsonPath('owner', 'Priya Nair')->assertJsonStructure(['score', 'breakdown' => [['key', 'label', 'points', 'detail']]]);

        $this->assertSame(0, Lead::count());
    }

    public function test_the_simulator_reports_spam_and_never_routes_it(): void
    {
        $this->owner('Sam Ortega');

        $this->actingAs($this->agent)->postJson('/scoring/simulate', ['email' => 'x@y.example', 'message' => 'cheap backlinks click here'])
            ->assertOk()->assertJsonPath('tier', 'spam')->assertJsonPath('owner', null);
    }

    // ------------------------------------------------------------------ sources

    public function test_creating_a_source_shows_the_secret_once(): void
    {
        $response = $this->actingAs($this->admin)->post('/sources', ['name' => 'LinkedIn Forms'])->assertRedirect('/sources');

        $source = LeadSource::firstWhere('slug', 'linkedin-forms');
        $this->assertNotNull($source);
        $secret = session('revealed')[$source->id];
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame($secret, $source->secret());

        $this->get('/sources')->assertSee($secret);
        $this->get('/sources')->assertDontSee($secret);
    }

    public function test_rotating_a_secret_invalidates_the_old_one_immediately(): void
    {
        $source = $this->source();
        $this->actingAs($this->admin)->post("/sources/{$source->id}/rotate")->assertRedirect('/sources');

        $this->webhook($source->fresh(), $this->goodLead(), secret: $this->secret)->assertUnauthorized();
        $this->webhook($source->fresh(), $this->goodLead(), secret: session('revealed')[$source->id])->assertCreated();
    }

    public function test_pausing_a_source_makes_the_endpoint_refuse_requests(): void
    {
        $source = $this->source();
        $this->actingAs($this->admin)->post("/sources/{$source->id}/toggle");

        $this->webhook($source->fresh(), $this->goodLead())->assertForbidden();

        $this->actingAs($this->admin)->post("/sources/{$source->id}/toggle");
        $this->webhook($source->fresh(), $this->goodLead())->assertCreated();
    }

    public function test_the_test_button_sends_a_real_signed_request_through_the_public_endpoint(): void
    {
        $source = $this->source();
        $this->owner('Sam Ortega');

        $response = $this->actingAs($this->admin)->post("/sources/{$source->id}/test");

        $lead = Lead::firstOrFail();
        $response->assertRedirect(route('leads.show', $lead));
        $this->assertSame('Test Lead', $lead->fullName());
        $this->assertDatabaseHas('webhook_deliveries', ['outcome' => 'accepted', 'lead_id' => $lead->id]);
        $this->assertTrue(auth()->check(), 'The nested request must not log the admin out');
    }

    // ------------------------------------------------------------------ lead actions

    public function test_retry_now_recovers_a_dead_lead_from_the_ui(): void
    {
        $this->owner('Sam Ortega');
        $this->crmMode('reject', 1);
        $lead = $this->lead();
        $this->assertSame('dead', $lead->status);

        $this->actingAs($this->agent)->from("/leads/{$lead->uuid}")->post("/leads/{$lead->uuid}/retry")->assertRedirect("/leads/{$lead->uuid}")->assertSessionHas('status');

        $this->assertSame('synced', $lead->fresh()->status);
    }

    public function test_retry_reports_when_the_crm_is_still_failing(): void
    {
        $this->owner('Sam Ortega');
        $this->crmMode('reject', 1);
        $lead = $this->lead();
        $this->crmMode('down', -1);

        $this->actingAs($this->agent)->post("/leads/{$lead->uuid}/retry")->assertSessionHas('error');

        $this->assertSame('retrying', $lead->fresh()->status);
    }

    public function test_retry_is_refused_for_leads_that_are_not_waiting_on_the_crm(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->agent)->post("/leads/{$lead->uuid}/retry")->assertSessionHas('error');
    }

    public function test_reassigning_updates_the_owner_here_and_in_the_crm(): void
    {
        $sam = $this->owner('Sam Ortega');
        $lead = $this->lead();
        $dan = $this->owner('Dan Cole', 'US');

        $this->actingAs($this->agent)->post("/leads/{$lead->uuid}/assign", ['owner_id' => $dan->id])->assertSessionHas('status');

        $lead->refresh();
        $this->assertSame($dan->id, $lead->owner_id);
        $this->assertSame('own_dan', \App\Models\MockCrmContact::firstOrFail()->owner_id);
        $this->assertContains('reassigned', $lead->events->pluck('type')->all());
        $this->assertContains('crm_owner_updated', $lead->events->pluck('type')->all());
        $this->assertNotSame($sam->id, $lead->owner_id);
    }

    public function test_a_failed_crm_owner_update_is_reported_and_recorded(): void
    {
        $this->owner('Sam Ortega');
        $lead = $this->lead();
        $dan = $this->owner('Dan Cole', 'US');
        $this->crmMode('down', -1);

        $this->actingAs($this->agent)->post("/leads/{$lead->uuid}/assign", ['owner_id' => $dan->id])->assertSessionHas('error');

        $this->assertSame($dan->id, $lead->fresh()->owner_id);
        $this->assertContains('crm_owner_update_failed', $lead->fresh()->events->pluck('type')->all());
    }

    public function test_a_lead_can_be_unassigned_and_the_owner_must_exist(): void
    {
        $this->owner('Sam Ortega');
        $lead = $this->lead();

        $this->actingAs($this->agent)->post("/leads/{$lead->uuid}/assign", ['owner_id' => ''])->assertSessionHas('status');
        $this->assertNull($lead->fresh()->owner_id);
        $this->post("/leads/{$lead->uuid}/assign", ['owner_id' => 9999])->assertSessionHasErrors('owner_id');
    }

    public function test_marking_spam_and_rescoring(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->agent)->post("/leads/{$lead->uuid}/spam")->assertSessionHas('status');
        $this->assertSame(['spam', 'spam'], [$lead->fresh()->status, $lead->fresh()->tier]);

        ScoringRule::where('key', 'corporate_email')->update(['points' => 50]);
        $other = $this->lead(['email' => 'other@corp-two.example', 'phone' => '+1 415 555 0122']);
        ScoringRule::where('key', 'corporate_email')->update(['points' => 0]);
        $this->post("/leads/{$other->uuid}/rescore")->assertSessionHas('status');
        $this->assertSame(80 - 15 + 0, $other->fresh()->score);
        $this->assertContains('rescored', $other->fresh()->events->pluck('type')->all());
    }

    // ------------------------------------------------------------------ list filters

    public function test_the_lead_list_filters_by_status_tier_source_owner_and_search(): void
    {
        $sam = $this->owner('Sam Ortega');
        $this->lead(['name' => 'Alpha Person', 'email' => 'alpha@alpha-co.example', 'phone' => '+1 415 555 0101']);
        $this->lead(['name' => 'Beta Person', 'email' => 'beta@gmail.com', 'phone' => '+1 415 555 0102', 'message' => 'hello', 'job_title' => '', 'company' => '']);
        $this->crmMode('reject', 1);
        $this->lead(['name' => 'Gamma Person', 'email' => 'gamma@gamma-co.example', 'phone' => '+1 415 555 0103']);
        $this->crmMode('ok', 0);

        $this->actingAs($this->agent);
        $this->get('/leads?q=alpha')->assertSee('Alpha Person')->assertDontSee('Beta Person');
        $this->get('/leads?status=dead')->assertSee('Gamma Person')->assertDontSee('Alpha Person');
        $this->get('/leads?status=attention')->assertSee('Gamma Person')->assertDontSee('Beta Person');
        $this->get('/leads?tier=cold')->assertSee('Beta Person')->assertDontSee('Alpha Person');
        $this->get('/leads?source='.LeadSource::first()->id)->assertSee('Alpha Person');
        $this->get('/leads?owner='.$sam->id)->assertSee('Alpha Person');
        $this->get('/leads?owner=none')->assertDontSee('Alpha Person')->assertSee('No leads match');
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $this->lead(['name' => 'Plain Person']);

        $this->actingAs($this->agent)->get('/leads?q=%25')->assertDontSee('Plain Person');
        $this->get('/leads?q=_____')->assertDontSee('Plain Person');
    }

    // ------------------------------------------------------------------ mock CRM admin & dashboard

    public function test_the_failure_simulator_changes_how_the_next_leads_are_delivered(): void
    {
        $this->owner('Sam Ortega');
        $this->actingAs($this->agent)->put('/mock-crm', ['mode' => 'down', 'count' => 1])->assertSessionHas('status');
        $this->assertSame(['down', '1'], [Setting::get('mock_crm.mode'), Setting::get('mock_crm.fail_remaining')]);

        $this->assertSame('retrying', $this->lead()->status);
        $this->assertSame('synced', $this->lead(['email' => 'next@next.example', 'phone' => '+1 415 555 0133'])->status, 'The failure was only for the next 1 request');

        $this->put('/mock-crm', ['mode' => 'rate_limited', 'until_reset' => '1']);
        $this->assertSame('-1', Setting::get('mock_crm.fail_remaining'));
        $this->put('/mock-crm', ['mode' => 'ok']);
        $this->assertSame(['ok', '0'], [Setting::get('mock_crm.mode'), Setting::get('mock_crm.fail_remaining')]);
    }

    public function test_the_failure_simulator_rejects_unknown_modes(): void
    {
        $this->actingAs($this->agent)->put('/mock-crm', ['mode' => 'explode'])->assertSessionHasErrors('mode');
    }

    public function test_dashboard_numbers_match_the_stored_leads(): void
    {
        $this->owner('Sam Ortega');
        $this->lead();                                                                              // hot, synced
        $this->lead(['name' => 'Cold One', 'email' => 'c@gmail.com', 'phone' => '+1 415 555 0140', 'company' => '', 'job_title' => '', 'message' => 'hi']);
        $this->webhook($this->source(), ['email' => 'spam@x.example', 'message' => 'cheap backlinks'])->assertCreated();
        $this->crmMode('reject', 1);
        $this->lead(['email' => 'dead@dead.example', 'phone' => '+1 415 555 0141']);
        $this->crmMode('ok', 0);

        $response = $this->actingAs($this->agent)->get('/dashboard')->assertOk();
        $data = $response->viewData('kpis');

        $this->assertSame(4, $data['received']);
        $this->assertSame(2, $data['hot']);
        $this->assertSame(66.7, (float) $data['sync_rate'], '2 of 3 attempted leads reached the CRM');
        $this->assertSame(1, $data['attention']);
        $this->assertSame(4, collect($response->viewData('funnel'))->first()['value']);
    }
}
