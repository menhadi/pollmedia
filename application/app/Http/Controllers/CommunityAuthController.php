<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CommunityAuthSettings;
use App\Services\CommunityGoogleIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CommunityAuthController extends Controller
{
    public static function emailReady(): bool
    {
        return app(CommunityAuthSettings::class)->emailReady();
    }

    public static function googleReady(): bool
    {
        return app(CommunityAuthSettings::class)->googleReady();
    }

    public function login(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('community.account');
        }
        $next = (string) $request->query('next', '');
        if (\App\Services\CommunityData::publicEditReturn($next) || (preg_match('~^/contribute(?:[/?]|$)~', $next) && ! preg_match('/[\\\\\x00-\x20]/', $next))) {
            $request->session()->put('url.intended', $next);
        }

        return view('community-login', ['emailReady' => self::emailReady(), 'googleReady' => self::googleReady(), 'challenge' => $request->session()->has('community.challenge')]);
    }

    public function sendCode(Request $request): RedirectResponse
    {
        abort_unless(self::emailReady(), 503, 'Email sign-in is awaiting delivery configuration.');
        $input = $request->validate(['email' => 'required|email:rfc|max:254']);
        $email = mb_strtolower(trim($input['email']));
        foreach (['email' => $email, 'ip' => $request->ip()] as $kind => $identity) {
            $key = 'community-otp:'.$kind.':'.hash('sha256', $identity);
            abort_if(RateLimiter::tooManyAttempts($key, $kind === 'email' ? 3 : 10), 429, 'Please wait before requesting another code.');
            RateLimiter::hit($key, 600);
        }
        $id = bin2hex(random_bytes(32));
        $code = (string) random_int(100000, 999999);
        DB::table('community_login_codes')->where('expires_at', '<', now()->subDay())->delete();
        if ($previous = $request->session()->get('community.challenge')) {
            DB::table('community_login_codes')->where('id', $previous)->update(['used_at' => now()]);
        }
        DB::table('community_login_codes')->insert(['id' => $id, 'email' => $email, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);
        try {
            $settings = app(CommunityAuthSettings::class);
            $mailer = $settings->prepareMailer();
            $sender = $settings->values();
            $send = $mailer ? Mail::mailer($mailer)->raw(...) : Mail::raw(...);
            $send("Your Pollmedia sign-in code is {$code}. It expires in 10 minutes. Check your spam or junk folder if future codes do not arrive in your inbox. Do not share this code. If you did not request it, ignore this email.", function ($message) use ($email, $sender): void {
                if ($sender['email_enabled'] === true) {
                    $message->from($sender['from_address'], $sender['from_name']);
                }
                $message->to($email)->subject('Your Pollmedia sign-in code');
            });
        } catch (\Throwable $exception) {
            DB::table('community_login_codes')->where('id', $id)->delete();
            report(new \RuntimeException('Contributor OTP email delivery failed: '.$exception::class));
            throw ValidationException::withMessages(['email' => 'The code could not be delivered. Please try again later.']);
        }
        $request->session()->put('community.challenge', $id);

        return redirect()->route('community.login')->with('status', 'Check your email for a code. Check your spam or junk folder too.');
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        abort_unless(self::emailReady(), 503, 'Email sign-in is currently disabled.');
        $input = $request->validate(['code' => 'required|digits:6']);
        $id = $request->session()->get('community.challenge');
        $email = DB::transaction(function () use ($id, $input): ?string {
            $row = DB::table('community_login_codes')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->used_at || $row->expires_at <= now()->toDateTimeString() || $row->attempts >= 5) {
                return null;
            }
            DB::table('community_login_codes')->where('id', $id)->increment('attempts');
            if (! Hash::check($input['code'], $row->code_hash)) {
                return null;
            }
            DB::table('community_login_codes')->where('id', $id)->update(['used_at' => now()]);

            return $row->email;
        });
        if (! $email) {
            throw ValidationException::withMessages(['code' => 'This code is invalid or expired. Request a new code if needed.']);
        }
        $request->session()->forget('community.challenge');
        $request->session()->put('community.password_verified', ['email' => $email, 'at' => time()]);

        return $this->signIn($request, $email);
    }

    public function google(Request $request): RedirectResponse
    {
        abort_unless(self::googleReady(), 503, 'Google sign-in is awaiting configuration.');
        $request->session()->put('community.google', $state = ['state' => Str::random(64), 'nonce' => Str::random(64), 'created' => time()]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => app(CommunityAuthSettings::class)->values()['google_client_id'], 'response_type' => 'code',
            'redirect_uri' => $this->googleRedirect(), 'scope' => 'openid email profile', 'state' => $state['state'], 'nonce' => $state['nonce'],
        ]));
    }

    public function googleCallback(Request $request, CommunityGoogleIdentity $identity): RedirectResponse
    {
        $state = $request->session()->pull('community.google');
        abort_unless(self::googleReady() && $state && time() - $state['created'] < 600
            && hash_equals($state['state'], (string) $request->query('state')) && is_string($request->query('code')), 419, 'Sign-in expired. Please try again.');
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => app(CommunityAuthSettings::class)->values()['google_client_id'],
            'client_secret' => app(CommunityAuthSettings::class)->values()['google_client_secret'],
            'redirect_uri' => $this->googleRedirect(), 'grant_type' => 'authorization_code', 'code' => $request->query('code'),
        ]);
        abort_unless($response->successful(), 422, 'Google sign-in could not be completed. Please try again.');
        $claims = $identity->verify((string) $response->json('id_token'), $state['nonce']);

        return $this->signIn($request, mb_strtolower($claims['email']), $claims['sub']);
    }

    private function googleRedirect(): string
    {
        return config('community-auth.google_redirect_uri') ?: route('community.google.callback');
    }

    private function signIn(Request $request, string $email, ?string $subject = null): RedirectResponse
    {
        $user = DB::transaction(function () use ($email, $subject): User {
            $user = User::where('email', $email)->first();
            abort_if($user?->is_admin, 403, 'Administrators must use the administrator sign-in.');
            if ($subject) {
                $account = DB::table('community_google_accounts')->where('subject', $subject)->first();
                abort_if($account && (! $user || $account->user_id !== $user->id), 403, 'This Google identity is already linked to another account.');
            }
            $user ??= User::create(['name' => 'Contributor', 'email' => $email, 'password' => Str::random(64)]);
            $user->forceFill(['email_verified_at' => now()])->save();
            if ($subject) {
                DB::table('community_google_accounts')->insertOrIgnore(['subject' => $subject, 'user_id' => $user->id, 'created_at' => now()]);
            }

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('community.account'));
    }

    public function passwordLogin(Request $request): RedirectResponse
    {
        $input = $request->validate(['email' => 'required|email:rfc|max:254', 'password' => 'required|string|max:1000']);
        $email = mb_strtolower(trim($input['email']));
        $key = 'community-password:'.hash('sha256', $email.'|'.$request->ip());
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Please wait before trying again.');
        RateLimiter::hit($key, 600);
        $user = User::where('email', $email)->first();
        if (! $user || $user->is_admin || ! $user->email_verified_at || ! $user->contributor_password_set_at || ! Hash::check($input['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email or password is incorrect. You can also sign in with an email code.']);
        }
        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('community.account'));
    }

    public function setPassword(Request $request): RedirectResponse
    {
        abort_unless($request->user() && ! $request->user()->is_admin, 403);
        $proof = $request->session()->get('community.password_verified');
        abort_unless(is_array($proof) && ($proof['email'] ?? null) === $request->user()->email && time() - ($proof['at'] ?? 0) < 600, 403, 'Verify a fresh email code before setting a password.');
        $input = $request->validate(['password' => ['required', 'string', 'min:12', 'max:128', 'confirmed']]);
        $request->user()->forceFill(['password' => $input['password'], 'contributor_password_set_at' => now(), 'remember_token' => Str::random(60)])->save();
        $request->session()->forget('community.password_verified');
        $request->session()->regenerate();

        return redirect()->route('community.account')->with('status', 'Password saved. You can now sign in with email and password, Google, or an email code.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('community.data');
    }
}
