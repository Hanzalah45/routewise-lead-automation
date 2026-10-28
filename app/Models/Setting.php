<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    protected $guarded = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::remember("setting:{$key}", 300, fn () => static::find($key)?->value ?? $default);
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:{$key}");
    }

    public static function flush(): void
    {
        foreach (static::pluck('key') as $key) {
            Cache::forget("setting:{$key}");
        }
    }
}
