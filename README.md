# Routewise: lead-to-CRM automation

![Routewise](public/brand/routewise-logo.png)

Routewise receives leads from forms, ad platforms and automation tools through **signed webhooks**, cleans and de-duplicates them, **scores** them with explainable rules, **routes** each one to the right owner, and **saves it to a CRM** with retries when the CRM is down. Every step is logged and visible in an operations dashboard.

A CoreHives portfolio project. All people, companies and leads in the demo data are invented. The CRM is a bundled mock and notifications are only recorded: **nothing leaves the application.**

## What it does

| Step | Behaviour |
|---|---|
| **Receive** | `POST /api/v1/webhooks/{source}`. Each source has its own endpoint and secret. HMAC-SHA256 signature over `"<timestamp>.<raw body>"`, 5 minute replay window, 64 KB limit, rate limit. Every request, including refused ones, is logged (payload content is not). |
| **Clean** | Names split and tidied, email validated, plus-tags and Gmail dots collapsed for matching, phone to E.164 only when it can be trusted, country from code or name, consent, honeypot field. |
| **De-duplicate** | Same email or same phone is marked duplicate and linked to the original. Same `external_id` / `Idempotency-Key` returns the original lead (safe retries). |
| **Score** | 11 editable rules (points and on/off in the UI). Every point is listed on the lead. Hot from 70, warm from 35. Spam signals override the tier. Rules, not machine learning. |
| **Route** | Active owner with capacity left, covering the lead's country, handling the segment (enterprise or smb), lightest load today. Falls back step by step and says why. |
| **Sync** | Contact upsert then deal, each with an idempotency key. Progress is saved between the two calls. Transient failures (429, 5xx, timeout) retry after 30 s, 2 min, 10 min, 30 min (honouring `Retry-After`); rejected data or bad credentials go straight to *Needs attention*; so does a lead that used all 5 attempts. A person can press **Retry CRM sync**. |
| **Notify** | Slack and email messages are written to an outbox, not sent. |

Other screens: dashboard (14-day flow, funnel, sources, CRM health), lead detail (score breakdown, timeline, CRM attempts, original payload, reassign, re-score, mark spam), owners, scoring rules with a live **"try a lead"** simulator, sources & webhooks (secret shown once, rotate, pause, signing snippets, **Send test lead**), deliveries log, outbox, and a **Mock CRM** page with a failure simulator (answer 429 / 503 / 422 for the next N requests) so retries can be demonstrated on demand.

Roles: **admin** (everything) and **agent** (view, retry, reassign, re-score, mark spam; no owner, rule or secret changes).

## Run it locally (Laragon / Windows)

```bash
composer install
npm install && npm run build
copy .env.example .env        # then: php artisan key:generate
php artisan migrate --seed    # 34 demo leads, 7 owners, 3 sources
php artisan serve             # http://127.0.0.1:8000
php artisan schedule:work     # runs CRM retries every minute (or: php artisan leads:sync-pending --loop)
```

**Retries and the scheduler.** Overdue CRM retries are processed by whichever of these runs first: the Laravel scheduler (`schedule:work` or a cron line `* * * * * php artisan schedule:run`), the worker loop (`leads:sync-pending --loop --every=30`, handy on Windows where there is no cron), or a small sweep that runs after any page visit or incoming lead (at most every 20 seconds, 5 leads at a time). The dashboard's **Retry checker** badge shows when retries were last processed and turns amber after 3 idle minutes. If nothing runs and nobody uses the app, retries wait: that is the one case where a scheduler is required.

Sign in with `admin@routewise.test` or `agent@routewise.test`, password `password` (local environment only).

Sending a lead from the command line (demo source secret from the seeder):

```bash
SECRET='whsec_demo_website_000000000000000000000000'
BODY='{"name":"Ada Lovelace","email":"ada@analytical.example","company":"Analytical Engines","message":"Pricing please","consent":true}'
TS=$(date +%s); SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.* //')
curl -X POST http://127.0.0.1:8000/api/v1/webhooks/website -H "Content-Type: application/json" -H "X-Routewise-Signature: t=$TS,v1=$SIG" -d "$BODY"
```

Production sources get random secrets (`whsec_` + 40 characters) shown once in the UI. The `whsec_demo_*` secrets exist only in seeded demo data.

## n8n

Two importable workflows are in [`n8n/`](n8n): a generic website form and a Typeform response, each mapping fields, signing with HMAC-SHA256, posting to Routewise and retrying 3 times. See [docs/N8N.md](docs/N8N.md). Both were imported and run inside n8n 2.35.7 (lead created, repeat de-duplicated); the failure/retry branch was not exercised there. See the verification note in that file.

## Pointing it at a real CRM

The sync service talks to a `CrmGateway` (two methods: `upsertContact`, `createDeal`). Pick the adapter with `ROUTEWISE_CRM_DRIVER`:

| Driver | Settings | Notes |
|---|---|---|
| `mock` (default) | none | Bundled Mock CRM, called in-process. |
| `hubspot` | `ROUTEWISE_HUBSPOT_TOKEN` (private app token with contacts and deals write scopes), optional `ROUTEWISE_HUBSPOT_PIPELINE`, `ROUTEWISE_HUBSPOT_DEAL_STAGE` | Contacts via batch upsert on email, deal associated with the contact. Owner id = numeric HubSpot owner id. |
| `pipedrive` | `ROUTEWISE_PIPEDRIVE_TOKEN`, optional `ROUTEWISE_PIPEDRIVE_URL` | Person and organisation looked up before create, deal linked to both. Owner id = numeric Pipedrive user id. |

**Both real adapters were written from the vendors' public API documentation and are tested only against faked HTTP responses (request shapes, id handling, error mapping, retry through the whole pipeline). They have never been run against a live HubSpot or Pipedrive account**, and no Salesforce adapter exists. Expect to adjust field names (for example custom properties for score and tier) on first contact with a real account. Neither vendor offers an idempotency key for creating deals, so a crash between "deal created" and "deal id saved" could duplicate one; the Mock CRM adapter has idempotency keys.

## Calibrating the scoring rules on real outcomes

The shipped rules are sensible examples, **not tuned on real conversion data** (none exists for this demo). When you have real leads and outcomes, export `email,outcome` (won / lost) from the CRM and run:

```bash
php artisan routewise:calibrate outcomes.csv
```

It matches leads by canonical email and prints win rate by tier and, for every rule, the win rate with and without it plus a suggested point value (10 x ln of the odds ratio, smoothed, clamped to -30..30). Rules with fewer than 30 leads on either side are marked "too thin". It is advisory: it never edits a rule. Treat the output as a prompt to review, not a verdict (correlation is not cause, and a few dozen outcomes are noisy).

## Tests

```bash
php artisan test
```

123 tests: signature scheme, normaliser, scoring, routing (region, segment, capacity, load balancing), the full webhook path (bad/old/tampered signatures, validation, idempotency, duplicates, spam, consent, oversized bodies), CRM sync (backoff schedule, Retry-After, dead letter, partial progress, no duplicate contacts, bad credentials), the Mock CRM API, roles and every admin action, the HubSpot and Pipedrive adapters (faked HTTP), the retry sweep and heartbeat, the scoring calibration command, and the public demo mode. `docs/e2e-results.txt` holds a 43-check real-browser run (Chrome via Playwright): sending signed leads, failure simulator, retry, dead letter, role limits, secret rotation, mobile layout.

## Limitations (honest list)

- **No real CRM has been connected.** The Mock CRM is exercised end to end. The HubSpot and Pipedrive adapters are tested against faked HTTP responses only and have never run against a live account. No Salesforce adapter.
- **Scoring rules are examples.** There is no real conversion data in this project. `routewise:calibrate` exists so the rules can be checked against real won/lost outcomes once you have them.
- **Retries** run from the scheduler, the worker loop, or after page visits and incoming leads. If none of those happen (idle app, nothing running), nothing is retried until one does.
- **n8n:** see docs/N8N.md for exactly what was and was not run inside n8n.
- **Docker/Render:** the image itself has never been built (no Docker on the build machine). The container's start-up steps (key generation, demo reset, scheduler, server on `$PORT`) were run locally with a temporary database and the public-demo flow was exercised over HTTP. See docs/DEPLOY_DEMO.md.
- Phone normalisation covers 25 countries' calling codes; other national numbers are left un-normalised rather than guessed.
- Duplicate detection is exact on canonical email or E.164 phone. No fuzzy name or company matching.
- Slack and email are simulated (outbox only).
- Single workspace, no password reset, no email verification.
## Design

Deep ink sidebar, cool paper content area, one saffron accent kept for hot leads and the active marker. Geist and Geist Mono (bundled, no CDN). Logo made in Canva. Layout works down to phone width.

## License

All rights reserved. The code is available to view for evaluation; see [LICENSE](LICENSE).

