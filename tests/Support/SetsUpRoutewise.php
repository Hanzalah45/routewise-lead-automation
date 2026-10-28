<?php

namespace Tests\Support;

use App\Models\LeadSource;
use App\Models\Owner;
use App\Models\Setting;
use App\Services\Webhooks\SignatureVerifier;
use Database\Seeders\ScoringRuleSeeder;
use Illuminate\Testing\TestResponse;

trait SetsUpRoutewise
{
    protected string $secret = 'whsec_test_secret';

    protected function seedScoring(): void
    {
        $this->seed(ScoringRuleSeeder::class);
        Setting::flush();
    }

    protected function source(string $slug = 'website', bool $active = true): LeadSource
    {
        if ($existing = LeadSource::where('slug', $slug)->first()) {
            return $existing;
        }

        $source = new LeadSource(['name' => ucfirst($slug), 'slug' => $slug, 'active' => $active]);
        $source->setSecret($this->secret);
        $source->save();

        return $source;
    }

    protected function owner(string $name, ?string $regions = null, ?string $segments = null, int $capacity = 20, bool $active = true): Owner
    {
        return Owner::create([
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@routewise.test', 'regions' => $regions,
            'segments' => $segments, 'capacity_per_day' => $capacity, 'active' => $active, 'crm_owner_id' => 'own_'.strtolower(substr($name, 0, 3)),
        ]);
    }

    /** @return array<string,mixed> a strong, complete lead */
    protected function goodLead(array $override = []): array
    {
        return $override + [
            'name' => 'Elena Fischer', 'email' => 'elena@northbeam-logistics.example', 'phone' => '+49 30 5550 1187',
            'company' => 'Northbeam Logistics', 'job_title' => 'Head of Operations', 'country' => 'DE',
            'message' => 'We need pricing for about 40 seats and would like a demo this week, please. Our current form-to-CRM process is manual.', 'consent' => true,
        ];
    }

    /** Sends a correctly signed webhook (or a deliberately wrong one via the overrides). */
    protected function webhook(LeadSource $source, array $payload, array $headers = [], ?int $timestamp = null, ?string $secret = null, ?string $rawBody = null): TestResponse
    {
        $body = $rawBody ?? json_encode($payload);
        $signature = app(SignatureVerifier::class)->sign($secret ?? $this->secret, $body, $timestamp);

        return $this->call('POST', "/api/v1/webhooks/{$source->slug}", [], [], [], $this->transformHeadersToServerVars($headers + [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ROUTEWISE_SIGNATURE' => $signature,
        ]), $body);
    }

    protected function crmMode(string $mode, int $remaining = -1): void
    {
        Setting::put('mock_crm.mode', $mode);
        Setting::put('mock_crm.fail_remaining', (string) $remaining);
    }
}
