<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Owner extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    /** @return list<string> */
    public function regionList(): array
    {
        return $this->listOf($this->regions, true);
    }

    /** @return list<string> */
    public function segmentList(): array
    {
        return $this->listOf($this->segments, false);
    }

    private function listOf(?string $value, bool $upper): array
    {
        $items = array_filter(array_map('trim', explode(',', (string) $value)));

        return array_values(array_map(fn ($i) => $upper ? strtoupper($i) : strtolower($i), $items));
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    }
}
