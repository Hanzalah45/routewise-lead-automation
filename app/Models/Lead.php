<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $guarded = [];

    public const STATUS_RECEIVED = 'received';
    public const STATUS_HELD = 'held';             // no consent: stored, never sent anywhere
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_SPAM = 'spam';
    public const STATUS_ROUTED = 'routed';         // owner chosen, CRM sync pending
    public const STATUS_SYNCED = 'synced';
    public const STATUS_RETRYING = 'retrying';     // a sync failed, next attempt scheduled
    public const STATUS_DEAD = 'dead';             // retries exhausted or permanent CRM error: needs a person

    public const STATUSES = [
        self::STATUS_RECEIVED, self::STATUS_HELD, self::STATUS_DUPLICATE, self::STATUS_SPAM,
        self::STATUS_ROUTED, self::STATUS_SYNCED, self::STATUS_RETRYING, self::STATUS_DEAD,
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'score_breakdown' => 'array',
            'consent' => 'boolean',
            'is_free_email' => 'boolean',
            'next_retry_at' => 'datetime',
            'routed_at' => 'datetime',
            'synced_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function owner()
    {
        return $this->belongsTo(Owner::class);
    }

    public function duplicateOf()
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function events()
    {
        return $this->hasMany(LeadEvent::class)->orderBy('id');
    }

    public function attempts()
    {
        return $this->hasMany(CrmSyncAttempt::class)->orderBy('id');
    }

    public function fullName(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : ($this->email ?? 'Unknown lead');
    }

    public function record(string $type, string $message, array $meta = []): LeadEvent
    {
        return $this->events()->create(['type' => $type, 'message' => $message, 'meta' => $meta ?: null]);
    }

    /** Leads whose CRM sync can be retried by a person. */
    public function canRetry(): bool
    {
        return in_array($this->status, [self::STATUS_RETRYING, self::STATUS_DEAD, self::STATUS_ROUTED], true);
    }
}
