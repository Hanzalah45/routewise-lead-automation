# n8n workflows

Two workflows are in `n8n/`:

| File | Trigger | Use |
|---|---|---|
| `routewise-form-to-routewise.json` | Webhook `/webhook/lead-form` | Any website form that can POST JSON (`name`, `email`, `phone`, `company`, `job_title`, `country`, `message`, `consent`, optional `submission_id`). |
| `routewise-typeform-to-routewise.json` | Webhook `/webhook/typeform-lead` | Typeform "Webhooks". Answers are mapped by field `ref` (name, email, phone, company, job_title, country, message, consent). Edit the refs to match your form. |

Both do the same four things: **map** the input to a Routewise lead and prepare the string to sign, **sign** it (Crypto node, HMAC-SHA256, hex), **POST** it with the `X-Routewise-Signature` header (3 tries, 2 s apart), and **answer** the caller (200 on success, 502 on failure so the sender redeliver). Each payload has an `external_id` (the form's submission id, or Typeform's response token), so a retry or redelivery cannot create a second lead.

## Import

1. n8n: **Workflows > Import from file**, choose a JSON file.
2. Set environment variables on the n8n instance: `ROUTEWISE_URL` (for example `https://your-app.example`), `ROUTEWISE_SECRET` (the source's signing secret from *Sources & webhooks*), optional `ROUTEWISE_SOURCE` (default `website` or `typeform`).
3. n8n 2.x blocks `$env` inside nodes by default. Either set `N8N_BLOCK_ENV_ACCESS_IN_NODE=false`, or replace the three `$env.*` expressions with n8n Variables or a credential.
4. Activate the workflow and point your form or Typeform at its **production** webhook URL.

## What was and was not verified

Verified (run `node tools/verify-n8n-flow.mjs <baseUrl>` against a seeded app): both files are valid JSON with unique node names and ids, every connection points to a real node, the signing node is HMAC-SHA256 with hex output, the **Code nodes' own JavaScript** produces a payload and signed string that Routewise accepts (HTTP 201), a repeat is a replay (HTTP 200, no second lead), and a wrong signature is refused (HTTP 401).

**Run inside real n8n (2026-10-29):** n8n 2.35.7 installed from npm on the build machine, both files imported with `n8n import:workflow` and published, `N8N_BLOCK_ENV_ACCESS_IN_NODE=false`. A POST to `/webhook/lead-form` created a lead in Routewise (scored hot, synced to the mock CRM); the identical submission sent again returned the same lead id and created no second lead. A Typeform-style `form_response` POST to `/webhook/typeform-lead` created a warm lead. Both workflows needed no edits after import. Note: n8n takes about two minutes after start to activate workflows on a cold start; calls before that return 404.

**Still not verified in n8n:** the failure branch (Routewise down, 502 back to the caller) and the 3-time retry were not exercised in n8n; the retry/refusal behaviour is covered by `verify-n8n-flow.mjs` and the app tests only. Tested on one n8n version on Windows, not on n8n Cloud.
