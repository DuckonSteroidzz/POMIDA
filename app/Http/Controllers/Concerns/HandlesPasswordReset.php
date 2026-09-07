<?php

namespace App\Http\Controllers\Concerns;

use App\Mail\PasswordResetCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Shared "forgot password" logic for the customer and admin/staff portals.
 *
 * Both portals authenticate against the SAME `users` table (see config/auth.php
 * — every guard points at the one `users` provider), so they can safely share
 * Laravel's `password_reset_tokens` table: `users.email` is unique and
 * `password_reset_tokens.email` is that table's primary key, so a single email
 * address can only ever belong to one account with one role. A customer token
 * and an admin token can never collide.
 *
 * Scoping is enforced in two independent places:
 *
 *   1. resolveResettableUser() only ever returns a user whose role is in the
 *      calling controller's allowed roles, so a customer cannot start a reset
 *      for an admin's email, and an admin cannot start one for a customer's.
 *
 *      As of 2026-09-01 the admin portal's list is ['admin'] only — STAFF
 *      accounts have no reset path at all, because the owner's decision is that
 *      only an admin may set a staff password. A staff email therefore falls
 *      into the same "no such account" arm as an unknown one, byte for byte,
 *      which is what keeps it from becoming an account-enumeration oracle. See
 *      AdminAuthController::resettableRoles() for the full reasoning.
 *   2. Each portal stores its progress under its own session key
 *      (see resetSessionKey()), so a half-finished customer reset cannot be
 *      carried across into the admin reset screens.
 */
trait HandlesPasswordReset
{
    /*
    |--------------------------------------------------------------------------
    | Hooks — implemented by each controller
    |--------------------------------------------------------------------------
    */

    /** Roles this portal is allowed to reset. */
    abstract protected function resettableRoles(): array;

    /** Session key prefix; keeps the two flows from bleeding into each other. */
    abstract protected function resetSessionKey(): string;

    /** Blade view prefix, e.g. "customer" or "admin". */
    abstract protected function resetViewPrefix(): string;

    /** Route name prefix, e.g. "customer" or "admin". */
    abstract protected function resetRoutePrefix(): string;

    /** Human wording used when no matching account exists. */
    abstract protected function resetAccountLabel(): string;

    /**
     * Minutes a verification code stays valid.
     *
     * Security review 2026-08-31: tightened from 15 to 10. Still generous for
     * someone switching to their mail app and back, but it shortens the window
     * in which an intercepted or shoulder-surfed code is usable. The email
     * template renders this value, so the wording follows automatically.
     */
    protected int $resetCodeLifetime = 10;

    /**
     * Wrong guesses allowed against ONE issued code before that code is
     * destroyed.
     *
     * Security review 2026-08-31 (Pass 3, Item 2) — why this exists.
     *
     * The verification code is 6 digits, so 1,000,000 possibilities, and the
     * only thing standing between an attacker and a full sweep used to be
     * `throttle:10,1` on the route. Laravel keys an unauthenticated throttle on
     * sha1(domain|IP) — see ThrottleRequests::resolveRequestSignature() — which
     * means it limits an ADDRESS, not a code and not a session. Nothing about a
     * wrong guess was recorded against the code itself, so the code survived
     * any number of failures.
     *
     * Measured, not assumed: hammering /customer/verification from ONE IP was
     * refused at the 10th request, but hammering it from ROTATING IPs accepted
     * 60 out of 60 wrong guesses with no refusal at all and left the token row
     * intact. An attacker with a pool of addresses had, in effect, unlimited
     * guesses inside the code's 10-minute life.
     *
     * This counter closes that: it is keyed on the EMAIL the code was issued
     * for, so it cannot be escaped by changing IP, clearing cookies, or
     * starting a new session. Five is generous for a real person typing six
     * digits into six boxes, and it caps an attacker at 5 guesses per issued
     * code — 5 in 1,000,000 — no matter how much infrastructure they have.
     *
     * Burning the budget destroys the code rather than locking the account, so
     * this cannot be used to deny a real user their reset: they simply request
     * a new code. Each new code is an independent random draw, so guesses never
     * accumulate across codes, and every re-issue puts a fresh email in the
     * real owner's inbox, which makes a sustained attack loud.
     */
    protected int $resetCodeMaxAttempts = 5;

    /*
    |--------------------------------------------------------------------------
    | STEP 1 — request a code
    |--------------------------------------------------------------------------
    */

    public function showForgotPassword()
    {
        return view($this->resetViewPrefix() . '.forgot-password');
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim((string) $request->input('email')));

        $user = $this->resolveResettableUser($email);

        // Scope check: the account must exist AND belong to this portal.
        if (! $user) {
            return back()
                ->withErrors([
                    'email' => 'We could not find an active ' . $this->resetAccountLabel() . ' with that email address.',
                ])
                ->withInput($request->only('email'));
        }

        $code = $this->issueResetCode($user->email);

        if (! $this->deliverResetCode($user, $code)) {
            return back()
                ->withErrors([
                    'email' => 'We could not send the verification code right now. Please try again in a moment.',
                ])
                ->withInput($request->only('email'));
        }

        // Remember which address this flow is for. The code itself is never put
        // in the session — only its hash lives in the database.
        session([$this->resetSessionKey() . '.email' => $user->email]);
        session()->forget($this->resetSessionKey() . '.verified');

        return redirect()
            ->route($this->resetRoutePrefix() . '.verification')
            ->with('success', 'We sent a 6-digit verification code to ' . $user->email . '.')
            ->with('dev_reset_code', $this->devVisibleCode($code));
    }

    /*
    |--------------------------------------------------------------------------
    | STEP 2 — verify the code
    |--------------------------------------------------------------------------
    */

    public function showVerification()
    {
        if (! session($this->resetSessionKey() . '.email')) {
            return redirect()
                ->route($this->resetRoutePrefix() . '.forgot-password')
                ->withErrors(['email' => 'Please enter your email address first.']);
        }

        return view($this->resetViewPrefix() . '.verification');
    }

    public function verifyCode(Request $request)
    {
        $email = session($this->resetSessionKey() . '.email');

        if (! $email) {
            return redirect()
                ->route($this->resetRoutePrefix() . '.forgot-password')
                ->withErrors(['email' => 'Your reset session expired. Please start again.']);
        }

        // The form posts six single-character inputs named otp[].
        $raw = $request->input('otp');
        $code = is_array($raw) ? implode('', $raw) : (string) $raw;
        $code = preg_replace('/[^0-9]/', '', $code);

        if (strlen($code) !== 6) {
            return back()->withErrors([
                'otp' => 'Please enter all 6 digits of the verification code.',
            ]);
        }

        if (! $this->matchResetCode($email, $code)) {
            /*
             * Charge the guess against the CODE, not just the IP. See
             * $resetCodeMaxAttempts. The message below is deliberately the same
             * whether the code was wrong, expired, or has just been destroyed
             * by exhausting the attempt budget — an attacker must not be able
             * to tell those apart, because knowing "the budget is gone" tells
             * them the address is real and a code is live.
             */
            $this->registerFailedResetAttempt($email);

            return back()->withErrors([
                'otp' => 'That verification code is invalid or has expired. Please request a new one.',
            ]);
        }

        // A correct code clears the budget so a user who fumbled a few digits
        // before getting it right does not carry that history forward.
        $this->clearResetAttempts($email);

        session([$this->resetSessionKey() . '.verified' => true]);

        return redirect()->route($this->resetRoutePrefix() . '.new-password');
    }

    public function resendCode()
    {
        $email = session($this->resetSessionKey() . '.email');

        if (! $email) {
            return redirect()
                ->route($this->resetRoutePrefix() . '.forgot-password')
                ->withErrors(['email' => 'Please enter your email address first.']);
        }

        $user = $this->resolveResettableUser($email);

        if (! $user) {
            session()->forget($this->resetSessionKey());

            return redirect()
                ->route($this->resetRoutePrefix() . '.forgot-password')
                ->withErrors(['email' => 'That account is no longer available. Please start again.']);
        }

        $code = $this->issueResetCode($user->email);

        if (! $this->deliverResetCode($user, $code)) {
            return back()->withErrors([
                'otp' => 'We could not resend the code right now. Please try again in a moment.',
            ]);
        }

        // Re-issuing a code invalidates any already-verified step.
        session()->forget($this->resetSessionKey() . '.verified');

        return back()
            ->with('success', 'A new verification code is on its way to ' . $user->email . '.')
            ->with('dev_reset_code', $this->devVisibleCode($code));
    }

    /*
    |--------------------------------------------------------------------------
    | STEP 3 — set the new password
    |--------------------------------------------------------------------------
    */

    public function showNewPassword()
    {
        if (! session($this->resetSessionKey() . '.email')
            || ! session($this->resetSessionKey() . '.verified')) {

            return redirect()
                ->route($this->resetRoutePrefix() . '.forgot-password')
                ->withErrors(['email' => 'Please verify your email address first.']);
        }

        return view($this->resetViewPrefix() . '.new-password');
    }

    public function updatePassword(Request $request)
    {
        $email = session($this->resetSessionKey() . '.email');
        $verified = session($this->resetSessionKey() . '.verified');

        if (! $email || ! $verified) {
            return redirect()
                ->route($this->resetRoutePrefix() . '.forgot-password')
                ->withErrors(['email' => 'Please verify your email address first.']);
        }

        // Security review 2026-08-31: this step previously accepted `min:6`
        // with no complexity, so a reset could set an admin password to
        // `123456`. It now uses the single shared policy — see
        // App\Support\PasswordPolicy.
        $request->validate([
            'password' => \App\Support\PasswordPolicy::required(),
        ], [
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        // Re-check scope at the final step: the role could have changed, or the
        // account been deactivated, since the code was issued.
        $user = $this->resolveResettableUser($email);

        if (! $user) {
            $this->clearResetState($email);

            return redirect()
                ->route($this->resetRoutePrefix() . '.forgot-password')
                ->withErrors(['email' => 'That account is no longer available. Please start again.']);
        }

        // The token row must still exist and be unexpired. This stops a stale
        // session being replayed after the code was already consumed.
        if (! $this->pendingResetRow($email)) {
            $this->clearResetState($email);

            return redirect()
                ->route($this->resetRoutePrefix() . '.forgot-password')
                ->withErrors(['email' => 'Your reset request expired. Please start again.']);
        }

        // The User model casts `password` to "hashed", so assigning the plain
        // value here hashes it exactly once.
        $user->forceFill([
            'password' => $request->input('password'),
            'remember_token' => Str::random(60),
        ])->save();

        $this->clearResetState($email);

        return redirect()
            ->route($this->resetRoutePrefix() . '.login')
            ->with('success', 'Your password has been updated. Please sign in with your new password.');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Look up an active account for this email that this portal is allowed to
     * reset. Returns null for any other role — this is the scope boundary.
     */
    protected function resolveResettableUser(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->whereIn('role', $this->resettableRoles())
            ->where('is_active', true)
            ->first();
    }

    /**
     * Generate a fresh 6-digit code and store only its hash.
     */
    protected function issueResetCode(string $email): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($code),
                'created_at' => now(),
            ]
        );

        // A brand new code is an independent random draw, so guesses made
        // against the previous one are worthless and must not be carried over.
        $this->clearResetAttempts($email);

        return $code;
    }

    /**
     * Where the per-code wrong-guess budget for this email is counted.
     *
     * Keyed on the email, NOT on IP or session, which is the whole point —
     * see $resetCodeMaxAttempts. The address is hashed so a cache dump (the
     * cache store is the database on this deployment) does not become a list
     * of accounts currently mid-reset.
     */
    protected function resetAttemptCacheKey(string $email): string
    {
        return 'password-reset-attempts:' . sha1(strtolower(trim($email)));
    }

    /**
     * Record one wrong guess against the issued code and destroy that code once
     * the budget is spent.
     *
     * The counter expires on its own after the code's own lifetime, so a stale
     * count can never carry into a later reset attempt.
     */
    protected function registerFailedResetAttempt(string $email): void
    {
        $key = $this->resetAttemptCacheKey($email);
        $attempts = ((int) Cache::get($key, 0)) + 1;

        if ($attempts >= $this->resetCodeMaxAttempts) {
            // Budget spent: the code itself is now dead, for everyone, on every
            // IP. Deleting the row is what makes this IP-independent — no
            // further guess against this code can succeed regardless of where
            // it comes from.
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            Cache::forget($key);

            return;
        }

        Cache::put($key, $attempts, now()->addMinutes($this->resetCodeLifetime));
    }

    protected function clearResetAttempts(string $email): void
    {
        Cache::forget($this->resetAttemptCacheKey($email));
    }

    /**
     * The pending, unexpired token row for this email (null if there is none).
     */
    protected function pendingResetRow(string $email): ?object
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $row) {
            return null;
        }

        if (Carbon::parse($row->created_at)->addMinutes($this->resetCodeLifetime)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return null;
        }

        return $row;
    }

    protected function matchResetCode(string $email, string $code): bool
    {
        $row = $this->pendingResetRow($email);

        return $row !== null && Hash::check($code, $row->token);
    }

    protected function clearResetState(string $email): void
    {
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        $this->clearResetAttempts($email);

        session()->forget($this->resetSessionKey());
    }

    /**
     * Send the code. Returns false (instead of throwing a 500) if the mailer is
     * misconfigured, so the user sees a readable message instead of a crash.
     */
    protected function deliverResetCode(User $user, string $code): bool
    {
        try {
            Mail::to($user->email)->send(
                new PasswordResetCode($user, $code, $this->resetCodeLifetime)
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('Password reset code could not be sent', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * DEVELOPMENT CONVENIENCE ONLY.
     *
     * This project ships with MAIL_MAILER=log, which writes the message to
     * storage/logs/laravel.log instead of delivering it. So the reset flow can
     * still be demonstrated without an SMTP account, the code is also shown on
     * screen — but ONLY when the app is in the local environment AND the log
     * mailer is in use. Point MAIL_MAILER at a real SMTP server (or set
     * APP_ENV=production) and this returns null, i.e. nothing is displayed.
     */
    protected function devVisibleCode(string $code): ?string
    {
        /*
         * Security review 2026-08-31 — why this is a double check, and why it
         * must stay one.
         *
         * If this ever returns the code on a public server, it is a complete
         * account takeover: anyone who knows an ADMIN email can request
         * a reset and read the verification code straight off the page, without
         * ever touching that person's inbox. The email step stops being a
         * verification of anything.
         *
         * So BOTH conditions are required, and either one alone is treated as
         * unsafe:
         *
         *   - APP_ENV must be local. If APP_ENV is missing or wrong on the
         *     server, Laravel reports 'production' and this returns null.
         *   - The mailer must be the `log` driver, i.e. genuinely not
         *     delivering. If real SMTP is configured, there is no reason to
         *     print the code, so it is not printed.
         *
         * The point of requiring both is that a HALF-misconfigured production
         * box is still safe. Deploying with APP_ENV accidentally left as local
         * does not leak the code as long as mail is real; deploying with
         * MAIL_MAILER accidentally left as log does not leak it either, as long
         * as APP_ENV is production. Both would have to be wrong at once.
         *
         * Do not relax this to a single condition, and do not swap it for
         * config('app.debug') — debug can legitimately be on while a system is
         * reachable. See docs/DEPLOYMENT.md for the pre-launch checklist that
         * verifies these settings on the live server.
         */
        $isLocalEnvironment = app()->environment('local');
        $mailerIsNotDelivering = config('mail.default') === 'log';

        if ($isLocalEnvironment && $mailerIsNotDelivering) {
            return $code;
        }

        return null;
    }
}
