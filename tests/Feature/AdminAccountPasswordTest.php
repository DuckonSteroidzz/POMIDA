<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Changing your own password from /admin/account.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * The "Change Password" button on that page did nothing at all when clicked:
 * no modal, no navigation, no error. It was a bare <button type="button"> with
 * no onclick, no form and no route behind it — the same "built on one side and
 * never connected" pattern as items 1, 2, 9 and 28.
 *
 * The Save button beside it was worse than dead: it posted to
 * AdminController::updateAccount(), whose entire body was
 *
 *     return redirect()->back()->with('success', 'Account updated successfully.');
 *
 * so it wrote nothing and said it had. Both are gone, replaced by a real
 * change-password form and updateOwnPassword().
 *
 * WHAT THIS MUST GUARANTEE
 * ------------------------
 * The endpoint changes the SIGNED-IN account and nothing else. It takes no user
 * id at all, which is what makes "neither role can change anyone else's from
 * this page" true by construction rather than by a check that could be missed.
 */
class AdminAccountPasswordTest extends TestCase
{
    use DatabaseTransactions;

    private const OLD = 'password123';
    private const NEW = 'Str0ngerPass!2026';

    protected function setUp(): void
    {
        parent::setUp();
        /*
         * This line does nothing, and is kept only so the next person does not
         * re-add it. `throttle:6,1` is the middleware ARGUMENT, not a limiter
         * name: ThrottleRequests::resolveRequestSignature() keys an
         * unauthenticated request on sha1(domain|ip) with an EMPTY prefix, so
         * there is no limiter called "throttle:6,1" to clear.
         *
         * The consequence is real. /admin/account/password is throttle:6,1, and
         * because the prefix is empty that counter is SHARED by every plain
         * throttle:X,Y route for the same IP. Within one PHPUnit process the
         * array cache store keeps it between tests, so a class that posts to
         * this endpoint a dozen times can start 429-ing partway through — which
         * showed up as an intermittent single failure once Pass 6 added more
         * tests against the same endpoint.
         *
         * Cache::flush() is what actually resets it, and is what the other
         * throttle-aware test classes here use.
         */
        RateLimiter::clear('throttle:6,1');
        Cache::flush();
    }

    /** A user of the given role with a password we know, restored by the transaction. */
    private function actor(string $role): User
    {
        $user = User::where('role', $role)->where('is_active', true)->firstOrFail();

        $user->forceFill(['password' => self::OLD])->save();

        return $user->fresh();
    }

    private function submit(User $actor, array $payload)
    {
        return $this->actingAs($actor, 'admin')
            ->from('/admin/account')
            ->put('/admin/account/password', $payload);
    }

    /**
     * ADMIN ONLY as of 2026-09-01 — 'staff' was deliberately removed.
     *
     * This provider used to include 'staff', and the four tests it drove were
     * correct at the time: a staff member could change their own password from
     * /admin/account, and these tests proved it worked.
     *
     * The owner has since decided that staff must have NO self-service password
     * path at all — the account page and its update endpoint are now
     * `role:admin`, and AdminAuthController::resettableRoles() no longer covers
     * staff either. So a staff member reaching this endpoint is refused, and
     * these tests were asserting behaviour that has been intentionally removed.
     *
     * They are narrowed rather than deleted: what they check about the ADMIN
     * path is still exactly right and still worth pinning.
     *
     * The staff side did not lose coverage — it moved and got stronger. See
     * StaffPasswordControlTest, which asserts staff are refused here, that the
     * refusal matches every other admin-only route, and that the admin can set
     * a staff password from /admin/users instead.
     */
    public static function roles(): array
    {
        return ['admin' => ['admin']];
    }

    // ══════════ the page and its controls ══════════

    /**
     * @dataProvider roles
     */
    public function test_the_account_page_offers_a_real_change_password_form(string $role): void
    {
        $html = $this->actingAs($this->actor($role), 'admin')
            ->get('/admin/account')
            ->assertOk()
            ->getContent();

        // This page is admin-only again as of 2026-09-01. It briefly served
        // staff too, so they could change their own password; the owner's
        // decision removed that. StaffPasswordControlTest covers the staff
        // side now — that they are refused here, and that the admin sets their
        // password from /admin/users instead.
        $this->assertStringContainsString('admin/account/password', $html);
        $this->assertStringContainsString('name="current_password"', $html);
        $this->assertStringContainsString('name="password_confirmation"', $html);
    }

    public function test_the_dead_save_and_duplicate_logout_are_gone(): void
    {
        $html = $this->actingAs($this->actor('admin'), 'admin')
            ->get('/admin/account')
            ->assertOk()
            ->getContent();

        // The mid-page Log Out duplicated the one in the sidebar, which is on
        // every page. Exactly one logout form must remain.
        $this->assertSame(
            1,
            substr_count($html, 'action="' . route('admin.logout') . '"'),
            'there is still more than one Log Out on this page'
        );

        // And the no-op Save route no longer exists at all.
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('admin.account.update'),
            'the no-op account update route is still registered'
        );
    }

    // ══════════ the happy path ══════════

    /**
     * @dataProvider roles
     */
    public function test_a_user_can_change_their_own_password(string $role): void
    {
        $actor = $this->actor($role);

        $response = $this->submit($actor, [
            'current_password'      => self::OLD,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('password_success');

        $actor->refresh();

        $this->assertTrue(Hash::check(self::NEW, $actor->password), 'the new password was not saved');
        $this->assertFalse(Hash::check(self::OLD, $actor->password));
    }

    public function test_the_new_password_actually_signs_the_user_in(): void
    {
        // Was 'staff' until 2026-09-01. Staff no longer have access to this
        // endpoint at all — see the note on roles() above — so the same
        // property is now proved with an admin.
        $actor = $this->actor('admin');

        $this->submit($actor, [
            'current_password'      => self::OLD,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertSessionHasNoErrors();

        // The password column is cast to "hashed"; hashing it a second time on
        // the way in would leave a value nothing can log in with.
        $this->assertTrue(Hash::check(self::NEW, $actor->fresh()->password));
    }

    // ══════════ the refusals ══════════

    public function test_the_wrong_current_password_is_refused(): void
    {
        $actor = $this->actor('admin');

        $this->submit($actor, [
            'current_password'      => 'not-my-password',
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check(self::OLD, $actor->fresh()->password));
    }

    public function test_a_short_password_is_refused(): void
    {
        $actor = $this->actor('admin');

        $this->submit($actor, [
            'current_password'      => self::OLD,
            'password'              => 'short1',
            'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD, $actor->fresh()->password));
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $actor = $this->actor('admin');

        $this->submit($actor, [
            'current_password'      => self::OLD,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW . 'x',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD, $actor->fresh()->password));
    }

    public function test_reusing_the_current_password_is_refused(): void
    {
        $actor = $this->actor('admin');

        $this->submit($actor, [
            'current_password'      => self::OLD,
            'password'              => self::OLD,
            'password_confirmation' => self::OLD,
        ])->assertSessionHasErrors('password');
    }

    public function test_a_signed_out_visitor_cannot_reach_the_endpoint(): void
    {
        $this->put('/admin/account/password', [
            'current_password'      => self::OLD,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertRedirect();
    }

    // ══════════ nobody else's password ══════════

    /**
     * @dataProvider roles
     */
    public function test_the_endpoint_only_ever_touches_the_signed_in_account(string $role): void
    {
        $actor = $this->actor($role);

        $others = User::where('id', '!=', $actor->id)->get();
        $before = $others->pluck('password', 'id');

        // There is no user id to pass, so this is the strongest form the test
        // can take: try to smuggle one in and confirm it is ignored.
        $victim = $others->firstWhere('id', '!=', $actor->id);

        $this->submit($actor, [
            'user_id'               => $victim?->id,
            'id'                    => $victim?->id,
            'email'                 => $victim?->email,
            'current_password'      => self::OLD,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertSessionHasNoErrors();

        foreach (User::whereIn('id', $before->keys())->get() as $other) {
            $this->assertSame(
                $before[$other->id],
                $other->password,
                "the password of user {$other->id} was changed by someone else"
            );
        }

        $this->assertTrue(Hash::check(self::NEW, $actor->fresh()->password));
    }
}
