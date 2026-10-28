<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\ScoringRule;
use App\Services\Leads\LeadNormalizer;
use Illuminate\Console\Command;

/**
 * Advisory backtest of the scoring rules against real outcomes.
 *
 * Input: a CSV with columns `email` and `outcome` (won / lost; also 1 / 0, yes / no), typically exported from the CRM.
 * Leads are matched on canonical email. For each tier and each rule it reports how often leads converted, and for rules
 * a suggested point value from the log odds ratio (with +0.5 smoothing). It never changes any rule: a person decides.
 * Rules with fewer than --min-sample leads on either side are flagged as too thin to trust.
 */
class CalibrateScoring extends Command
{
    protected $signature = 'routewise:calibrate {csv : Path to a CSV with email and outcome columns} {--min-sample=30 : Minimum leads on each side before a suggestion is trusted}';

    protected $description = 'Compare scoring tiers and rules with real won/lost outcomes and suggest point values (advisory only).';

    public function handle(LeadNormalizer $normalizer): int
    {
        $path = $this->argument('csv');
        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), (array) fgetcsv($handle));
        $emailCol = array_search('email', $header, true);
        $outcomeCol = array_search('outcome', $header, true);
        if ($emailCol === false || $outcomeCol === false) {
            $this->error('The CSV needs a header row with "email" and "outcome" columns.');

            return self::FAILURE;
        }

        $outcomes = [];
        while (($row = fgetcsv($handle)) !== false) {
            $email = $normalizer->normalise(['email' => $row[$emailCol] ?? ''])['email_normalized'] ?? null;
            $won = $this->won($row[$outcomeCol] ?? '');
            if ($email !== null && $won !== null) {
                $outcomes[$email] = $won;
            }
        }
        fclose($handle);

        $leads = Lead::whereIn('email_normalized', array_keys($outcomes))->whereNotIn('status', [Lead::STATUS_DUPLICATE, Lead::STATUS_SPAM])->get();
        if ($leads->isEmpty()) {
            $this->warn('No lead in the database matches an email in the CSV.');

            return self::FAILURE;
        }

        $rows = $leads->map(fn (Lead $l) => ['won' => $outcomes[$l->email_normalized], 'tier' => $l->tier, 'rules' => array_column($l->score_breakdown ?? [], 'key')]);
        $wins = $rows->where('won', true)->count();
        $this->info(sprintf('%d leads matched (%d won, base rate %s).', $rows->count(), $wins, $this->pct($wins, $rows->count())));

        $this->newLine();
        $this->line('<options=bold>By tier</>');
        $this->table(['Tier', 'Leads', 'Won', 'Win rate'], collect(['hot', 'warm', 'cold'])->map(function ($tier) use ($rows) {
            $in = $rows->where('tier', $tier);

            return [$tier, $in->count(), $in->where('won', true)->count(), $this->pct($in->where('won', true)->count(), $in->count())];
        })->all());

        $this->line('<options=bold>By rule</> (suggestion = 10 x ln(odds ratio), clamped to -30..30)');
        $min = (int) $this->option('min-sample');
        $table = [];
        foreach (ScoringRule::orderBy('sort')->get() as $rule) {
            $with = $rows->filter(fn ($r) => in_array($rule->key, $r['rules'], true));
            $without = $rows->reject(fn ($r) => in_array($rule->key, $r['rules'], true));
            $w1 = $with->where('won', true)->count();
            $w0 = $without->where('won', true)->count();
            $thin = $with->count() < $min || $without->count() < $min;
            $table[] = [$rule->key, $rule->points, $with->count(), $this->pct($w1, $with->count()), $this->pct($w0, $without->count()),
                $thin ? 'too thin' : $this->suggest($w1, $with->count() - $w1, $w0, $without->count() - $w0)];
        }
        $this->table(['Rule', 'Now', 'Leads with', 'Win rate with', 'Win rate without', 'Suggested'], $table);

        $this->warn("Advisory only. Nothing was changed. A suggestion needs at least {$min} leads with and without the rule, and correlation is not proof of cause.");

        return self::SUCCESS;
    }

    private function won(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            'won', 'win', '1', 'yes', 'true', 'closed won' => true,
            'lost', 'loss', '0', 'no', 'false', 'closed lost' => false,
            default => null,
        };
    }

    private function pct(int $part, int $of): string
    {
        return $of === 0 ? '-' : round($part / $of * 100).'%';
    }

    private function suggest(int $winWith, int $lossWith, int $winWithout, int $lossWithout): string
    {
        $oddsWith = ($winWith + 0.5) / ($lossWith + 0.5);
        $oddsWithout = ($winWithout + 0.5) / ($lossWithout + 0.5);
        $points = (int) round(max(-30, min(30, 10 * log($oddsWith / $oddsWithout))));

        return ($points > 0 ? '+' : '').$points;
    }
}
