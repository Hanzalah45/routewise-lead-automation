<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.scss'])
</head>
<body>
<main class="d-flex align-items-center justify-content-center text-center" style="min-height:100vh;padding:2rem">
    <div style="max-width:420px">
        <div class="mb-4 d-inline-flex align-items-center gap-2">@include('partials.mark', ['size' => 34, 'ink' => '#0E1726'])<span class="fw-semibold fs-4">routewise</span></div>
        <div class="mono text-muted-2 mb-1">@yield('code')</div>
        <h1 class="fs-3 mb-2">@yield('title')</h1>
        <p class="text-muted-2">@yield('message')</p>
        <a href="{{ url('/') }}" class="btn btn-primary btn-sm">Back to the dashboard</a>
    </div>
</main>
</body>
</html>
