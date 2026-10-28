<?php

namespace App\Services\Crm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Talks to a CRM over real HTTP (point ROUTEWISE_CRM_URL at it). */
class HttpTransport implements CrmTransport
{
    public function __construct(private readonly string $baseUrl, private readonly string $token) {}

    public function send(string $method, string $path, array $body = [], array $headers = []): CrmResponse
    {
        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withToken($this->token)
                ->withHeaders($headers)
                ->acceptJson()
                ->timeout((int) config('routewise.crm.timeout_seconds'))
                ->send(strtoupper($method), $path, $method === 'GET' ? ['query' => $body] : ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new CrmTransientException('Could not reach the CRM (connection failed or timed out).');
        }

        return new CrmResponse(
            $response->status(),
            $response->json() ?? [],
            collect($response->headers())->mapWithKeys(fn ($v, $k) => [strtolower($k) => (string) ($v[0] ?? '')])->all(),
        );
    }
}
