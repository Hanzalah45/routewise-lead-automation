<?php

namespace Database\Seeders;

use App\Models\ScoringRule;
use Illuminate\Database\Seeder;

class ScoringRuleSeeder extends Seeder
{
    /** @return list<array{key:string,label:string,description:string,points:int}> */
    public static function defaults(): array
    {
        return [
            ['key' => 'corporate_email', 'label' => 'Company email', 'description' => 'Email is on a company domain, not a personal or disposable provider.', 'points' => 15],
            ['key' => 'senior_title', 'label' => 'Senior job title', 'description' => 'Title contains a seniority word such as director, VP, founder, chief.', 'points' => 20],
            ['key' => 'company_provided', 'label' => 'Company named', 'description' => 'The lead filled in a company name.', 'points' => 10],
            ['key' => 'phone_provided', 'label' => 'Valid phone number', 'description' => 'A phone number that normalises to a valid international number.', 'points' => 5],
            ['key' => 'intent_keywords', 'label' => 'Buying intent in message', 'description' => 'Message mentions pricing, a demo, a quote, a budget or urgency.', 'points' => 15],
            ['key' => 'long_message', 'label' => 'Detailed message', 'description' => 'Message is at least 80 characters.', 'points' => 5],
            ['key' => 'priority_country', 'label' => 'Priority market', 'description' => 'Country is on the priority list (US, GB, CA, AU, DE, NL, SE, AE).', 'points' => 10],
            ['key' => 'free_email', 'label' => 'Personal email', 'description' => 'Gmail, Yahoo and similar providers.', 'points' => -10],
            ['key' => 'missing_name', 'label' => 'No name', 'description' => 'Neither a first nor a last name was given.', 'points' => -5],
            ['key' => 'disposable_email', 'label' => 'Disposable email', 'description' => 'Throwaway address providers such as Mailinator.', 'points' => -40],
            ['key' => 'spam_signal', 'label' => 'Spam signal', 'description' => 'Hidden field filled in or a spam phrase. Forces the lead to the spam tier.', 'points' => -50],
        ];
    }

    public function run(): void
    {
        foreach (self::defaults() as $i => $rule) {
            ScoringRule::updateOrCreate(['key' => $rule['key']], $rule + ['active' => true, 'sort' => $i]);
        }
    }
}
