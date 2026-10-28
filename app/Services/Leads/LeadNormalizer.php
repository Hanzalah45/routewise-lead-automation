<?php

namespace App\Services\Leads;

use Illuminate\Support\Str;

/**
 * Turns whatever a form, ad platform or automation sends into one clean shape.
 * It never throws on odd input: anything it cannot make sense of becomes null.
 */
class LeadNormalizer
{
    /** International calling codes for the countries used in routing. Not a full numbering plan. */
    private const CALLING_CODES = [
        'US' => '1', 'CA' => '1', 'GB' => '44', 'AU' => '61', 'DE' => '49', 'FR' => '33', 'NL' => '31', 'SE' => '46',
        'ES' => '34', 'IT' => '39', 'IE' => '353', 'AE' => '971', 'SA' => '966', 'PK' => '92', 'IN' => '91', 'SG' => '65',
        'NZ' => '64', 'ZA' => '27', 'BR' => '55', 'MX' => '52', 'CH' => '41', 'NO' => '47', 'DK' => '45', 'PL' => '48',
    ];

    private const COUNTRY_NAMES = [
        'united states' => 'US', 'usa' => 'US', 'united kingdom' => 'GB', 'uk' => 'GB', 'great britain' => 'GB',
        'canada' => 'CA', 'australia' => 'AU', 'germany' => 'DE', 'france' => 'FR', 'netherlands' => 'NL', 'sweden' => 'SE',
        'spain' => 'ES', 'italy' => 'IT', 'ireland' => 'IE', 'united arab emirates' => 'AE', 'uae' => 'AE',
        'saudi arabia' => 'SA', 'pakistan' => 'PK', 'india' => 'IN', 'singapore' => 'SG', 'new zealand' => 'NZ',
    ];

    /**
     * @param  array<string,mixed>  $in
     * @return array<string,mixed>
     */
    public function normalise(array $in): array
    {
        [$first, $last] = $this->names($in);
        $email = $this->email($in['email'] ?? null);
        $country = $this->country($in['country'] ?? null);
        $domain = $email ? Str::after($email, '@') : null;

        $isFree = $domain !== null && in_array($domain, config('routewise.free_email_domains'), true);
        $isDisposable = $domain !== null && in_array($domain, config('routewise.disposable_email_domains'), true);

        return [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'email_normalized' => $email ? $this->canonicalEmail($email) : null,
            'phone' => $this->text($in['phone'] ?? null, 40),
            'phone_e164' => $this->phoneE164($in['phone'] ?? null, $country),
            'company' => $this->text($in['company'] ?? null, 160),
            'job_title' => $this->text($in['job_title'] ?? ($in['title'] ?? null), 160),
            'country' => $country,
            'message' => $this->text($in['message'] ?? null, 5000),
            'consent' => $this->bool($in['consent'] ?? null),
            'company_domain' => ($domain && ! $isFree && ! $isDisposable) ? $domain : null,
            'is_free_email' => $isFree,
            'is_disposable_email' => $isDisposable,
            'honeypot' => filled($in['website'] ?? null) || filled($in['_gotcha'] ?? null),
        ];
    }

    /** Lower-cases, strips +tags and (for Gmail) dots so one person is one key. */
    public function canonicalEmail(string $email): string
    {
        [$local, $domain] = explode('@', strtolower($email), 2);
        $local = explode('+', $local, 2)[0];
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    public function phoneE164(mixed $raw, ?string $country): ?string
    {
        if (! is_scalar($raw) || trim((string) $raw) === '') {
            return null;
        }
        $raw = trim((string) $raw);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            $number = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $number = substr($digits, 2);
        } else {
            $code = self::CALLING_CODES[$country ?? config('routewise.default_country')] ?? null;
            if ($code === null) {
                return null;                                   // national number with an unknown country: do not guess
            }
            $number = (str_starts_with($digits, $code) && strlen($digits) > 10) ? $digits : $code.ltrim($digits, '0');
        }

        return (strlen($number) >= 8 && strlen($number) <= 15) ? '+'.$number : null;
    }

    private function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    /** @return array{0:?string,1:?string} */
    private function names(array $in): array
    {
        $first = $this->text($in['first_name'] ?? null, 80);
        $last = $this->text($in['last_name'] ?? null, 80);

        if ($first === null && $last === null && ($full = $this->text($in['name'] ?? ($in['full_name'] ?? null), 160))) {
            $parts = preg_split('/\s+/', $full, 2);
            $first = $parts[0] ?? null;
            $last = $parts[1] ?? null;
        }

        return [$first ? $this->titleCase($first) : null, $last ? $this->titleCase($last) : null];
    }

    private function titleCase(string $name): string
    {
        // Only fix shouting or all-lowercase names; leave "McDonald" and "van der Berg" alone.
        return ($name === mb_strtoupper($name) || $name === mb_strtolower($name)) ? Str::title($name) : $name;
    }

    private function country(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (strlen($value) === 2 && ctype_alpha($value)) {
            return strtoupper($value);
        }

        return self::COUNTRY_NAMES[strtolower($value)] ?? null;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function bool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
