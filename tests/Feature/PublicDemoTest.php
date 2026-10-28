<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDemoTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): User
    {
        return User::factory()->agent()->create(['email' => config('routewise.demo_login_email')]);
    }

    public function test_demo_login_is_not_available_when_the_public_demo_is_off(): void
    {
        $this->agent();

        $this->post('/demo-login')->assertNotFound();
        $this->assertGuest();
    }

    public function test_demo_login_signs_in_the_seeded_agent_never_an_admin(): void
    {
        config(['routewise.public_demo' => true]);
        $user = $this->agent();

        $this->post('/demo-login')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        config(['routewise.demo_login_email' => 'boss@example.test']);
        User::factory()->admin()->create(['email' => 'boss@example.test']);
        $this->post('/demo-login')->assertStatus(503);
        $this->assertGuest();
    }

    public function test_password_login_is_disabled_and_the_page_offers_the_demo_button(): void
    {
        config(['routewise.public_demo' => true]);
        User::factory()->create(['email' => 'someone@example.test', 'password' => 'password']);

        $this->post('/login', ['email' => 'someone@example.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get('/login')->assertOk()->assertSee('Enter the demo workspace')->assertDontSee('name="password"', false);
    }

    public function test_the_banner_is_shown_inside_the_app_and_the_demo_agent_cannot_manage(): void
    {
        config(['routewise.public_demo' => true]);

        $this->actingAs($this->agent())->get('/dashboard')->assertOk()->assertSee('Public demo.');
        $this->post('/sources', ['name' => 'x'])->assertForbidden();
    }

    public function test_reset_refuses_to_run_unless_the_public_demo_is_on(): void
    {
        $this->artisan('routewise:demo-reset')->assertFailed();
    }
}
