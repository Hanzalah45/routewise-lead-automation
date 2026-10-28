<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A notification that would be sent. Nothing leaves this application: the outbox only records it. */
class OutboxMessage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
