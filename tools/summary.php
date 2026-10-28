<?php
// Prints a one-line summary per lead state, for a quick sanity check after seeding.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (App\Models\Lead::selectRaw('status, tier, count(*) c')->groupBy('status', 'tier')->orderBy('status')->get() as $r) {
    echo str_pad($r->status, 10), str_pad($r->tier, 6), $r->c, PHP_EOL;
}
echo 'owners with leads: ', App\Models\Lead::whereNotNull('owner_id')->distinct('owner_id')->count('owner_id'), PHP_EOL;
echo 'unassigned routed: ', App\Models\Lead::where('status', 'routed')->orWhere(fn ($q) => $q->whereNull('owner_id')->whereIn('status', ['synced', 'retrying', 'dead']))->count(), PHP_EOL;
echo 'crm contacts: ', App\Models\MockCrmContact::count(), ' deals: ', App\Models\MockCrmDeal::count(), PHP_EOL;
echo 'outbox: ', App\Models\OutboxMessage::count(), PHP_EOL;
foreach (App\Models\Lead::whereIn('status', ['retrying', 'dead'])->get() as $l) {
    echo "{$l->status}: {$l->fullName()} attempts={$l->sync_attempts} err=".mb_substr((string) $l->last_error, 0, 70), PHP_EOL;
}
