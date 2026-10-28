<?php

namespace App\Services\Webhooks;

/**
 * Stripe-style webhook signatures: header "t=<unix seconds>,v1=<hex>" where v1 = HMAC-SHA256(secret, "<t>.<raw body>").
 * The timestamp is part of the signed string, so a captured request cannot be replayed after the tolerance window.
 */
class SignatureVerifier
{
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const MALFORMED = 'malformed';
    public const EXPIRED = 'expired';
    public const MISMATCH = 'mismatch';

    public function sign(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, $this->digest($secret, $timestamp, $body));
    }

    /** @return string one of the class constants */
    public function verify(string $secret, string $body, ?string $header, ?int $now = null, ?int $tolerance = null): string
    {
        if ($header === null || trim($header) === '') {
            return self::MISSING;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't' && ctype_digit((string) $value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== null && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            return self::MALFORMED;
        }

        $tolerance ??= (int) config('routewise.webhook_tolerance_seconds');
        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return self::EXPIRED;
        }

        $expected = $this->digest($secret, $timestamp, $body);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return self::OK;
            }
        }

        return self::MISMATCH;
    }

    private function digest(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
