<?php

namespace App\Services\Crm;

use App\Models\CrmSyncAttempt;
use App\Models\Lead;
use Illuminate\Support\Carbon;

/**
 * Delivers one routed lead to the CRM with an audit trail.
 *
 *  - Progress is saved after each CRM call, so a retry after "contact saved, deal failed" does not create a second contact.
 *  - Transient failures (429, 5xx, timeout) schedule the next attempt with backoff, honouring Retry-After when it is longer.
 *  - Permanent failures (rejected data, bad credentials) and exhausted retries go to "dead": a person must look.
 */
class CrmSyncService
{
    public function __construct(private readonly CrmGateway $crm) {}

    public function sync(Lead $lead): Lead
    {
        if (! in_array($lead->status, [Lead::STATUS_ROUTED, Lead::STATUS_RETRYING], true)) {
            return $lead;
        }
        if ($lead->email === null) {
            return $this->failPermanently($lead, 1, null, 'The lead has no email address, which the CRM requires to create a contact.', 0);
        }

        $attempt = $lead->sync_attempts + 1;
        $started = hrtime(true);

        try {
            $contactId = $lead->crm_contact_id ?? $this->crm->upsertContact($lead);
            if ($lead->crm_contact_id === null) {
                $lead->update(['crm_contact_id' => $contactId]);
            }

            $dealId = $lead->crm_deal_id ?? $this->crm->createDeal($lead, $contactId);
        } catch (CrmTransientException $e) {
            return $this->scheduleRetry($lead, $attempt, $e, $this->elapsed($started));
        } catch (CrmPermanentException $e) {
            return $this->failPermanently($lead, $attempt, $e->status, $e->getMessage(), $this->elapsed($started));
        }

        $ms = $this->elapsed($started);
        $lead->update([
            'crm_deal_id' => $dealId, 'status' => Lead::STATUS_SYNCED, 'synced_at' => now(),
            'sync_attempts' => $attempt, 'next_retry_at' => null, 'last_error' => null,
        ]);
        $this->log($lead, $attempt, 'success', 200, $ms, null);
        $lead->record('synced', "Saved to the CRM (contact {$lead->crm_contact_id}, deal {$dealId}) on attempt {$attempt}.");

        return $lead->refresh();
    }

    /** A person pressed "Retry now": allow a fresh set of attempts, keeping the saved contact id. */
    public function retryNow(Lead $lead, string $by): Lead
    {
        if (! $lead->canRetry()) {
            return $lead;
        }
        $lead->update(['status' => Lead::STATUS_ROUTED, 'sync_attempts' => 0, 'next_retry_at' => null]);
        $lead->record('manual_retry', "Retry requested by {$by}.");

        return $this->sync($lead->refresh());
    }

    /** Runs due retries (called by `leads:sync-pending` every minute). @return int leads processed */
    public function runDue(?Carbon $now = null, int $limit = 50): int
    {
        $now ??= now();
        $due = Lead::where('status', Lead::STATUS_RETRYING)->where('next_retry_at', '<=', $now)->orderBy('next_retry_at')->limit($limit)->get();
        foreach ($due as $lead) {
            $this->sync($lead);
        }

        return $due->count();
    }

    private function scheduleRetry(Lead $lead, int $attempt, CrmException $e, int $ms): Lead
    {
        $max = (int) config('routewise.crm.max_attempts');
        if ($attempt >= $max) {
            $this->log($lead, $attempt, 'retry', $e->status, $ms, $e->getMessage());
            $lead->update(['status' => Lead::STATUS_DEAD, 'sync_attempts' => $attempt, 'next_retry_at' => null, 'last_error' => $e->getMessage()]);
            $lead->record('dead', "Gave up after {$attempt} attempts: {$e->getMessage()} Use Retry now once the CRM is healthy.");

            return $lead->refresh();
        }

        $backoff = config('routewise.crm.backoff_seconds');
        $wait = max($backoff[$attempt - 1] ?? end($backoff), $e->retryAfter ?? 0);
        $next = now()->addSeconds($wait);

        $this->log($lead, $attempt, 'retry', $e->status, $ms, $e->getMessage());
        $lead->update(['status' => Lead::STATUS_RETRYING, 'sync_attempts' => $attempt, 'next_retry_at' => $next, 'last_error' => $e->getMessage()]);
        $lead->record('retry_scheduled', "Attempt {$attempt} failed: {$e->getMessage()} Next attempt in {$wait}s.");

        return $lead->refresh();
    }

    private function failPermanently(Lead $lead, int $attempt, ?int $status, string $message, int $ms): Lead
    {
        $this->log($lead, $attempt, 'permanent_failure', $status, $ms, $message);
        $lead->update(['status' => Lead::STATUS_DEAD, 'sync_attempts' => $attempt, 'next_retry_at' => null, 'last_error' => $message]);
        $lead->record('dead', "Not retried: {$message}");

        return $lead->refresh();
    }

    private function log(Lead $lead, int $attempt, string $outcome, ?int $status, int $ms, ?string $error): void
    {
        CrmSyncAttempt::create([
            'lead_id' => $lead->id, 'attempt' => $attempt, 'outcome' => $outcome,
            'http_status' => $status, 'duration_ms' => $ms, 'error' => $error ? mb_substr($error, 0, 250) : null,
        ]);
    }

    private function elapsed(int $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }
}
