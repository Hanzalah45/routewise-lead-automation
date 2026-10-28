<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Records when something last checked for due CRM retries (the scheduler, the worker loop, or the opportunistic
 * sweep that runs after web requests), so the UI can say honestly whether retries are being processed.
 */
class SchedulerHealth
{
    public const KEY = 'scheduler.last_run';
    public const STALE_AFTER_SECONDS = 180;

    public static function beat(): void
    {
        Setting::put(self::KEY, (string) now()->timestamp);
    }

    public static function lastRun(): ?int
    {
        $value = Setting::get(self::KEY);

        return $value ? (int) $value : null;
    }

    public static function ageSeconds(): ?int
    {
        $last = self::lastRun();

        return $last === null ? null : max(0, now()->timestamp - $last);
    }

    public static function isStale(): bool
    {
        $age = self::ageSeconds();

        return $age === null || $age > self::STALE_AFTER_SECONDS;
    }
}
