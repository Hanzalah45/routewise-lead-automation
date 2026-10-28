<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DemoReset extends Command
{
    protected $signature = 'routewise:demo-reset {--force : Run even when ROUTEWISE_PUBLIC_DEMO is off (wipes the database)}';

    protected $description = 'Wipe and re-seed the public demo workspace.';

    public function handle(): int
    {
        if (! config('routewise.public_demo') && ! $this->option('force')) {
            $this->error('Refusing to reset: ROUTEWISE_PUBLIC_DEMO is off. Pass --force to wipe this database.');

            return self::FAILURE;
        }

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->info('Demo workspace reset.');

        return self::SUCCESS;
    }
}
