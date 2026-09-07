<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Manual security review 2026-08-31, item 4c.
 *
 * The verification code used to stay valid for 15 minutes. It is now 10.
 * These tests pin the boundary from both sides so the window cannot quietly
 * drift back: a code just inside it must still work, and one just outside it
 * must be refused.
 *
 * The clock is moved by ageing the token row rather than by sleeping, so the
 * boundary is exercised exactly and the suite stays fast.
 */
class ResetCodeLifetimeTest extends TestCase
{
    use DatabaseTransactions;

    private const LIFETIME = 10;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    /**
     * Issue a real code through the real endpoint and return it.
     *
     * The code is only readable from the session in the local environment with
     * a non-delivering mailer — that gate is the subject of
     * ResetCodeNotLeakedTest and is deliberately closed under APP_ENV=testing.
     * So the environment is switched to local here purely to observe the code,
     * which also means CSRF has to be disabled explicitly (see that test for
     * why: runningUnitTests() is just environment('testing')).
     */
    private function issueCodeFor(User $admin): string
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        Mail::fake();
        $this->app->detectEnvironment(fn () => 'local');
        config(['mail.default' => 'log']);

        $this->post(route('admin.forgot-password.post'), ['email' => $admin->email])
            ->assertRedirect(route('admin.verification'));

        $code = session('dev_reset_code');

        $this->assertNotNull($code, 'the local dev banner should have exposed the code for this test');

        return (string) $code;
    }

    /** Backdate the issued token so it looks N minutes old. */
    private function ageCodeBy(User $admin, int $minutes): void
    {
        DB::table('password_reset_tokens')
            ->where('email', $admin->email)
            ->update(['created_at' => now()->subMinutes($minutes)]);
    }

    private function submitCode(string $code)
    {
        return $this->post(route('admin.verification.post'), [
            'otp' => str_split($code),
        ]);
    }

    public function test_the_configured_lifetime_is_ten_minutes(): void
    {
        $controller = new \App\Http\Controllers\Admin\AdminAuthController();
        $property = new \ReflectionProperty($controller, 'resetCodeLifetime');
        $property->setAccessible(true);

        $this->assertSame(self::LIFETIME, $property->getValue($controller));
    }

    public function test_a_code_just_inside_the_window_is_accepted(): void
    {
        $admin = $this->admin();
        $code = $this->issueCodeFor($admin);

        $this->ageCodeBy($admin, self::LIFETIME - 1); // 9 minutes old

        $this->submitCode($code)->assertRedirect(route('admin.new-password'));
        $this->assertTrue(session('admin_password_reset.verified'));
    }

    public function test_a_code_past_the_window_is_refused(): void
    {
        $admin = $this->admin();
        $code = $this->issueCodeFor($admin);

        $this->ageCodeBy($admin, self::LIFETIME + 1); // 11 minutes old

        $this->submitCode($code)->assertSessionHasErrors('otp');
        $this->assertNotTrue(session('admin_password_reset.verified'));
    }

    public function test_a_code_that_would_still_have_worked_under_the_old_15_minute_window_is_now_refused(): void
    {
        // This is the actual behaviour change: 12 minutes was valid before, and
        // must not be any more.
        $admin = $this->admin();
        $code = $this->issueCodeFor($admin);

        $this->ageCodeBy($admin, 12);

        $this->submitCode($code)->assertSessionHasErrors('otp');
    }

    public function test_an_expired_code_row_is_cleaned_up_rather_than_left_lying_around(): void
    {
        $admin = $this->admin();
        $code = $this->issueCodeFor($admin);

        $this->ageCodeBy($admin, self::LIFETIME + 5);
        $this->submitCode($code);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);
    }

    public function test_resend_issues_a_fresh_code_that_works_on_its_own_window(): void
    {
        $admin = $this->admin();
        $original = $this->issueCodeFor($admin);

        // Let the first code go stale.
        $this->ageCodeBy($admin, self::LIFETIME + 1);

        Mail::fake();
        $this->get(route('admin.verification.resend'))->assertRedirect();

        $fresh = session('dev_reset_code');
        $this->assertNotNull($fresh, 'resend should have issued a new code');
        $this->assertNotSame($original, $fresh, 'resend must not reuse the stale code');

        // The stale one is dead...
        $this->submitCode($original)->assertSessionHasErrors('otp');

        // ...and the new one works on its own fresh window.
        $this->submitCode((string) $fresh)->assertRedirect(route('admin.new-password'));
    }

    public function test_the_email_tells_the_user_the_new_window(): void
    {
        $admin = $this->admin();

        $html = (new \App\Mail\PasswordResetCode($admin, '123456', self::LIFETIME))
            ->render();

        $this->assertStringContainsString('expires in ' . self::LIFETIME . ' minutes', $html);
        $this->assertStringNotContainsString('expires in 15 minutes', $html);
    }
}
