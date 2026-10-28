<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Owner;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Crm\CrmSyncService;
use App\Services\Leads\LeadPipeline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo data. Every lead goes through the real pipeline (normalise, score, route, sync to the Mock CRM), then its
 * timestamps are moved into the past so the dashboard has two weeks of history. All people and companies are invented.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@routewise.test'], ['name' => 'Amara Okafor', 'password' => 'password', 'role' => User::ROLE_ADMIN]);
        User::updateOrCreate(['email' => 'agent@routewise.test'], ['name' => 'Jonas Weber', 'password' => 'password', 'role' => User::ROLE_AGENT]);

        $this->call(ScoringRuleSeeder::class);

        foreach ([
            ['Maya Brooks', 'maya.brooks@routewise.test', 'US,CA', 'enterprise', 12, 'own_101'],
            ['Daniel Reyes', 'daniel.reyes@routewise.test', 'US,CA', 'smb', 25, 'own_102'],
            ['Priya Nair', 'priya.nair@routewise.test', 'GB,IE,DE,NL,SE,FR', 'enterprise,smb', 20, 'own_103'],
            ['Lucas Meyer', 'lucas.meyer@routewise.test', 'DE,NL,SE,CH', 'smb', 20, 'own_104'],
            ['Omar Haddad', 'omar.haddad@routewise.test', 'AE,SA,PK,IN', null, 20, 'own_105'],
            ['Chloe Wright', 'chloe.wright@routewise.test', 'AU,NZ,SG', null, 20, 'own_106'],
            ['Sam Ortega', 'sam.ortega@routewise.test', null, 'smb', 15, 'own_107'],
        ] as [$name, $email, $regions, $segments, $cap, $crm]) {
            Owner::updateOrCreate(['email' => $email], ['name' => $name, 'regions' => $regions, 'segments' => $segments, 'capacity_per_day' => $cap, 'active' => true, 'crm_owner_id' => $crm]);
        }

        $sources = [];
        foreach ([['website', 'Website contact form'], ['meta-ads', 'Meta lead ads'], ['typeform', 'Typeform via n8n']] as [$slug, $name]) {
            $source = LeadSource::firstOrNew(['slug' => $slug]);
            $source->name = $name;
            $source->active = true;
            $source->setSecret('whsec_demo_'.str_replace('-', '', $slug).'_'.str_repeat('0', 24));
            $source->save();
            $sources[$slug] = $source;
        }

        Setting::put('mock_crm.mode', 'ok');
        Setting::put('mock_crm.fail_remaining', '0');

        $this->demoLeads($sources);

        Setting::put('mock_crm.mode', 'ok');
        Setting::put('mock_crm.fail_remaining', '0');

        $total = Lead::count();
        $this->command?->info("{$total} demo leads created. Sign in with admin@routewise.test / password.");
    }

    /** @param  array<string,LeadSource>  $sources */
    private function demoLeads(array $sources): void
    {
        $pipeline = app(LeadPipeline::class);
        $sync = app(CrmSyncService::class);

        // [source, payload, days ago, hour, crm behaviour]
        $rows = [
            ['website', ['name' => 'Elena Fischer', 'email' => 'elena.fischer@northbeam-logistics.example', 'phone' => '+49 30 5550 1187', 'company' => 'Northbeam Logistics', 'job_title' => 'Head of Operations', 'country' => 'DE', 'message' => 'We are replacing our lead intake and need pricing for about 40 seats. Can we get a demo this week?', 'consent' => true], 13, 9],
            ['website', ['name' => 'Marcus Lee', 'email' => 'marcus@brightpath-dental.example', 'phone' => '(415) 555-0142', 'company' => 'Brightpath Dental Group', 'job_title' => 'Practice Owner', 'country' => 'US', 'message' => 'Looking for a quote. We get roughly 300 enquiries a month from three websites and lose track of them.', 'consent' => true], 13, 11],
            ['meta-ads', ['full_name' => 'tariq mahmood', 'email' => 'tariq.mahmood@gmail.com', 'phone' => '0300 5550198', 'country' => 'PK', 'message' => 'interested', 'consent' => true], 12, 8],
            ['website', ['name' => 'Sofia Alvarez', 'email' => 'sofia.alvarez@harborview-realty.example', 'phone' => '+1 305 555 0176', 'company' => 'Harborview Realty', 'job_title' => 'Sales Director', 'country' => 'US', 'message' => 'Budget approved for Q4. We need a way to route inbound buyers to agents by neighbourhood.', 'consent' => true], 12, 14],
            ['typeform', ['first_name' => 'Hannah', 'last_name' => 'Cole', 'email' => 'hannah.cole@cedarpoint-capital.example', 'company' => 'Cedar Point Capital', 'job_title' => 'VP Marketing', 'country' => 'GB', 'message' => 'Please send pricing and a proposal. Contract decision is next month.', 'consent' => true], 11, 10],
            ['meta-ads', ['name' => 'Ravi Kapoor', 'email' => 'ravi.kapoor@yahoo.com', 'phone' => '98765 43210', 'country' => 'IN', 'message' => 'Call me', 'consent' => true], 11, 16],
            ['website', ['name' => 'Isabelle Moreau', 'email' => 'isabelle.moreau@lumiere-studio.example', 'company' => 'Lumière Studio', 'job_title' => 'Founder', 'country' => 'FR', 'message' => 'We run a small design studio and want to stop copying form entries into the CRM by hand. How does setup work and what does it cost?', 'consent' => true], 10, 9],
            ['website', ['name' => 'Tom Becker', 'email' => 'tom.becker@mailinator.com', 'company' => 'x', 'message' => 'test', 'consent' => true], 10, 13],
            ['website', ['name' => 'Aisha Rahman', 'email' => 'aisha.rahman@gulfline-freight.example', 'phone' => '+971 4 555 0133', 'company' => 'Gulfline Freight', 'job_title' => 'Chief Commercial Officer', 'country' => 'AE', 'message' => 'Urgent: we need an automated lead hand-off before our regional campaign starts on the 20th. Please call.', 'consent' => true], 9, 8],
            ['meta-ads', ['name' => 'Peter Novak', 'email' => 'peter.novak@outlook.com', 'phone' => '+420 555 019 233', 'country' => 'CZ', 'message' => 'How much?', 'consent' => true], 9, 12],
            ['typeform', ['first_name' => 'Grace', 'last_name' => 'Thompson', 'email' => 'grace.thompson@oakridge-schools.example', 'company' => 'Oakridge Schools', 'job_title' => 'Admissions Manager', 'country' => 'AU', 'message' => 'We want admissions enquiries to reach the right campus coordinator within minutes.', 'consent' => true], 8, 9],
            ['website', ['name' => 'Jonas Lindqvist', 'email' => 'jonas.lindqvist@fjordtech.example', 'phone' => '+46 8 555 019 88', 'company' => 'Fjordtech AB', 'job_title' => 'CTO', 'country' => 'SE', 'message' => 'Evaluating tools to sync web leads with our CRM. Do you support custom scoring and a trial?', 'consent' => true], 8, 15],
            ['website', ['name' => 'Linda Park', 'email' => 'linda.park@gmail.com', 'phone' => '212-555-0188', 'country' => 'US', 'message' => 'Hi, saw your page. Do you work with small shops?', 'consent' => true], 7, 10],
            ['meta-ads', ['name' => 'Nadia Sheikh', 'email' => 'nadia.sheikh@brightfuture-clinic.example', 'phone' => '+92 321 5550144', 'company' => 'Bright Future Clinic', 'job_title' => 'Manager', 'country' => 'PK', 'message' => 'Need a demo for our clinic network.', 'consent' => true], 7, 14],
            ['website', ['name' => 'Oliver Grant', 'email' => 'oliver.grant@stonebridge-law.example', 'phone' => '+44 20 7946 0958', 'company' => 'Stonebridge Law', 'job_title' => 'Managing Partner', 'country' => 'GB', 'message' => 'We lose enquiries over weekends. Quote for automated acknowledgement and routing to the duty solicitor, please.', 'consent' => true], 6, 9],
            ['website', ['name' => 'Casey Morgan', 'email' => 'casey@seo-boost-now.example', 'company' => 'Rank Fast', 'message' => 'We offer SEO services and cheap backlinks. Click here to see our packages', 'consent' => true], 6, 12],
            ['typeform', ['first_name' => 'Noah', 'last_name' => 'Williams', 'email' => 'noah.williams@summit-hvac.example', 'phone' => '(512) 555-0121', 'company' => 'Summit HVAC', 'job_title' => 'General Manager', 'country' => 'US', 'message' => 'Looking for pricing to route service calls from our website to the nearest technician.', 'consent' => true], 5, 8],
            ['website', ['name' => 'Fatima Zahra', 'email' => 'fatima.zahra@riyadh-trading.example', 'phone' => '+966 11 555 0167', 'company' => 'Riyadh Trading Co', 'job_title' => 'Director of Sales', 'country' => 'SA', 'message' => 'We would like a demo and pricing for the Gulf region.', 'consent' => true], 5, 13],
            ['meta-ads', ['name' => 'Ben Carter', 'email' => 'ben.carter@gmail.com', 'phone' => '+61 400 555 019', 'country' => 'AU', 'message' => 'Interested in learning more about your service', 'consent' => true], 4, 9],
            ['website', ['name' => 'Anika Schmidt', 'email' => 'anika.schmidt@bauwerk-gmbh.example', 'phone' => '+49 89 5550 142', 'company' => 'Bauwerk GmbH', 'job_title' => 'Geschäftsführerin (CEO)', 'country' => 'DE', 'message' => 'We receive tenders by form and email. Is a trial available and what is the pricing for 15 users?', 'consent' => true], 4, 11],
            ['website', ['name' => 'Diego Ramos', 'email' => 'diego.ramos@fresco-foods.example', 'company' => 'Fresco Foods', 'country' => 'MX', 'message' => 'Quote please', 'consent' => false], 3, 10],
            ['typeform', ['first_name' => 'Emily', 'last_name' => 'Chen', 'email' => 'emily.chen@pixelforge.example', 'phone' => '+65 6555 0194', 'company' => 'Pixelforge', 'job_title' => 'Head of Growth', 'country' => 'SG', 'message' => 'We run paid campaigns in 6 markets and need leads routed by market within minutes. Pricing and trial?', 'consent' => true], 3, 14],
            ['website', ['name' => 'Chris Dalton', 'email' => 'chris.dalton@tidewater-marine.example', 'phone' => '(206) 555-0163', 'company' => 'Tidewater Marine', 'job_title' => 'Owner', 'country' => 'US', 'message' => 'Need a proposal for lead capture from our boat show landing page.', 'consent' => true], 2, 9],
            ['meta-ads', ['name' => 'Zara Ali', 'email' => 'zara.ali@gmail.com', 'phone' => '+971 50 555 0172', 'country' => 'AE', 'message' => 'send details', 'consent' => true], 2, 12],
            ['website', ['name' => 'Henrik Olsen', 'email' => 'henrik.olsen@nordlys-energi.example', 'phone' => '+47 22 55 01 99', 'company' => 'Nordlys Energi', 'job_title' => 'Procurement Lead', 'country' => 'NO', 'message' => 'Request for a demo of the CRM sync and scoring for our sales team of 25.', 'consent' => true], 1, 8],
            ['typeform', ['first_name' => 'Rachel', 'last_name' => 'Kim', 'email' => 'rachel.kim@vantage-wealth.example', 'phone' => '+1 646 555 0149', 'company' => 'Vantage Wealth', 'job_title' => 'VP Business Development', 'country' => 'US', 'message' => 'We want inbound referral requests scored and assigned the same day. What does an enterprise plan cost?', 'consent' => true], 1, 10],
            ['website', ['name' => 'Yusuf Demir', 'email' => 'yusuf.demir@anatolia-textiles.example', 'company' => 'Anatolia Textiles', 'job_title' => 'Sales Manager', 'country' => 'TR', 'message' => 'Please send information.', 'consent' => true], 1, 15],
            ['website', ['name' => 'Mia Johansson', 'email' => 'mia.johansson@kvist-interiors.example', 'phone' => '+46 70 555 01 23', 'company' => 'Kvist Interiors', 'job_title' => 'Owner', 'country' => 'SE', 'message' => 'Small studio, 5 people. How quickly can we be live?', 'consent' => true], 0, 7],
            ['meta-ads', ['name' => 'Kevin Brooks', 'email' => 'kevin.brooks@hotmail.com', 'phone' => '(617) 555-0111', 'country' => 'US', 'message' => 'interested in a demo', 'consent' => true], 0, 8],
        ];

        foreach ($rows as [$slug, $payload, $daysAgo, $hour]) {
            $at = now()->subDays($daysAgo)->setTime($hour, rand(2, 55), rand(0, 59));
            if ($at->isFuture()) {
                $at = now()->subMinutes(rand(20, 120));   // today's later slots would be in the future
            }
            $this->deliver($pipeline, $sync, $sources[$slug], $payload, $at);
        }

        // Duplicates: the same person arriving again through another channel.
        $this->deliver($pipeline, $sync, $sources['meta-ads'], ['name' => 'Elena Fischer', 'email' => 'Elena.Fischer@northbeam-logistics.example', 'company' => 'Northbeam Logistics', 'country' => 'DE', 'message' => 'Following up on my request', 'consent' => true], now()->subDays(12)->setTime(10, 12));
        $this->deliver($pipeline, $sync, $sources['website'], ['name' => 'Linda Park', 'email' => 'linda.park+shop@gmail.com', 'country' => 'US', 'message' => 'Submitting again in case it did not go through', 'consent' => true], now()->subDays(6)->setTime(18, 40));

        // CRM trouble, on purpose: one lead waiting on a retry, one rejected for good, one that exhausted its retries.
        $this->crm('down', -1);
        $dead = $this->deliver($pipeline, $sync, $sources['website'], ['name' => 'Arthur Penn', 'email' => 'arthur.penn@keystone-surveys.example', 'phone' => '+44 161 496 0150', 'company' => 'Keystone Surveys', 'job_title' => 'Managing Director', 'country' => 'GB', 'message' => 'Demo and pricing please, we are choosing a tool this week.', 'consent' => true], now()->subHours(8));
        for ($i = 0; $i < 4; $i++) {
            $sync->runDue(now()->addDays(2 + $i));
        }
        $this->crm('ok', 0);

        $this->crm('rate_limited', 1);
        $this->deliver($pipeline, $sync, $sources['website'], ['name' => 'Victor Hugo', 'email' => 'victor.hugo@atelier-moderne.example', 'phone' => '+33 1 55 50 01 77', 'company' => 'Atelier Moderne', 'job_title' => 'Director', 'country' => 'FR', 'message' => 'We want a demo and pricing for our two showrooms.', 'consent' => true], now()->subMinutes(3));

        $this->crm('reject', 1);
        $this->deliver($pipeline, $sync, $sources['typeform'], ['first_name' => 'Laura', 'last_name' => 'Bianchi', 'email' => 'laura.bianchi@trattoria-nonna.example', 'company' => 'Trattoria Nonna', 'job_title' => 'Owner', 'country' => 'IT', 'message' => 'Interested in a quote for reservation leads.', 'consent' => true], now()->subHours(5));


        // Rejected deliveries, so the delivery log shows what a hostile or misconfigured sender looks like.
        foreach ([
            ['website', 'invalid_signature', 401, 'mismatch', now()->subDays(3)->setTime(3, 14)],
            ['website', 'expired', 401, 'expired', now()->subDays(2)->setTime(21, 5)],
            ['meta-ads', 'validation_failed', 422, 'email', now()->subDays(1)->setTime(11, 27)],
            ['typeform', 'invalid_signature', 401, 'missing', now()->subHours(20)],
        ] as [$slug, $outcome, $status, $error, $at]) {
            WebhookDelivery::create(['lead_source_id' => $sources[$slug]->id, 'source_slug' => $slug, 'ip' => '203.0.113.'.rand(10, 200), 'outcome' => $outcome, 'http_status' => $status, 'payload_sha256' => hash('sha256', $outcome.$at), 'payload_bytes' => rand(180, 640), 'error' => $error, 'created_at' => $at]);
        }
    }

    /** @param  array<string,mixed>  $payload */
    private function deliver(LeadPipeline $pipeline, CrmSyncService $sync, LeadSource $source, array $payload, Carbon $at): Lead
    {
        $lead = $pipeline->ingest($source, $payload);
        if ($lead->status === Lead::STATUS_ROUTED) {
            $lead = $sync->sync($lead);
        }

        WebhookDelivery::create([
            'lead_source_id' => $source->id, 'source_slug' => $source->slug, 'ip' => '198.51.100.'.rand(10, 200),
            'outcome' => 'accepted', 'http_status' => 201, 'lead_id' => $lead->id,
            'payload_sha256' => hash('sha256', json_encode($payload)), 'payload_bytes' => strlen(json_encode($payload)), 'created_at' => $at,
        ]);
        $source->forceFill(['last_used_at' => max($source->last_used_at ?? $at, $at)])->save();

        return $this->backdate($lead, $at);
    }

    /** Moves a lead and everything recorded about it to $at (+ a few seconds per step), so history looks lived-in. */
    private function backdate(Lead $lead, Carbon $at): Lead
    {
        $lead->refresh();
        $lead->forceFill([
            'received_at' => $at, 'created_at' => $at, 'updated_at' => $at->copy()->addSeconds(5),
            'routed_at' => $lead->routed_at ? $at->copy()->addSeconds(1) : null,
            'synced_at' => $lead->synced_at ? $at->copy()->addSeconds(2) : null,
            'next_retry_at' => $lead->status === Lead::STATUS_RETRYING ? now()->addSeconds(25) : $lead->next_retry_at,
        ])->saveQuietly();

        foreach ($lead->events as $i => $event) {
            DB::table('lead_events')->where('id', $event->id)->update(['created_at' => $at->copy()->addSeconds($i)]);
        }
        foreach ($lead->attempts as $i => $attempt) {
            DB::table('crm_sync_attempts')->where('id', $attempt->id)->update(['created_at' => $at->copy()->addSeconds(2 + $i * 60)]);
        }
        DB::table('outbox_messages')->where('lead_id', $lead->id)->update(['created_at' => $at->copy()->addSeconds(1)]);

        return $lead->refresh();
    }

    private function crm(string $mode, int $remaining): void
    {
        Setting::put('mock_crm.mode', $mode);
        Setting::put('mock_crm.fail_remaining', (string) $remaining);
    }
}
