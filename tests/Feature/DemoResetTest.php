<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MockCrmContact;
use App\Models\User;
use Tests\TestCase;

/** Not using RefreshDatabase: migrate:fresh cannot run inside its wrapping transaction. */
class DemoResetTest extends TestCase
{
    public function test_reset_rebuilds_and_seeds_a_realistic_workspace(): void
    {
        config(['routewise.public_demo' => true]);

        $this->artisan('routewise:demo-reset')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'agent@routewise.test', 'role' => User::ROLE_AGENT]);
        $this->assertSame(34, Lead::count());
        $this->assertSame(['dead', 'duplicate', 'held', 'retrying', 'spam', 'synced'], Lead::distinct()->orderBy('status')->pluck('status')->all());
        $this->assertGreaterThan(20, MockCrmContact::count());
    }
}
