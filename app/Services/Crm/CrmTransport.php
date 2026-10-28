<?php

namespace App\Services\Crm;

interface CrmTransport
{
    /**
     * @param  array<string,mixed>  $body
     * @param  array<string,string>  $headers
     *
     * @throws CrmTransientException when the CRM cannot be reached (timeout, DNS, refused)
     */
    public function send(string $method, string $path, array $body = [], array $headers = []): CrmResponse;
}
