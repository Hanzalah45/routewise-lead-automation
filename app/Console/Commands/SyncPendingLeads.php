<?php

namespace App\Console\Commands;

use App\Services\Crm\CrmSyncService;
use App\Services\SchedulerHealth;
use Illuminate\Console\Command;

class SyncPendingLeads extends Command
{
    protected $signature = 'leads:sync-pending
        {--limit=50 : Maximum leads to process per pass}
        {--loop : Keep running, one pass every --every seconds (an alternative to schedule:work)}
        {--every=30 : Seconds between passes with --loop}';

    protected $description = 'Retry CRM delivery for leads whose backoff time has passed.';

    public function handle(CrmSyncService $sync): int
    {
        do {
            $count = $sync->runDue(null, (int) $this->option('limit'));
            SchedulerHealth::beat();
            $this->info(now()->format('H:i:s')." {$count} lead(s) retried.");

            if ($this->option('loop')) {
                sleep(max(1, (int) $this->option('every')));
            }
        } while ($this->option('loop'));

        return self::SUCCESS;
    }
}
