<?php

use App\Http\Controllers\Api\MockCrmController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Inbound lead webhook: POST /api/v1/webhooks/{source}, signed with that source's secret.
Route::post('/v1/webhooks/{source}', WebhookController::class)->middleware('throttle:webhooks')->name('webhooks.receive');

// Bundled Mock CRM (Bearer token). Stand-in for HubSpot / Pipedrive / Salesforce.
Route::any('/mock-crm/v1/{path}', MockCrmController::class)->where('path', '.*')->name('mock-crm');
