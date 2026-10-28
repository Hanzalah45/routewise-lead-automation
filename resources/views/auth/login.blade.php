<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body>
<div class="auth-wrap">
    <section class="auth-brand">
        <div class="logo-plate"><img src="{{ asset('brand/routewise-logo.png') }}" alt="Routewise"></div>

        <div>
            <div class="eyebrow mb-3" style="color:#6f7b98">Lead-to-CRM automation</div>
            <h1>Every lead scored, owned and in the CRM.</h1>
            <p class="lede">Signed webhooks in. Normalised, de-duplicated, scored and routed to the right owner. Saved to your CRM with retries when it is down.</p>

            <div class="flow" aria-hidden="true">
                <div class="step"><i class="bi bi-shield-check"></i><div>Verify signature<small>HMAC-SHA256, replay window 5 min</small></div></div>
                <div class="step"><i class="bi bi-funnel"></i><div>Clean, de-duplicate, score<small>Every point explained</small></div></div>
                <div class="step"><i class="bi bi-diagram-3"></i><div>Route to an owner<small>Region, segment, daily capacity</small></div></div>
                <div class="step"><i class="bi bi-arrow-repeat"></i><div>Sync to CRM<small>Backoff, dead-letter, manual retry</small></div></div>
            </div>
        </div>

        <div class="fs-xs" style="color:#6f7b98">A CoreHives portfolio project. Synthetic data only.</div>
    </section>

    <section class="auth-form">
        <div class="box">
            <div class="d-lg-none mb-4 d-flex align-items-center gap-2">@include('partials.mark', ['size' => 30, 'ink' => '#0E1726'])<span class="fw-semibold fs-5">routewise</span></div>

            @if(config('routewise.public_demo'))
                <h2 class="mb-1">Try the demo</h2>
                <p class="text-muted-2 mb-4">Opens an agent session in a sample workspace. No sign-up, nothing to type.</p>
                <form method="post" action="{{ route('demo.login') }}">
                    @csrf
                    <button class="btn btn-accent w-100" type="submit">Enter the demo workspace</button>
                </form>
                <ul class="fs-xs text-muted-2 mt-4 ps-3 mb-0">
                    <li>Synthetic leads only. Please do not enter real personal data.</li>
                    <li>The CRM is a bundled mock and notifications are only recorded.</li>
                    <li>Data resets daily. The first load after a quiet spell can take about a minute.</li>
                </ul>
            @else
                <h2 class="mb-1">Sign in</h2>
                <p class="text-muted-2 mb-4">Use your workspace account to continue.</p>
                <form method="post" action="{{ route('login.attempt') }}" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" autocomplete="username" required autofocus>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="current-password" required>
                    </div>
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                        <label class="form-check-label" for="remember">Keep me signed in</label>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Sign in</button>
                </form>

                @if(app()->environment('local'))
                    <div class="eyebrow mt-5 mb-2">Local demo accounts</div>
                    <div class="demo-accounts">
                        <button type="button" data-fill="admin@routewise.test"><span><strong>Admin</strong> · rules, owners, secrets</span><span class="mono">admin@routewise.test</span></button>
                        <button type="button" data-fill="agent@routewise.test"><span><strong>Agent</strong> · retry, reassign</span><span class="mono">agent@routewise.test</span></button>
                    </div>
                    <p class="fs-xs text-muted-2 mt-2">Seeded for local development only. Password: <span class="mono">password</span></p>
                    <script>document.querySelectorAll('[data-fill]').forEach(b => b.addEventListener('click', () => { document.getElementById('email').value = b.dataset.fill; document.getElementById('password').value = 'password'; document.getElementById('password').focus(); }));</script>
                @endif
            @endif
        </div>
    </section>
</div>
<div class="toast-stack" id="toastStack"></div>
<script>window.__flash = @json(['status' => session('status'), 'error' => $errors->any() ? $errors->first() : null]);</script>
</body>
</html>
