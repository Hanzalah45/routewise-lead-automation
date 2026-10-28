<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class LeadSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_used_at' => 'datetime'];
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    /** The signing secret (decrypted). Never show it in full outside the one-time reveal. */
    public function secret(): string
    {
        return Crypt::decryptString($this->secret);
    }

    public function setSecret(string $plain): void
    {
        $this->secret = Crypt::encryptString($plain);
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function maskedSecret(): string
    {
        return 'whsec_••••••••'.substr($this->secret(), -4);
    }
}
