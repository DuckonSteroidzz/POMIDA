<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Can the 6-digit password-reset code simply be GUESSED?
 *
 * Pass 2 (ResetCodeNotLeakedTest) proved the code is never exposed in
 * production. This file answers the remaining question: the code is 6 digits,
 * so 1,000,000 possibilities submitted through an ordinary form — what stops
 * an attacker from working through them?
 *
 * WHAT WAS FOUND, BY MEASUREMENT NOT BY READING
 * ---------------------------------------------
 * Both portals throttle the verification submit at 10 a minute, keyed on the
 * IP. The admin side is still the raw `throttle:10,1`; the customer side is the
 * named per-IP limiter `customer-verification` as of Pass 13 (2026-09-09), same
 * 10/min. This matters here in two ways:
 *
 *   a) The limit is per IP ADDRESS. Not per session, not per code, not per
 *      account.
 *   b) On the admin side, the empty-prefix raw throttle still means every plain
 *      `throttle:X,Y` route shares one counter per IP — so the POST to
 *      /admin/forgot-password that starts the flow spends one of the same 10
 *      slots the verification step then uses (first 429 on the 10th attempt,
 *      not the 11th). On the customer side the named limiter now has its own
 *      counter, so /customer/verification gets its full 10 regardless of the
 *      forgot-password POST. Either way one IP is capped well under 100 guesses
 *      inside the code's 10-minute life.
 *
 * From a single IP that is a hard ceiling of well under 100 guesses inside the
 * code's 10-minute life. Against 1,000,000 that is nothing.
 *
 * But an IP limit only limits an IP. Before the fix, running the exact same
 * attack from ROTATING addresses accepted 60 out of 60 wrong guesses with no
 * refusal whatsoever and left the token row fully intact — nothing was ever
 * recorded against the CODE. An attacker with a pool of addresses had, in
 * practice, unlimited guesses for ten minutes.
 *
 * THE FIX
 * -------
 * HandlesPasswordReset now counts wrong guesses against the EMAIL the code was
 * issued for ($resetCodeMaxAttempts = 5) and DELETES the token row once that
 * budget is spent. Because the code itself is destroyed, changing IP, clearing
 * cookies, or starting a new session buys an attacker nothing.
 *
 * THE MATH, AFTER THE FIX
 * -----------------------
 *   before : rotating IPs -> unbounded guesses in 10 min against 1,000,000
 *   after  : 5 guesses per issued code, from anywhere, ever -> 5 / 1,000,000
 *
 * Guesses do not accumulate across codes, because each re-issue is an
 * independent random draw, and every re-issue puts another email in the real
 * owner's inbox.
 *
 * FALSE-POSITIVE DISCIPLINE
 * -------------------------
 * "The correct code is rejected after N wrong guesses" is only meaningful if
 * the correct code is ACCEPTED when the budget has not been spent. Every
 * lockout assertion below is paired with that control — see
 * test_a_correct_code_within_budget_is_accepted_control(). Without it, a test
 * that simply broke the reset flow entirely would pass just as happily.
 */
class ResetCodeBruteForceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function adminOrStaff(): User
    {
        return User::whereIn('role', ['admin', 'staff'])->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    /**
     * Plant a known code exactly the way issueResetCode() does — same table,
     * same hashing — so the test exercises the real matching path.
     */
    private function plantCode(string $email, string $code): void
    {
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($code), 'created_at' => now()]
        );
    }

    private function codeStillExists(string $email): bool
    {
        return DB::table('password_reset_tokens')->where('email', $email)->exists();
    }

    /** Submit one guess from a given source address. */
    private function guess(string $path, string $sessionKey, string $email, string $code, ?string $ip = null)
    {
        $test = $this->withSession([$sessionKey . '.email' => $email]);

        if ($ip !== null) {
            $test = $test->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        return $test->post($path, ['otp' => str_split($code)]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. The throttle configuration actually in force
    // ══════════════════════════════════════════════════════════════════

    /**
     * Pins the exact throttle string on both verification endpoints. If anyone
     * loosens these, the math in this file's docblock stops being true and this
     * test says so.
     */
    public function test_both_verification_endpoints_are_throttled_at_ten_per_minute(): void
    {
        // admin.verification.post is still a raw throttle:10,1 (not in Pass 13's
        // scope). customer.verification.post moved to the named per-IP limiter
        // `customer-verification` in Pass 13 — same 10/min, its own counter — so
        // it is pinned by resolving the limiter to a number instead.
        $adminRoute = Route::getRoutes()->getByName('admin.verification.post');
        $this->assertNotNull($adminRoute, 'route admin.verification.post should exist');
        $this->assertContains(
            'throttle:10,1',
            $adminRoute->gatherMiddleware(),
            'admin.verification.post must stay throttled at 10 requests per minute'
        );

        $customerRoute = Route::getRoutes()->getByName('customer.verification.post');
        $this->assertNotNull($customerRoute, 'route customer.verification.post should exist');
        $this->assertContains(
            'throttle:customer-verification',
            $customerRoute->gatherMiddleware(),
            'customer.verification.post must go through the named customer-verification limiter'
        );

        $request = \Illuminate\Http\Request::create('http://127.0.0.1/customer/verification', 'POST');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $limit = app(\Illuminate\Cache\RateLimiter::class)->limiter('customer-verification')($request);
        $limit = is_array($limit) ? $limit[0] : $limit;
        $this->assertSame(10, $limit->maxAttempts, 'customer verification must stay at 10 a minute');
        $this->assertSame(60, $limit->decaySeconds, 'the window must stay one minute');
    }

    /** The code's life is what bounds the attack window. Pin it. */
    public function test_reset_code_lifetime_is_ten_minutes(): void
    {
        foreach ([
            \App\Http\Controllers\Customer\AuthController::class,
            \App\Http\Controllers\Admin\AdminAuthController::class,
        ] as $class) {
            $prop = new \ReflectionProperty($class, 'resetCodeLifetime');
            $prop->setAccessible(true);
            $this->assertSame(10, $prop->getValue(app($class)), "{$class} code lifetime");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. A single IP is refused long before it can make a dent
    // ══════════════════════════════════════════════════════════════════

    /**
     * Hammer the real endpoint with wrong codes from one address and record
     * where it is actually refused.
     */
    public function test_a_single_ip_is_throttled_within_ten_wrong_guesses(): void
    {
        $email = $this->customer()->email;
        $this->plantCode($email, '111111');

        $accepted = 0;
        $blockedAt = null;

        for ($i = 1; $i <= 40; $i++) {
            $guess = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $response = $this->guess('/customer/verification', 'customer_password_reset', $email, $guess, '198.51.100.7');

            if ($response->status() === 429) {
                $blockedAt = $i;
                break;
            }
            $accepted++;
        }

        $this->assertNotNull($blockedAt, 'the endpoint never returned 429 — it is not throttled at all');
        $this->assertLessThanOrEqual(
            10,
            $accepted,
            'a single IP got more than 10 guesses through; throttle:10,1 is not in force'
        );

        // Against 1,000,000 possibilities this is nothing. Stated as an
        // assertion so the ratio is on the record, not just in a comment.
        $this->assertLessThan(
            0.0001,
            $accepted / 1000000,
            'a single IP should reach well under 0.01% of the search space'
        );
    }

    public function test_a_single_ip_is_throttled_on_the_admin_portal_too(): void
    {
        $email = $this->adminOrStaff()->email;
        $this->plantCode($email, '111111');

        $accepted = 0;
        $blockedAt = null;

        for ($i = 1; $i <= 40; $i++) {
            $guess = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $response = $this->guess('/admin/verification', 'admin_password_reset', $email, $guess, '198.51.100.8');

            if ($response->status() === 429) {
                $blockedAt = $i;
                break;
            }
            $accepted++;
        }

        $this->assertNotNull($blockedAt, 'the admin endpoint never returned 429');
        $this->assertLessThanOrEqual(10, $accepted, 'a single IP got more than 10 guesses through on admin');
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. The gap that was found: rotating IPs
    // ══════════════════════════════════════════════════════════════════

    /**
     * THE REGRESSION TEST FOR THE ACTUAL FIX.
     *
     * Every guess comes from a different address, so the IP throttle never
     * fires. What must stop the attack is the code being destroyed — proven
     * here the only way that means anything: by showing the CORRECT code no
     * longer works afterwards.
     */
    public function test_rotating_ips_cannot_out_wait_the_throttle_because_the_code_dies(): void
    {
        $email = $this->customer()->email;
        $correct = '424242';
        $this->plantCode($email, $correct);

        $wrongGuesses = 0;
        for ($i = 1; $i <= 5; $i++) {
            $guess = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $this->assertNotSame($correct, $guess, 'guard: the probe must never accidentally guess right');

            $response = $this->guess(
                '/customer/verification',
                'customer_password_reset',
                $email,
                $guess,
                '203.0.113.' . $i          // a fresh address every single time
            );

            $this->assertNotSame(429, $response->status(), 'rotating IPs should NOT be IP-throttled — that is the point');
            $wrongGuesses++;
        }

        $this->assertSame(5, $wrongGuesses);

        $this->assertFalse(
            $this->codeStillExists($email),
            'after the wrong-guess budget was spent the code must be destroyed, not merely rate limited'
        );

        // The assertion that actually proves it. From yet another fresh
        // address, with the RIGHT code.
        $response = $this->guess(
            '/customer/verification',
            'customer_password_reset',
            $email,
            $correct,
            '203.0.113.200'
        );

        $response->assertRedirect();
        $this->assertNotSame(
            route('customer.new-password'),
            $response->headers->get('Location'),
            'the correct code was still accepted after the budget was spent — the code was not really invalidated'
        );
        $this->assertFalse(
            session('customer_password_reset.verified', false),
            'a burnt code must never mark the session verified'
        );
    }

    public function test_rotating_ips_cannot_out_wait_the_throttle_on_admin_either(): void
    {
        $email = $this->adminOrStaff()->email;
        $correct = '787878';
        $this->plantCode($email, $correct);

        for ($i = 1; $i <= 5; $i++) {
            $guess = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $this->assertNotSame($correct, $guess);
            $this->guess('/admin/verification', 'admin_password_reset', $email, $guess, '203.0.113.' . (100 + $i));
        }

        $this->assertFalse($this->codeStillExists($email), 'the admin code must be destroyed too');

        $response = $this->guess('/admin/verification', 'admin_password_reset', $email, $correct, '203.0.113.201');
        $this->assertNotSame(
            route('admin.new-password'),
            $response->headers->get('Location'),
            'the correct admin code survived the budget — not invalidated'
        );
        $this->assertFalse(session('admin_password_reset.verified', false));
    }

    /**
     * THE CONTROL. Without this, the two tests above would pass even if the
     * reset flow were simply broken and no code ever worked.
     */
    public function test_a_correct_code_within_budget_is_accepted_control(): void
    {
        $email = $this->customer()->email;
        $correct = '555111';
        $this->plantCode($email, $correct);

        // Spend some, but not all, of the budget first — a real person
        // mistyping twice must still be able to finish.
        $this->guess('/customer/verification', 'customer_password_reset', $email, '000001', '203.0.113.50');
        $this->guess('/customer/verification', 'customer_password_reset', $email, '000002', '203.0.113.51');

        $this->assertTrue($this->codeStillExists($email), 'two wrong guesses must not kill the code');

        $response = $this->guess('/customer/verification', 'customer_password_reset', $email, $correct, '203.0.113.52');

        $response->assertRedirect(route('customer.new-password'));
        $this->assertTrue(
            session('customer_password_reset.verified', false),
            'the correct code, within budget, must still verify — otherwise the lockout tests prove nothing'
        );
    }

    /**
     * A fresh code restores a full budget, so an attacker who burns one code
     * gains nothing cumulative, and a real user is never stuck.
     */
    public function test_requesting_a_new_code_resets_the_budget(): void
    {
        $user = $this->customer();
        $email = $user->email;
        $this->plantCode($email, '999999');

        for ($i = 1; $i <= 5; $i++) {
            $this->guess('/customer/verification', 'customer_password_reset', $email, str_pad((string) $i, 6, '0', STR_PAD_LEFT), '203.0.113.' . (60 + $i));
        }
        $this->assertFalse($this->codeStillExists($email), 'budget spent, code gone');

        // The real user asks for another code.
        $this->post('/customer/forgot-password', ['email' => $email])->assertRedirect();
        $this->assertTrue($this->codeStillExists($email), 'a new code should have been issued');

        // And that new code has its own full budget: a wrong guess must not
        // instantly kill it.
        $this->guess('/customer/verification', 'customer_password_reset', $email, '000123', '203.0.113.70');
        $this->assertTrue(
            $this->codeStillExists($email),
            'the new code inherited the old code\'s spent budget — each code must get its own'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. A wrong guess must not narrow the search space
    // ══════════════════════════════════════════════════════════════════

    /**
     * Guesses at every "distance" from the real code — one digit off, five
     * digits off, right digits in the wrong order, entirely different — must
     * all produce the byte-identical response. Any variation would be an
     * oracle worth more than the throttle costs.
     */
    public function test_wrong_code_responses_are_identical_regardless_of_closeness(): void
    {
        $email = $this->customer()->email;
        $correct = '135790';

        $guesses = [
            '135791',  // one digit off
            '135709',  // last two transposed
            '035790',  // first digit off
            '901357',  // same digits, rotated
            '864208',  // nothing in common
        ];

        $messages = [];
        $statuses = [];

        foreach ($guesses as $i => $guess) {
            // Fresh code each time so the attempt budget never interferes with
            // what is being measured here.
            Cache::flush();
            $this->plantCode($email, $correct);

            $response = $this->guess('/customer/verification', 'customer_password_reset', $email, $guess, '198.51.100.' . (10 + $i));

            $statuses[] = $response->status();
            $errors = session('errors');
            $messages[] = $errors ? $errors->first('otp') : '(no error)';
        }

        $this->assertCount(1, array_unique($messages),
            'wrong-code messages differ by how close the guess was, which narrows the search space: '
            . json_encode(array_unique($messages)));
        $this->assertCount(1, array_unique($statuses), 'wrong-code HTTP statuses differ by closeness');

        $this->assertSame(
            'That verification code is invalid or has expired. Please request a new one.',
            $messages[0],
            'the wrong-code message must stay generic and must not distinguish invalid from expired'
        );
    }

    /**
     * "Wrong code" and "no code issued at all" must also look the same, so the
     * endpoint cannot be used to discover whether a reset is in flight.
     */
    public function test_a_wrong_code_and_a_nonexistent_code_look_the_same(): void
    {
        $email = $this->customer()->email;

        $this->plantCode($email, '246800');
        $wrong = $this->guess('/customer/verification', 'customer_password_reset', $email, '111222', '198.51.100.30');
        $wrongMessage = session('errors') ? session('errors')->first('otp') : null;

        Cache::flush();
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        $none = $this->guess('/customer/verification', 'customer_password_reset', $email, '111222', '198.51.100.31');
        $noneMessage = session('errors') ? session('errors')->first('otp') : null;

        $this->assertSame($wrongMessage, $noneMessage, 'the endpoint reveals whether a code is currently outstanding');
        $this->assertSame($wrong->status(), $none->status());
    }

    /**
     * And a burnt code must be indistinguishable from an ordinary wrong guess,
     * or exhausting the budget itself becomes the signal.
     */
    public function test_a_burnt_code_is_indistinguishable_from_an_ordinary_wrong_guess(): void
    {
        $email = $this->customer()->email;

        $this->plantCode($email, '314159');
        $ordinary = $this->guess('/customer/verification', 'customer_password_reset', $email, '000999', '198.51.100.40');
        $ordinaryMessage = session('errors') ? session('errors')->first('otp') : null;

        for ($i = 1; $i <= 5; $i++) {
            $this->guess('/customer/verification', 'customer_password_reset', $email, str_pad((string) $i, 6, '0', STR_PAD_LEFT), '198.51.100.' . (50 + $i));
        }

        $burnt = $this->guess('/customer/verification', 'customer_password_reset', $email, '777888', '198.51.100.60');
        $burntMessage = session('errors') ? session('errors')->first('otp') : null;

        $this->assertSame($ordinaryMessage, $burntMessage, 'hitting the attempt limit is observable to the attacker');
        $this->assertSame($ordinary->status(), $burnt->status());
    }

    // ══════════════════════════════════════════════════════════════════
    // 5. The budget itself
    // ══════════════════════════════════════════════════════════════════

    public function test_the_per_code_attempt_budget_is_five_and_is_keyed_on_the_email(): void
    {
        foreach ([
            \App\Http\Controllers\Customer\AuthController::class,
            \App\Http\Controllers\Admin\AdminAuthController::class,
        ] as $class) {
            $prop = new \ReflectionProperty($class, 'resetCodeMaxAttempts');
            $prop->setAccessible(true);
            $this->assertSame(5, $prop->getValue(app($class)), "{$class} attempt budget");
        }

        // Keyed on the email, which is what makes it IP-independent. If this
        // key ever picks up an IP or session id, the whole fix is undone.
        $controller = app(\App\Http\Controllers\Customer\AuthController::class);
        $method = new \ReflectionMethod($controller, 'resetAttemptCacheKey');
        $method->setAccessible(true);

        $this->assertSame(
            $method->invoke($controller, 'someone@example.com'),
            $method->invoke($controller, 'SOMEONE@Example.com  '),
            'the attempt key must normalise the email, or case/whitespace resets the budget'
        );
        $this->assertNotSame(
            $method->invoke($controller, 'a@example.com'),
            $method->invoke($controller, 'b@example.com'),
            'different accounts must not share one budget'
        );
        $this->assertStringNotContainsString(
            'example.com',
            $method->invoke($controller, 'someone@example.com'),
            'the cache key should not store the address in clear text'
        );
    }
}
