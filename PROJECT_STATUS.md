# Project status: Routewise (lead-to-CRM automation)

Last updated: 2026-10-28

## What works (verified)

- Signed webhook intake with per-source secrets, replay window, size and rate limits, validation, idempotency, full delivery log.
- Normalisation, duplicate detection, explainable rule scoring, routing by region / segment / capacity / load.
- CRM sync to the bundled Mock CRM with idempotency keys, backoff (30 s, 2 min, 10 min, 30 min), `Retry-After`, dead-letter state, partial-progress safety, manual retry.
- Operations dashboard, lead detail, owners, scoring rules with live simulator, sources & webhooks, deliveries, outbox, Mock CRM with failure simulator, admin and agent roles, public demo mode.
- Two n8n workflows, imported and run in n8n 2.35.7 (form and Typeform each created a lead; repeat de-duplicated); mapping and signing also verified by script.

## Tests actually run

| Check | Result |
|---|---|
| `php artisan test` | **101 passed** (0 failed) |
| `docs/e2e-results.txt`: real-browser run (Chrome, Playwright) | **43 / 43** |
| `node tools/verify-n8n-flow.mjs` | all structure and behaviour checks pass |
| Console / page errors across all pages | none |
| Mobile (390 px) horizontal overflow | none on leads and dashboard |

## Screenshots

`docs/screenshots/`: login, dashboard, leads, needs-attention, lead detail (synced duplicate and dead-letter), owners, scoring (with simulator), sources, deliveries, outbox, Mock CRM. Captured from the seeded demo data.

## Known limitations

- The CRM is a mock; no real CRM (HubSpot, Pipedrive, Salesforce) is connected.
- Scoring rules are examples, not tuned on real data. Duplicate detection is exact (email / phone only).
- Retries need `php artisan schedule:work` or a cron entry.
- The n8n failure/retry branch was not exercised inside n8n. The Dockerfile / Render config were never built.
- Slack and email are simulated (outbox only).
- No demo video, no password reset, single workspace.

## Commands

```bash
php artisan migrate:fresh --seed   # reset demo data
php artisan serve                  # http://127.0.0.1:8000
php artisan schedule:work          # CRM retries each minute
php artisan leads:sync-pending     # run due retries once
php artisan routewise:demo-reset   # public demo only (or --force)
php artisan test
node tools/verify-n8n-flow.mjs http://127.0.0.1:8000
```

## Portfolio readiness

Ready to show: UI, tests, screenshots, README. Still needed from the owner: record a 60 to 90 second video (send a test lead, make the CRM fail, watch retry, fix, retry now).
