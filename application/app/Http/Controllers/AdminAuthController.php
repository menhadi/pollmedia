<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function account(): View
    {
        return view('admin-account');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'current_password' => 'required|string|current_password',
            'password' => 'required|string|confirmed',
        ]);
        $user = $request->user();
        DB::transaction(function () use ($user, $input): void {
            $user->password = $input['password'];
            $user->setRememberToken(Str::random(60));
            $user->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))
                    ->where('user_id', $user->id)->delete();
            }
        });
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'Password updated. Sign in with your new password.');
    }

    public function login(Request $request): View|RedirectResponse
    {
        if ($request->user()?->is_admin) {
            return redirect()->route('admin.dashboard');
        }
        $canSetup = app()->environment('local')
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            && in_array($request->getHost(), ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            && ! User::where('is_admin', true)->exists();

        return view('admin-login', compact('canSetup'));
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $input = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string']);
        $input['email'] = mb_strtolower(trim($input['email']));
        $key = 'admin-login:'.hash('sha256', $input['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Please wait one minute before trying again.']);
        }
        if (! Auth::attempt($input + ['is_admin' => true])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Unable to sign in with these details.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'You have signed out.');
    }

    public function setup(): View
    {
        abort_if(User::where('is_admin', true)->exists(), 404);

        return view('admin-setup');
    }

    public function storeAdministrator(Request $request): RedirectResponse
    {
        abort_if(User::where('is_admin', true)->exists(), 404);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $input = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|confirmed']);
        $user = DB::transaction(function () use ($input): User {
            abort_if(User::where('is_admin', true)->lockForUpdate()->exists(), 404);
            $user = new User($input);
            $user->is_admin = true;
            $user->save();

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', 'Administrator account created. You are signed in.');
    }
}
