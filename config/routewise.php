<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public demo
    |--------------------------------------------------------------------------
    | Hosted showcase switch. On: one-click reviewer-level login (password login off),
    | a daily reset is allowed, and the banner reminds visitors the data is synthetic.
    */
    'public_demo' => (bool) env('ROUTEWISE_PUBLIC_DEMO', false),
    'demo_login_email' => 'agent@routewise.test',

    /*
    |--------------------------------------------------------------------------
    | Inbound webhooks
    |--------------------------------------------------------------------------
    | Every request is signed: X-Routewise-Signature: t=<unix seconds>,v1=<hex hmac-sha256(secret, "<t>.<raw body>")>.
    | Requests older (or newer) than the tolerance are rejected to stop replays.
    */
    'webhook_tolerance_seconds' => (int) env('ROUTEWISE_WEBHOOK_TOLERANCE', 300),
    'webhook_rate_limit_per_minute' => 120,
    'max_body_bytes' => 65536,

    /*
    |--------------------------------------------------------------------------
    | CRM sync
    |--------------------------------------------------------------------------
    | The bundled Mock CRM lives in this app under /mock-crm. Point base_url and token at a real CRM adapter
    | to reuse the pipeline. First attempt is inline; retries run from `leads:sync-pending` with backoff.
    */
    'crm' => [
        // mock (bundled, default) | hubspot | pipedrive. Real adapters are untested against live accounts.
        'driver' => env('ROUTEWISE_CRM_DRIVER', 'mock'),
        'hubspot' => ['token' => env('ROUTEWISE_HUBSPOT_TOKEN'), 'pipeline' => env('ROUTEWISE_HUBSPOT_PIPELINE', 'default'), 'deal_stage' => env('ROUTEWISE_HUBSPOT_DEAL_STAGE', 'appointmentscheduled')],
        'pipedrive' => ['token' => env('ROUTEWISE_PIPEDRIVE_TOKEN'), 'base_url' => env('ROUTEWISE_PIPEDRIVE_URL', 'https://api.pipedrive.com/v1')],
        'base_url' => env('ROUTEWISE_CRM_URL'),   // mock driver only: empty = call the bundled Mock CRM in-process
        'token' => env('ROUTEWISE_CRM_TOKEN', 'mock-crm-demo-token'),
        'timeout_seconds' => 8,
        'max_attempts' => 5,
        'backoff_seconds' => [30, 120, 600, 1800],   // wait before attempt 2, 3, 4, 5
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring
    |--------------------------------------------------------------------------
    | Point values live in the scoring_rules table and are editable in the UI; the lists below are the vocabulary.
    */
    'tiers' => ['hot' => 70, 'warm' => 35],
    'default_country' => 'US',
    'priority_countries' => ['US', 'GB', 'CA', 'AU', 'DE', 'NL', 'SE', 'AE'],
    'seniority_keywords' => ['ceo', 'cto', 'cfo', 'coo', 'chief', 'founder', 'owner', 'president', 'vp', 'vice president', 'head of', 'director'],
    'intent_keywords' => ['pricing', 'price', 'quote', 'demo', 'trial', 'buy', 'purchase', 'proposal', 'contract', 'urgent', 'asap', 'budget'],
    'spam_keywords' => ['viagra', 'casino', 'crypto giveaway', 'seo services', 'backlinks', 'click here', 'loan offer', 'make money fast'],
    'free_email_domains' => ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com', 'yandex.com'],
    'disposable_email_domains' => ['mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'trashmail.com', 'yopmail.com', 'sharklasers.com'],
];
