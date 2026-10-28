<?php

namespace App\Services\Crm;

use App\Services\MockCrm\MockCrmApi;

/**
 * Calls the bundled Mock CRM without a network hop. Same code path as the HTTP endpoints (auth, failure injection,
 * logging), but it cannot deadlock a single-threaded dev server by requesting itself.
 */
class InProcessMockTransport implements CrmTransport
{
    public function __construct(private readonly MockCrmApi $api, private readonly string $token) {}

    public function send(string $method, string $path, array $body = [], array $headers = []): CrmResponse
    {
        return $this->api->handle($method, $path, $body, ['authorization' => 'Bearer '.$this->token] + array_change_key_case($headers));
    }
}
