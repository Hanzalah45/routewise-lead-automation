<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\ScoringRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\SetsUpRoutewise;
use Tests\TestCase;

class CalibrateScoringTest extends TestCase
{
    use RefreshDatabase, SetsUpRoutewise;

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cal').'.csv';
        file_put_contents($path, "Email,Outcome\n".collect($rows)->map(fn ($r) => implode(',', $r))->implode("\n"));

        return $path;
    }

    /** 40 leads with a senior title (30 won) and 40 without (8 won): the senior_title rule is clearly predictive. */
    private function seedOutcomes(): array
    {
        $this->seedScoring();
        $source = $this->source();
        $rows = [];
        foreach (range(1, 80) as $i) {
            $senior = $i <= 40;
            $won = $senior ? $i <= 30 : $i - 40 <= 8;
            $this->webhook($source, ['email' => "lead{$i}@company{$i}.example", 'name' => "Lead {$i}", 'job_title' => $senior ? 'Director' : 'Analyst', 'consent' => true])->assertCreated();
            $rows[] = ["lead{$i}@company{$i}.example", $won ? 'won' : 'lost'];
        }

        return $rows;
    }

    public function test_it_reports_win_rates_by_tier_and_suggests_points_for_predictive_rules(): void
    {
        $path = $this->csv($this->seedOutcomes());

        $this->artisan('routewise:calibrate', ['csv' => $path])
            ->expectsOutputToContain('80 leads matched (38 won, base rate 48%)')
            ->expectsOutputToContain('Advisory only. Nothing was changed.')
            ->assertSuccessful();

        @unlink($path);
    }

    public function test_it_suggests_a_positive_value_for_the_rule_that_predicts_wins_and_changes_nothing(): void
    {
        $path = $this->csv($this->seedOutcomes());
        $before = ScoringRule::pluck('points', 'key')->all();

        Artisan::call('routewise:calibrate', ['csv' => $path]);
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/senior_title\s*\|\s*20\s*\|\s*40\s*\|\s*75%\s*\|\s*20%\s*\|\s*\+24/', $out);

        $this->assertSame($before, ScoringRule::pluck('points', 'key')->all(), 'The command is advisory and must not edit rules');
        @unlink($path);
    }

    public function test_thin_samples_are_flagged_instead_of_suggested(): void
    {
        $this->seedScoring();
        $this->webhook($this->source(), $this->goodLead(['email' => 'one@one.example']))->assertCreated();
        $path = $this->csv([['one@one.example', 'won']]);

        $this->artisan('routewise:calibrate', ['csv' => $path])->expectsOutputToContain('too thin')->assertSuccessful();
        @unlink($path);
    }

    public function test_emails_are_matched_canonically_and_unknown_outcomes_are_ignored(): void
    {
        $this->seedScoring();
        $this->webhook($this->source(), $this->goodLead(['email' => 'Elena@Northbeam-Logistics.example']))->assertCreated();
        $path = $this->csv([['elena+promo@northbeam-logistics.example', 'Closed Won'], ['nobody@nowhere.example', 'lost'], ['x@y.example', 'maybe']]);

        $this->artisan('routewise:calibrate', ['csv' => $path])->expectsOutputToContain('1 leads matched (1 won')->assertSuccessful();
        @unlink($path);
    }

    public function test_bad_input_is_reported(): void
    {
        $this->artisan('routewise:calibrate', ['csv' => '/no/such/file.csv'])->assertFailed();

        $path = tempnam(sys_get_temp_dir(), 'cal');
        file_put_contents($path, "mail,result\na@b.example,won\n");
        $this->artisan('routewise:calibrate', ['csv' => $path])->expectsOutputToContain('needs a header row')->assertFailed();

        $this->seedScoring();
        $this->assertSame(0, Lead::count());
        $this->artisan('routewise:calibrate', ['csv' => $this->csv([['a@b.example', 'won']])])->expectsOutputToContain('No lead in the database matches')->assertFailed();
        @unlink($path);
    }
}
