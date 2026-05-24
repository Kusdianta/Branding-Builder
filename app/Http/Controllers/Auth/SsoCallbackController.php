<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\HubSsoClient;
use App\Services\SsoTokenValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * BB02/BB03/BB05 — spoke-side SSO. Replaces the old GoogleAuthController.
 *
 *  - login():    bounce an unauthenticated user to the Hub SSO gateway.
 *  - callback(): validate the Hub's signed token, sync the local user, log in.
 *  - logout():   clear the local session + notify the Hub (platform-wide).
 *
 * The Google OAuth itself now lives ONLY in the Hub. This spoke never sees
 * a Google credential.
 */
class SsoCallbackController extends Controller
{
    public function __construct(private readonly SsoTokenValidator $validator)
    {
    }

    /**
     * GET /auth/login — send the user to the Hub SSO gateway. The Hub signs
     * them in (Google, or instantly if a Hub session exists) and redirects
     * back to /auth/sso/callback with a signed token.
     */
    public function login(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended(route('audits.index'));
        }

        $callback = route('auth.sso.callback');
        $hubUrl = rtrim((string) config('sso.hub_sso_url'), '/');

        $url = $hubUrl
            . '?spoke=' . urlencode((string) config('sso.spoke_slug', 'branding-builder'))
            . '&callback=' . urlencode($callback);

        return redirect()->away($url);
    }

    /**
     * GET /auth/sso/callback?sso_token=... — validate + establish session.
     */
    public function callback(Request $request): RedirectResponse
    {
        $payload = $this->validator->validate((string) $request->query('sso_token', ''));

        if ($payload === null) {
            return redirect()->route('home')->with(
                'auth_error',
                'Sesi login tidak valid atau kedaluwarsa. Silakan coba lagi.'
            );
        }

        $user = $this->findOrCreate($payload);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('audits.index'));
    }

    /**
     * POST /auth/logout — clear the local session and ask the Hub to log the
     * user out everywhere else too.
     */
    public function logout(Request $request, HubSsoClient $hub): RedirectResponse
    {
        $hubUserId = Auth::check() ? (string) (Auth::user()->hub_user_id ?? '') : '';

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $hub->notifyLogout($hubUserId);

        return redirect()->route('home');
    }

    /**
     * Reconcile the Hub identity with the local user record. Order preserves
     * existing users (and their credits + audit history):
     *
     *  1. hub_user_id known        → update profile, keep credits.
     *  2. google_id known (pre-SSO) → back-fill hub_user_id, keep credits.
     *  3. email matches, no google  → link both ids.
     *  4. brand-new identity        → create with 1 free credit (unchanged).
     *
     * @param  array<string,mixed>  $payload
     */
    private function findOrCreate(array $payload): User
    {
        $hubUserId = (string) ($payload['hub_user_id'] ?? '');
        $googleId = $payload['google_id'] ?? null;
        $email = $payload['email'] ?? null;
        $name = $payload['name'] ?? null;
        $avatar = $payload['avatar'] ?? null;

        return DB::transaction(function () use ($hubUserId, $googleId, $email, $name, $avatar): User {
            if ($hubUserId !== '') {
                $byHub = User::where('hub_user_id', $hubUserId)->first();
                if ($byHub) {
                    $byHub->forceFill([
                        'google_id'     => $googleId ?? $byHub->google_id,
                        'name'          => $name ?? $byHub->name,
                        'avatar_url'    => $avatar ?? $byHub->avatar_url,
                        'last_login_at' => now(),
                    ])->save();

                    return $byHub;
                }
            }

            if ($googleId !== null) {
                $byGoogle = User::where('google_id', $googleId)->first();
                if ($byGoogle) {
                    $byGoogle->forceFill([
                        'hub_user_id'   => $hubUserId !== '' ? $hubUserId : $byGoogle->hub_user_id,
                        'name'          => $name ?? $byGoogle->name,
                        'avatar_url'    => $avatar ?? $byGoogle->avatar_url,
                        'last_login_at' => now(),
                    ])->save();

                    return $byGoogle;
                }
            }

            if ($email !== null) {
                $byEmail = User::where('email', $email)->whereNull('google_id')->first();
                if ($byEmail) {
                    $byEmail->forceFill([
                        'hub_user_id'   => $hubUserId !== '' ? $hubUserId : $byEmail->hub_user_id,
                        'google_id'     => $googleId,
                        'name'          => $name ?? $byEmail->name,
                        'avatar_url'    => $avatar ?? $byEmail->avatar_url,
                        'last_login_at' => now(),
                    ])->save();

                    return $byEmail;
                }
            }

            return User::create([
                'hub_user_id'             => $hubUserId !== '' ? $hubUserId : null,
                'google_id'               => $googleId,
                'email'                   => $email ?? ($googleId . '@google.local'),
                'name'                    => $name ?? 'Pengguna Nema',
                'avatar_url'              => $avatar,
                'credits_balance'         => 1,
                'credits_lifetime_earned' => 1,
                'credits_lifetime_spent'  => 0,
                'last_login_at'           => now(),
            ]);
        });
    }
}
