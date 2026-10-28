<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inbound lead pipeline: store -> spam check -> consent -> duplicate check -> score -> route -> notify.
 * CRM delivery is a separate step (CrmSyncService) so a CRM outage never loses a lead.
 */
class LeadPipeline
{
    public function __construct(
        private readonly LeadNormalizer $normalizer,
        private readonly LeadScorer $scorer,
        private readonly LeadRouter $router,
    ) {}

    public function ingest(LeadSource $source, array $payload, ?string $externalId = null): Lead
    {
        return DB::transaction(function () use ($source, $payload, $externalId) {
            $n = $this->normalizer->normalise($payload);
            $result = $this->scorer->score($n);

            $lead = Lead::create([
                'uuid' => (string) Str::uuid(),
                'lead_source_id' => $source->id,
                'external_id' => $externalId,
                'first_name' => $n['first_name'], 'last_name' => $n['last_name'],
                'email' => $n['email'], 'phone' => $n['phone'], 'company' => $n['company'],
                'job_title' => $n['job_title'], 'country' => $n['country'], 'message' => $n['message'],
                'consent' => $n['consent'],
                'raw_payload' => $payload,
                'email_normalized' => $n['email_normalized'], 'phone_e164' => $n['phone_e164'],
                'company_domain' => $n['company_domain'], 'is_free_email' => $n['is_free_email'],
                'score' => $result['score'], 'score_breakdown' => $result['breakdown'], 'tier' => $result['tier'],
                'status' => Lead::STATUS_RECEIVED,
                'received_at' => now(),
            ]);
            $lead->record('received', "Received from {$source->name}.", ['source' => $source->slug]);

            if ($result['spam']) {
                $lead->update(['status' => Lead::STATUS_SPAM]);
                $lead->record('spam', 'Marked as spam: '.collect($result['breakdown'])->firstWhere('key', 'spam_signal')['detail'].'. Not sent to the CRM.');

                return $lead;
            }

            if ($n['consent'] === false) {
                $lead->update(['status' => Lead::STATUS_HELD]);
                $lead->record('held', 'The lead did not consent to be contacted, so it is stored but not routed or sent to the CRM.');

                return $lead;
            }

            if ($original = $this->findDuplicate($lead)) {
                $lead->update(['status' => Lead::STATUS_DUPLICATE, 'duplicate_of_id' => $original->id]);
                $lead->record('duplicate', "Duplicate of lead #{$original->id} ({$original->fullName()}). Not sent again.", ['original' => $original->uuid]);
                $original->record('duplicate_seen', "Another submission arrived from the same person (lead #{$lead->id}).");

                return $lead;
            }

            $lead->record('scored', "Scored {$lead->score} ({$lead->tier}).", ['breakdown' => $result['breakdown']]);

            $choice = $this->router->choose($lead);
            $lead->update(['owner_id' => $choice['owner']?->id, 'status' => Lead::STATUS_ROUTED, 'routed_at' => now()]);
            $lead->record($choice['owner'] ? 'routed' : 'unassigned', $choice['reason']);

            $this->notify($lead->refresh());

            return $lead;
        });
    }

    private function findDuplicate(Lead $lead): ?Lead
    {
        return Lead::where('id', '!=', $lead->id)
            ->whereNotIn('status', [Lead::STATUS_SPAM, Lead::STATUS_DUPLICATE, Lead::STATUS_HELD])
            ->where(function ($q) use ($lead) {
                $q->when($lead->email_normalized, fn ($w) => $w->orWhere('email_normalized', $lead->email_normalized))
                    ->when($lead->phone_e164, fn ($w) => $w->orWhere('phone_e164', $lead->phone_e164));
            })
            ->orderBy('id')->first();
    }

    /** Records the notifications a real deployment would send. Nothing is sent from this application. */
    private function notify(Lead $lead): void
    {
        if ($lead->tier === 'hot') {
            OutboxMessage::create([
                'lead_id' => $lead->id, 'channel' => 'slack', 'recipient' => '#sales-hot-leads',
                'subject' => "Hot lead: {$lead->fullName()}".($lead->company ? " ({$lead->company})" : ''),
                'body' => "Score {$lead->score}. ".($lead->owner ? "Routed to {$lead->owner->name}." : 'Unassigned.').' '.Str::limit((string) $lead->message, 160),
            ]);
        }
        if ($lead->owner) {
            OutboxMessage::create([
                'lead_id' => $lead->id, 'channel' => 'email', 'recipient' => $lead->owner->email,
                'subject' => "New {$lead->tier} lead assigned: {$lead->fullName()}",
                'body' => "{$lead->fullName()} ".($lead->company ? "from {$lead->company} " : '')."was assigned to you (score {$lead->score}).",
            ]);
        }
    }
}
