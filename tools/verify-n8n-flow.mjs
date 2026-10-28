// Checks the n8n workflow files without n8n:
//   1. structure: valid JSON, unique node names, every connection points at a real node
//   2. behaviour: runs each workflow's "Build payload" Code node and applies the same HMAC-SHA256 the Crypto node is
//      configured for, then POSTs to a running Routewise and expects 201.
// This proves the payload mapping and the signature scheme match the app. It does NOT run n8n itself.
// Usage: node tools/verify-n8n-flow.mjs <baseUrl>
import { createHmac } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const base = process.argv[2] ?? 'http://127.0.0.1:8010';
const dir = path.resolve(import.meta.dirname, '..', 'n8n');
let failed = 0;
const ok = (cond, msg) => { console.log(`${cond ? 'PASS' : 'FAIL'}  ${msg}`); if (!cond) failed++; };

const cases = [
    { file: 'routewise-form-to-routewise.json', source: 'website', secret: 'whsec_demo_website_000000000000000000000000', mapNode: 'Build payload',
      input: { body: { name: 'Nadia Voss', email: `nadia.voss+${Date.now()}@helix-labs.example`, phone: '+44 20 7946 0123', company: 'Helix Labs', job_title: 'Head of Growth', country: 'GB', message: 'Pricing and a demo please, we are choosing a tool this month.', consent: 'on', submission_id: `form-${Date.now()}` } } },
    { file: 'routewise-typeform-to-routewise.json', source: 'typeform', secret: 'whsec_demo_typeform_000000000000000000000000', mapNode: 'Map Typeform answers',
      input: { body: { form_response: { token: `tf-${Date.now()}`, answers: [
          { field: { ref: 'name' }, text: 'Owen Marsh' }, { field: { ref: 'email' }, email: `owen.marsh+${Date.now()}@brightline-energy.example` },
          { field: { ref: 'company' }, text: 'Brightline Energy' }, { field: { ref: 'job_title' }, text: 'Director of Sales' },
          { field: { ref: 'country' }, text: 'Australia' }, { field: { ref: 'message' }, text: 'Need a demo and pricing for our sales team.' },
          { field: { ref: 'consent' }, boolean: true } ] } } } },
];

for (const c of cases) {
    const wf = JSON.parse(fs.readFileSync(path.join(dir, c.file), 'utf8'));
    const names = wf.nodes.map((n) => n.name);
    ok(new Set(names).size === names.length, `${c.file}: node names are unique`);
    ok(new Set(wf.nodes.map((n) => n.id)).size === wf.nodes.length, `${c.file}: node ids are unique`);
    const targets = Object.entries(wf.connections).flatMap(([from, v]) => [from, ...v.main.flat().map((x) => x.node)]);
    ok(targets.every((t) => names.includes(t)), `${c.file}: every connection points at an existing node`);
    ok(wf.nodes.some((n) => n.type === 'n8n-nodes-base.webhook') && wf.nodes.some((n) => n.type === 'n8n-nodes-base.httpRequest'), `${c.file}: has a webhook trigger and an HTTP request node`);

    const crypto = wf.nodes.find((n) => n.type === 'n8n-nodes-base.crypto').parameters;
    ok(crypto.action === 'hmac' && crypto.type === 'SHA256' && crypto.encoding === 'hex', `${c.file}: signing node is HMAC-SHA256 with hex output`);

    // Run the mapping Code node the way n8n would.
    const code = wf.nodes.find((n) => n.name === c.mapNode).parameters.jsCode;
    const fn = vm.runInNewContext(`(function () { ${code} })`, { $input: { first: () => ({ json: c.input }) }, $execution: { id: 'verify' }, Math, JSON, Object, String, Date });
    const out = fn()[0].json;
    ok(typeof out.body === 'string' && out.toSign === `${out.t}.${out.body}`, `${c.file}: the signed string is "<t>.<exact body>"`);

    const v1 = createHmac('sha256', c.secret).update(out.toSign).digest('hex');
    const res = await fetch(`${base}/api/v1/webhooks/${c.source}`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Routewise-Signature': `t=${out.t},v1=${v1}` }, body: out.body,
    });
    const json = await res.json().catch(() => ({}));
    ok(res.status === 201 && json.id, `${c.file}: Routewise accepted the lead (HTTP ${res.status}, status=${json.status}, tier=${json.tier})`);

    const again = await fetch(`${base}/api/v1/webhooks/${c.source}`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Routewise-Signature': `t=${out.t},v1=${v1}` }, body: out.body,
    });
    ok(again.status === 200 && (await again.json()).replay === true, `${c.file}: a retry of the same submission is a replay, not a second lead`);

    const bad = await fetch(`${base}/api/v1/webhooks/${c.source}`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Routewise-Signature': `t=${out.t},v1=${'0'.repeat(64)}` }, body: out.body,
    });
    ok(bad.status === 401, `${c.file}: a wrong signature is refused (HTTP ${bad.status})`);
}

console.log(failed ? `\n${failed} check(s) failed` : '\nAll checks passed');
process.exit(failed ? 1 : 0);
