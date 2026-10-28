<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MockCrm\MockCrmApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** HTTP face of the bundled Mock CRM, for curl, n8n or an HttpTransport pointed at this app. */
class MockCrmController extends Controller
{
    public function __invoke(Request $request, string $path, MockCrmApi $api): JsonResponse
    {
        $headers = collect($request->headers->all())->mapWithKeys(fn ($v, $k) => [strtolower($k) => (string) ($v[0] ?? '')])->all();
        $body = $request->isMethod('GET') ? $request->query() : (array) $request->json()->all();

        $response = $api->handle($request->method(), '/'.$path, $body, $headers);

        return response()->json($response->body, $response->status)->withHeaders(array_filter($response->headers));
    }
}
