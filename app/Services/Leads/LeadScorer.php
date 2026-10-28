<?php

namespace App\Services\Leads;

use App\Models\ScoringRule;
use Illuminate\Support\Str;

/**
 * Transparent rule scoring. Each rule has editable points (scoring_rules table); the breakdown shows exactly
 * which rules fired, so a score can always be explained. This is rules, not machine learning.
 */
class LeadScorer
{
    /** @return array{score:int,tier:string,spam:bool,breakdown:list<array{key:string,label:string,points:int,detail:string}>} */
    public function score(array $lead): array
    {
        $rules = ScoringRule::where('active', true)->get()->keyBy('key');
        $breakdown = [];
        $fire = function (string $key, string $detail) use ($rules, &$breakdown) {
            if ($rule = $rules->get($key)) {
                $breakdown[] = ['key' => $key, 'label' => $rule->label, 'points' => $rule->points, 'detail' => $detail];
            }
        };

        $message = Str::lower((string) ($lead['message'] ?? ''));
        $title = Str::lower((string) ($lead['job_title'] ?? ''));

        if ($lead['is_disposable_email'] ?? false) {
            $fire('disposable_email', 'Disposable address domain');
        } elseif ($lead['is_free_email'] ?? false) {
            $fire('free_email', 'Personal mailbox provider');
        } elseif (! empty($lead['company_domain'])) {
            $fire('corporate_email', $lead['company_domain']);
        }

        if ($hit = $this->firstMatch($title, config('routewise.seniority_keywords'))) {
            $fire('senior_title', "Title contains \"{$hit}\"");
        }
        if (! empty($lead['company'])) {
            $fire('company_provided', $lead['company']);
        }
        if (! empty($lead['phone_e164'])) {
            $fire('phone_provided', $lead['phone_e164']);
        }
        if ($hit = $this->firstMatch($message, config('routewise.intent_keywords'))) {
            $fire('intent_keywords', "Message mentions \"{$hit}\"");
        }
        if (mb_strlen($message) >= 80) {
            $fire('long_message', mb_strlen($message).' characters');
        }
        if (in_array($lead['country'] ?? null, config('routewise.priority_countries'), true)) {
            $fire('priority_country', $lead['country']);
        }
        if (empty($lead['first_name']) && empty($lead['last_name'])) {
            $fire('missing_name', 'No name given');
        }

        $spamHit = $this->firstMatch($message.' '.$title, config('routewise.spam_keywords'));
        $spam = ($lead['honeypot'] ?? false) || $spamHit !== null;
        if ($spam) {
            $fire('spam_signal', ($lead['honeypot'] ?? false) ? 'Hidden field was filled in' : "Contains \"{$spamHit}\"");
        }

        $score = max(0, min(100, array_sum(array_column($breakdown, 'points'))));

        return ['score' => $score, 'tier' => $spam ? 'spam' : $this->tier($score), 'spam' => $spam, 'breakdown' => $breakdown];
    }

    public function tier(int $score): string
    {
        return match (true) {
            $score >= config('routewise.tiers.hot') => 'hot',
            $score >= config('routewise.tiers.warm') => 'warm',
            default => 'cold',
        };
    }

    private function firstMatch(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle) {
            if ($haystack !== '' && preg_match('/\b'.preg_quote($needle, '/').'(?:s|es)?\b/u', $haystack)) {
                return $needle;
            }
        }

        return null;
    }
}
