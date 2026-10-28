<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\ScoringRule;
use App\Services\Leads\LeadNormalizer;
use App\Services\Leads\LeadRouter;
use App\Services\Leads\LeadScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsUpRoutewise;
use Tests\TestCase;

class ScoringAndRoutingTest extends TestCase
{
    use RefreshDatabase, SetsUpRoutewise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedScoring();
    }

    private function score(array $in): array
    {
        return app(LeadScorer::class)->score(app(LeadNormalizer::class)->normalise($in));
    }

    public function test_a_complete_buying_lead_is_hot_and_every_point_is_explained(): void
    {
        $r = $this->score($this->goodLead());

        $this->assertSame(80, $r['score']);
        $this->assertSame('hot', $r['tier']);
        $this->assertSame(
            ['corporate_email', 'senior_title', 'company_provided', 'phone_provided', 'intent_keywords', 'long_message', 'priority_country'],
            array_column($r['breakdown'], 'key'),
        );
        $this->assertSame(80, array_sum(array_column($r['breakdown'], 'points')));
        $this->assertStringContainsString('"head of"', $r['breakdown'][1]['detail']);
    }

    public function test_tiers_follow_the_thresholds(): void
    {
        $scorer = app(LeadScorer::class);

        $this->assertSame('hot', $scorer->tier(70));
        $this->assertSame('warm', $scorer->tier(69));
        $this->assertSame('warm', $scorer->tier(35));
        $this->assertSame('cold', $scorer->tier(34));
    }

    public function test_free_mail_with_no_details_is_cold_and_the_score_never_goes_below_zero(): void
    {
        $r = $this->score(['email' => 'x@gmail.com']);

        $this->assertSame(0, $r['score']);
        $this->assertSame('cold', $r['tier']);
        $this->assertContains('free_email', array_column($r['breakdown'], 'key'));
        $this->assertContains('missing_name', array_column($r['breakdown'], 'key'));
    }

    public function test_the_score_is_capped_at_100(): void
    {
        ScoringRule::where('key', 'corporate_email')->update(['points' => 90]);

        $this->assertSame(100, $this->score($this->goodLead())['score']);
    }

    public function test_editing_points_or_switching_a_rule_off_changes_the_score(): void
    {
        ScoringRule::where('key', 'senior_title')->update(['points' => 0, 'active' => false]);
        ScoringRule::where('key', 'priority_country')->update(['points' => 25]);

        $r = $this->score($this->goodLead());

        $this->assertSame(80 - 20 + 15, $r['score']);
        $this->assertNotContains('senior_title', array_column($r['breakdown'], 'key'));
    }

    public function test_keywords_match_whole_words_only(): void
    {
        $this->assertNotContains('senior_title', array_column($this->score(['email' => 'a@b.example', 'job_title' => 'Ownership analyst'])['breakdown'], 'key'));
        $this->assertContains('senior_title', array_column($this->score(['email' => 'a@b.example', 'job_title' => 'Co-Founder'])['breakdown'], 'key'));
        $this->assertNotContains('intent_keywords', array_column($this->score(['email' => 'a@b.example', 'message' => 'Overpriced stuff'])['breakdown'], 'key'));
    }

    public function test_disposable_addresses_are_penalised_and_spam_overrides_the_tier(): void
    {
        $this->assertContains('disposable_email', array_column($this->score(['email' => 'a@mailinator.com'])['breakdown'], 'key'));

        $spam = $this->score($this->goodLead(['message' => 'Best SEO services and cheap backlinks']));
        $this->assertTrue($spam['spam']);
        $this->assertSame('spam', $spam['tier']);

        $this->assertSame('spam', $this->score($this->goodLead(['website' => 'http://x.example']))['tier']);
    }

    private function lead(array $attrs): Lead
    {
        return Lead::create($attrs + ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'lead_source_id' => $this->source()->id, 'status' => 'received']);
    }

    public function test_routing_prefers_the_owner_covering_the_country_and_segment(): void
    {
        $de = $this->owner('Lucas Meyer', 'DE,NL', 'enterprise');
        $this->owner('Daniel Reyes', 'US');
        $this->owner('Sam Ortega');

        $lead = $this->lead(['country' => 'DE', 'job_title' => 'CTO', 'tier' => 'hot']);
        $choice = app(LeadRouter::class)->choose($lead);

        $this->assertSame($de->id, $choice['owner']->id);
        $this->assertStringContainsString('covers DE', $choice['reason']);
        $this->assertStringContainsString('handles enterprise', $choice['reason']);
    }

    public function test_routing_falls_back_to_owners_covering_any_region(): void
    {
        $this->owner('Daniel Reyes', 'US');
        $anywhere = $this->owner('Sam Ortega');

        $choice = app(LeadRouter::class)->choose($this->lead(['country' => 'BR', 'tier' => 'cold']));

        $this->assertSame($anywhere->id, $choice['owner']->id);
        $this->assertStringContainsString('any region', $choice['reason']);
    }

    public function test_routing_balances_load_and_respects_daily_capacity(): void
    {
        $a = $this->owner('Anna A', 'US', null, 2);
        $b = $this->owner('Ben B', 'US', null, 5);
        $router = app(LeadRouter::class);

        $picked = [];
        for ($i = 0; $i < 7; $i++) {
            $lead = $this->lead(['country' => 'US', 'tier' => 'cold']);
            $owner = $router->choose($lead)['owner'];
            $lead->update(['owner_id' => $owner?->id, 'routed_at' => now()]);
            $picked[] = $owner?->name;
        }

        $this->assertSame(['Anna A', 'Ben B', 'Anna A', 'Ben B', 'Ben B', 'Ben B', 'Ben B'], $picked, 'Even split until Anna is full (2), then Ben until full (5)');
        $this->assertNull($router->choose($this->lead(['country' => 'US', 'tier' => 'cold']))['owner'], 'Everyone is full');
    }

    public function test_inactive_owners_are_skipped_and_nobody_available_means_unassigned(): void
    {
        $this->owner('Off Duty', 'US', null, 20, active: false);

        $choice = app(LeadRouter::class)->choose($this->lead(['country' => 'US', 'tier' => 'cold']));

        $this->assertNull($choice['owner']);
        $this->assertStringContainsString('unassigned', $choice['reason']);
    }

    public function test_when_no_owner_covers_the_country_and_nobody_is_global_the_lead_is_unassigned(): void
    {
        $this->owner('Daniel Reyes', 'US');

        $choice = app(LeadRouter::class)->choose($this->lead(['country' => 'JP', 'tier' => 'cold']));

        $this->assertNull($choice['owner']);
        $this->assertStringContainsString('JP', $choice['reason']);
    }

    public function test_segment_mismatch_keeps_the_region_match_instead_of_dropping_the_lead(): void
    {
        $smbOnly = $this->owner('Daniel Reyes', 'US', 'smb');

        $choice = app(LeadRouter::class)->choose($this->lead(['country' => 'US', 'job_title' => 'CEO', 'tier' => 'hot']));

        $this->assertSame($smbOnly->id, $choice['owner']->id);
        $this->assertStringContainsString('segment not matched', $choice['reason']);
    }
}
