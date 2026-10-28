import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

/* ------------------------------------------------------------- toasts */
function toast(message, type = 'info') {
    const stack = $('#toastStack');
    if (!stack) return;
    const el = document.createElement('div');
    el.className = `toast-card ${type === 'error' ? 'error' : ''}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML = `<i class="bi ${type === 'error' ? 'bi-exclamation-octagon' : 'bi-check-circle'}"></i><span></span>`;
    el.querySelector('span').textContent = message;
    stack.appendChild(el);
    setTimeout(() => el.remove(), type === 'error' ? 9000 : 5500);
}

function initFlash() {
    const flash = window.__flash || {};
    if (flash.status) toast(flash.status);
    if (flash.error) toast(flash.error, 'error');
}

/* ------------------------------------------------------------- confirm modal */
function initConfirm() {
    const modalEl = $('#confirmModal');
    if (!modalEl) return;
    const modal = new bootstrap.Modal(modalEl);
    let pending = null;
    $$('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (form.dataset.confirmed) return;
            e.preventDefault();
            pending = form;
            $('#confirmTitle').textContent = form.dataset.confirmTitle || 'Are you sure?';
            $('#confirmBody').textContent = form.dataset.confirm;
            $('#confirmOk').textContent = form.dataset.confirmOk || 'Confirm';
            modal.show();
        });
    });
    $('#confirmOk').addEventListener('click', () => {
        if (!pending) return;
        pending.dataset.confirmed = '1';
        modal.hide();
        pending.requestSubmit();
    });
}

/* ------------------------------------------------------------- copy to clipboard */
function initCopy() {
    $$('[data-copy]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const target = $(btn.dataset.copy);
            if (!target) return;
            try {
                await navigator.clipboard.writeText(target.innerText.trim());
                const old = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2"></i> Copied';
                setTimeout(() => (btn.innerHTML = old), 1600);
            } catch {
                toast('Copy is not available in this browser.', 'error');
            }
        });
    });
}

/* ------------------------------------------------------------- mobile sidebar */
function initSidebar() {
    const sidebar = $('#sidebar');
    const open = $('#sidebarOpen');
    if (!sidebar || !open) return;
    let scrim = null;
    const close = () => { sidebar.classList.remove('open'); scrim?.remove(); scrim = null; };
    open.addEventListener('click', () => {
        sidebar.classList.add('open');
        scrim = document.createElement('div');
        scrim.className = 'sidebar-scrim';
        scrim.addEventListener('click', close);
        document.body.appendChild(scrim);
    });
    $$('a.side-link', sidebar).forEach((a) => a.addEventListener('click', close));
}

/* ------------------------------------------------------------- row links & filters */
function initRows() {
    $$('tr[data-href]').forEach((row) => {
        row.addEventListener('click', (e) => {
            if (e.target.closest('a, button, input, select, form')) return;
            window.location = row.dataset.href;
        });
    });
    $$('form[data-autosubmit] select').forEach((s) => s.addEventListener('change', () => s.form.submit()));
}

/* ------------------------------------------------------------- scoring simulator */
function initSimulator() {
    const form = $('#simulator');
    if (!form) return;
    const out = $('#simResult');
    const run = async () => {
        const body = Object.fromEntries(new FormData(form).entries());
        out.style.opacity = '.55';
        try {
            const res = await fetch(form.action, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body) });
            if (!res.ok) throw new Error('bad response');
            const d = await res.json();
            const lines = d.breakdown.length
                ? d.breakdown.map((b) => `<div class="score-line"><div><div class="fw-medium">${esc(b.label)}</div><div class="fs-xs text-muted-2">${esc(b.detail)}</div></div><div class="pts ${b.points >= 0 ? 'pos' : 'neg'}">${b.points > 0 ? '+' : ''}${b.points}</div></div>`).join('')
                : '<div class="text-muted-2 py-2">No rule fired.</div>';
            out.innerHTML = `<div class="score-total mb-2"><span class="big">${d.score}</span><span class="tier ${d.tier}">${d.tier}</span></div>
                <div class="fs-sm text-muted-2 mb-3">${d.spam ? 'Spam is never routed.' : `Segment <b>${esc(d.segment)}</b>. ${esc(d.route_reason)}`}</div>${lines}`;
        } catch {
            out.innerHTML = '<div class="text-danger">Could not score this lead.</div>';
        }
        out.style.opacity = '1';
    };
    let t;
    form.addEventListener('input', () => { clearTimeout(t); t = setTimeout(run, 350); });
    form.addEventListener('submit', (e) => { e.preventDefault(); run(); });
    run();
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/* ------------------------------------------------------------- busy buttons */
function initBusy() {
    $$('form[data-busy]').forEach((form) => {
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type=submit]');
            if (btn && !form.dataset.confirm) { btn.disabled = true; btn.dataset.label = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (form.dataset.busy || 'Working'); }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initFlash();
    initConfirm();
    initCopy();
    initSidebar();
    initRows();
    initSimulator();
    initBusy();
});
