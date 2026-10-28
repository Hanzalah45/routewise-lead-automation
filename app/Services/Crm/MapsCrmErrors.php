<?php

namespace App\Services\Crm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/** Shared HTTP handling for the real-CRM adapters: timeouts and status codes become transient or permanent errors. */
trait MapsCrmErrors
{
    abstract protected function vendor(): string;

    protected function http(): PendingRequest
    {
        return \Illuminate\Support\Facades\Http::acceptJson()->asJson()->timeout((int) config('routewise.crm.timeout_seconds'));
    }

    /** Sends a request and returns the response, or throws for anything that is not a 2xx. */
    protected function call(string $method, string $url, string $what, array $options = []): Response
    {
        try {
            $response = $this->http()->send($method, $url, $options);
        } catch (ConnectionException) {
            throw new CrmTransientException("Could not reach {$this->vendor()} (connection failed or timed out).");
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $retryAfter = ctype_digit((string) $response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;
        $detail = (string) ($response->json('message') ?? $response->json('error') ?? $response->json('error_info') ?? 'no detail');

        throw match (true) {
            $status === 429 => new CrmTransientException("{$this->vendor()} rate limit reached while saving the {$what}.", 429, $retryAfter),
            $status >= 500 => new CrmTransientException("{$this->vendor()} server error ({$status}) while saving the {$what}.", $status, $retryAfter),
            $status === 401 || $status === 403 => new CrmPermanentException("{$this->vendor()} rejected the credentials ({$status}). Check the API token and its permissions.", $status),
            default => new CrmPermanentException("{$this->vendor()} rejected the {$what} ({$status}: ".mb_substr($detail, 0, 160).').', $status),
        };
    }
}
