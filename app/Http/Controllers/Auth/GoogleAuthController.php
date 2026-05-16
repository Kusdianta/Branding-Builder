<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * BB81 — Google OAuth entry/callback/logout. New users land with 1 free
 * credit; returning users get their google_id back-filled if they signed up
 * with the same email previously (defensive: matches by email if google_id
 * is null in DB, then writes the google_id once). last_login_at is bumped
 * on every successful sign-in so the Hub admin's "Sort by activity" works
 * out of the box.
 */
class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google's OAuth consent screen. The redirect URI baked
     * into Socialite is config('services.google.redirect') — must match
     * the Authorized redirect URI configured in the Google Cloud Console
     * exactly (scheme + host + path).
     */
    public function redirect(): mixed
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Callback from Google. On success, find-or-create the user, log them
     * in, and redirect to the intended URL (or /audit). On any OAuth error
     * (cancelled, network blip, missing config) the user lands back at /
     * with a flash banner — silent failures here would be worst-case UX.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Google OAuth callback failed', [
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('home')->with(
                'auth_error',
                'Login Google gagal. Silakan coba lagi.'
            );
        }

        $user = $this->findOrCreate($googleUser->getId(), $googleUser->getEmail(), $googleUser->getName(), $googleUser->getAvatar());

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('audits.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /**
     * Resolve the user record for this Google identity. Three branches:
     *
     *  1. google_id already known     → bump last_login_at, return.
     *  2. email matches but google_id is null (rare — only if seeded
     *     manually) → back-fill google_id + avatar, bump last_login_at.
     *  3. New identity                → create row with 1 free credit.
     *
     * Wrapped in a transaction so a concurrent first sign-in from the
     * same Google account (double-click on the consent button) cannot
     * yield two rows with the same google_id — the unique index on
     * google_id is the ultimate gate, the transaction just keeps the
     * winning insert and audit trail consistent.
     */
    private function findOrCreate(string $googleId, ?string $email, ?string $name, ?string $avatarUrl): User
    {
        return DB::transaction(function () use ($googleId, $email, $name, $avatarUrl): User {
            $byGoogleId = User::where('google_id', $googleId)->first();

            if ($byGoogleId) {
                $byGoogleId->forceFill([
                    'name'          => $name ?? $byGoogleId->name,
                    'avatar_url'    => $avatarUrl ?? $byGoogleId->avatar_url,
                    'last_login_at' => now(),
                ])->save();

                return $byGoogleId;
            }

            if ($email !== null) {
                $byEmail = User::where('email', $email)->whereNull('google_id')->first();
                if ($byEmail) {
                    $byEmail->forceFill([
                        'google_id'     => $googleId,
                        'name'          => $name ?? $byEmail->name,
                        'avatar_url'    => $avatarUrl ?? $byEmail->avatar_url,
                        'last_login_at' => now(),
                    ])->save();

                    return $byEmail;
                }
            }

            return User::create([
                'google_id'               => $googleId,
                'email'                   => $email ?? $googleId . '@google.local',
                'name'                    => $name ?? 'Pengguna Nema',
                'avatar_url'               => $avatarUrl,
                'credits_balance'         => 1,
                'credits_lifetime_earned' => 1,
                'credits_lifetime_spent'  => 0,
                'last_login_at'           => now(),
            ]);
        });
    }
}
