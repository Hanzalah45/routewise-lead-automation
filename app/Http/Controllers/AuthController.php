<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        if (config('routewise.public_demo')) {
            return back()->withErrors(['email' => 'Password sign-in is disabled on the public demo. Use "Try the demo".']);
        }

        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /** One-click session for the hosted showcase. It is a seeded agent, never an admin. */
    public function demoLogin(Request $request): RedirectResponse
    {
        abort_unless(config('routewise.public_demo'), 404);

        $user = User::where('email', config('routewise.demo_login_email'))->first();
        abort_unless($user && ! $user->isAdmin(), 503, 'The demo is resetting. Try again in a moment.');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
