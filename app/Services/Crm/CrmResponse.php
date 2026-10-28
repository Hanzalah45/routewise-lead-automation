<?php

namespace App\Services\Crm;

final class CrmResponse
{
    /**
     * @param  array<string,mixed>  $body
     * @param  array<string,string>  $headers  lower-case names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body = [],
        public readonly array $headers = [],
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function retryAfter(): ?int
    {
        $value = $this->headers['retry-after'] ?? null;

        return ($value !== null && ctype_digit((string) $value)) ? (int) $value : null;
    }
}
