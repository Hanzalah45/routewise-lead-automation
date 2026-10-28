<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
