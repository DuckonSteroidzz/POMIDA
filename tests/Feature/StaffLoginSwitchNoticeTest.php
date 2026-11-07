<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\OneBrowser;
use Tests\TestCase;

/**
 * One browser, one staff login, and never a silent switch (QA, 6 Nov 2026).
 *
 * QA opened two windows of one browser profile, signed in as Admin in one and
 * Supervisor in the other, refreshed, and the Admin window had become the
 * Supervisor. Both windows share one cookie jar, so that is expected. What was
 * wrong is that it happened silently.
 *
 * Pinned here:
 *   1. EVIDENCE: a stale Admin window can not perform an admin-only action once
 *      another window has signed in as a Supervisor. The server acts as the
 *      session's CURRENT user, so the role check refuses it, and nothing a stale
 *      window submits is attributed to the new account.
 *   2. The login page, with somebody already signed in, offers Continue or Log
 *      out and switch; a login over a signed-in account is refused; switching
 *      needs the logout first, and the login itself still regenerates the session.
 *   3. Every other open staff tab can learn about a change or a logout (the
 *      status endpoint, the account stamp, the notice) and a stale tab's form is
 *      refused with a plain sentence rather than the 419 card.
 *
 * Every test runs as one real browser (Concerns\OneBrowser): nothing survives
 * between requests except the session cookie. "Another tab" is the same jar.
 * CSRF is skipped for the whole suite; tests that need it call enforceCsrf(),
 * exactly the way CsrfExpiryIdleLoginTest does. The notice and the reload
 * themselves are browser behaviour, verified in Playwright.
 */
class StaffLoginSwitchNoticeTest extends TestCase
{
    use DatabaseTransactions;
    use OneBrowser;

    private const PASSWORD = 'Switch!Notice1x';

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

    // ══════════ fixtures ══════════

    private function account(string $role, ?string $name = null): User
    {
        return User::create([
            'name'              => $name ?? ('Switch ' . ucfirst($role)),
            'email'             => 'switch-' . $role . '-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'          => self::PASSWORD,
            'role'              => $role,
            'is_active'         => true,
            'branch_id'         => $role === 'admin' ? null : 1,
            'email_verified_at' => now(),
        ]);
    }

    private function login(User $user, string $browser = 'tester', string $password = self::PASSWORD): TestResponse
    {
        Cache::flush();

        return $this->browser(fn () => $this->post('/admin/login', [
            'email'    => $user->email,
            'password' => $password,
        ]), $browser);
    }

    private function signIn(User $user, string $browser = 'tester'): void
    {
        $this->login($user, $browser)->assertRedirect(route('admin.home'));
        $this->assertTrue($this->hasLogin('admin'), "{$user->role} login must succeed");
    }

    private function logout(string $browser = 'tester'): TestResponse
    {
        return $this->browser(fn () => $this->post('/admin/logout'), $browser);
    }

    /** The other tab's way to change who is signed in: log out, then log in. */
    private function switchTo(User $user, string $browser = 'tester'): void
    {
        $this->logout($browser);
        $this->signIn($user, $browser);
    }

    private function page(string $uri, array $headers = [], string $browser = 'tester'): TestResponse
    {
        return $this->browser(fn () => $this->withHeaders($headers)->get($uri), $browser);
    }

    /** @return array{id:int,name:string,role:string,label:string}|null */
    private function pageAccount(TestResponse $page): ?array
    {
        preg_match('/<meta name="staff-account" content="([^"]*)">/', $page->getContent(), $m);

        return ($m[1] ?? '') === '' ? null : json_decode(html_entity_decode($m[1]), true);
    }

    private function pageToken(TestResponse $page): string
    {
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page->getContent(), $m);

        return $m[1] ?? '';
    }

    private function enforceCsrf(): void
    {
        $this->app['env'] = 'production';
        $this->assertFalse($this->app->runningUnitTests(), 'CSRF still skipped; this test would prove nothing');
    }

    private function branch(): Branch
    {
        return Branch::create([
            'name'      => 'SWITCH ' . uniqid(),
            'code'      => 'SW' . strtoupper(substr(uniqid(), -7)),
            'address'   => 'switch test',
            'is_active' => true,
        ]);
    }

    private function pendingOrder(): Order
    {
        return Order::create([
            'order_number'   => 'SWN-' . strtoupper(substr(uniqid(), -8)),
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);
    }

    /** What the Admin window's page said about itself, before the other window acted. */
    private function adminWindow(User $owner): array
    {
        $this->signIn($owner);
        $page = $this->page('/admin/branches')->assertOk();

        return [$this->pageAccount($page), $this->pageToken($page)];
    }

    // ══════════ 1. EVIDENCE: a stale Admin window can not act ══════════

    public function test_a_stale_admin_window_cannot_do_an_admin_only_action_after_a_supervisor_signs_in(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $supervisor = $this->account('supervisor', 'Switch Supervisor');
        $branch = $this->branch();

        [$stale, $staleToken] = $this->adminWindow($owner);
        $this->assertSame((int) $owner->id, $stale['id'], 'setup: the Admin window knows it is the Admin');

        $this->switchTo($supervisor);

        // The branch the Admin window shows is Owner-only. The server uses the
        // session's CURRENT user, so the Supervisor's role is what is checked.
        // Three ways a stale window can submit it, none of them changes anything.

        // (a) What an old page does: no stamp, a current token (the token a
        //     stale window may have been handed on focus).
        $fresh = $this->pageToken($this->page('/admin/home'));
        $res = $this->browser(fn () => $this->put('/admin/branches/' . $branch->id . '/toggle', ['_token' => $fresh]));
        $res->assertRedirect(route('admin.home'));
        $this->assertTrue($branch->fresh()->is_active, 'an admin-only action ran as the Supervisor');

        $this->browser(fn () => $this->post('/admin/branches', ['_token' => $fresh, 'name' => 'Stale Made ' . uniqid(), 'address' => 'x']))
            ->assertRedirect(route('admin.home'));
        $this->assertSame(0, Branch::where('name', 'like', 'Stale Made %')->count(), 'a stale window created a branch');

        // (b) The page the Admin window really sends: stamped with the Admin.
        $res = $this->browser(fn () => $this->put('/admin/branches/' . $branch->id . '/toggle', [
            '_token'  => $fresh,
            '_staff_acct' => (string) $stale['id'],
        ]));
        $res->assertRedirect(route('admin.home'))->assertSessionHas('account_notice');
        $this->assertTrue($branch->fresh()->is_active);

        // (c) With CSRF on, as in a real browser: the token the Supervisor's
        //     sign-in rotated away is refused, and the Admin window is told so.
        $this->enforceCsrf();
        $res = $this->browser(fn () => $this->put('/admin/branches/' . $branch->id . '/toggle', [
            '_token'      => $staleToken,
            '_staff_acct' => (string) $stale['id'],
        ]));
        $this->assertNotSame(419, $res->getStatusCode(), 'the Admin window got the raw 419 card');
        $res->assertRedirect(route('admin.home'));
        $this->assertTrue($branch->fresh()->is_active, 'a stale token still toggled the branch');
    }

    public function test_nothing_a_stale_window_submits_is_attributed_to_the_new_account(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $supervisor = $this->account('supervisor', 'Switch Supervisor');
        $order = $this->pendingOrder();

        [$stale] = $this->adminWindow($owner);
        $this->switchTo($supervisor);

        // Cancelling an order IS something a Supervisor may do. Before this
        // change the Admin window's click would simply have run as the
        // Supervisor. Now the stamp says the page belonged to someone else.
        $this->browser(fn () => $this->put('/admin/orders/' . $order->id . '/cancel', ['_staff_acct' => (string) $stale['id']]))
            ->assertRedirect(route('admin.home'))
            ->assertSessionHas('account_notice');

        $this->assertSame('pending', $order->fresh()->status, "the Admin window's click ran as the Supervisor");

        // The same click from a page drawn for the Supervisor goes through.
        $page = $this->page('/admin/home')->assertOk();
        $mine = $this->pageAccount($page);
        $this->assertSame((int) $supervisor->id, $mine['id']);

        $this->browser(fn () => $this->put('/admin/orders/' . $order->id . '/cancel', ['_staff_acct' => (string) $mine['id']]));
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_a_stale_fetch_gets_a_409_naming_the_current_account(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $supervisor = $this->account('supervisor', 'Switch Supervisor');
        $order = $this->pendingOrder();

        [$stale] = $this->adminWindow($owner);
        $this->switchTo($supervisor);

        $res = $this->browser(fn () => $this
            ->withHeaders(['X-Staff-Account' => (string) $stale['id'], 'Accept' => 'application/json', 'Sec-Fetch-Mode' => 'cors'])
            ->putJson('/admin/orders/' . $order->id . '/cancel'));

        $res->assertStatus(409)
            ->assertHeader('X-Account-Changed', '1')
            ->assertJson([
                'success'         => false,
                'account_changed' => true,
                'account'         => ['id' => (int) $supervisor->id, 'name' => 'Switch Supervisor', 'role' => 'supervisor', 'label' => 'Supervisor'],
            ]);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_the_stamp_is_only_checked_on_state_changing_requests_and_never_without_one(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $supervisor = $this->account('supervisor', 'Switch Supervisor');

        [$stale] = $this->adminWindow($owner);
        $this->switchTo($supervisor);

        // Reading a page as the current account was always safe, stale stamp or not.
        $this->page('/admin/home', ['X-Staff-Account' => (string) $stale['id']])->assertOk();

        // A page that sends no stamp is judged exactly as before: it acts as the
        // current account, within that account's role.
        $order = $this->pendingOrder();
        $this->browser(fn () => $this->put('/admin/orders/' . $order->id . '/cancel'));
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    // ══════════ 2. the login page ══════════

    public function test_the_login_page_offers_continue_or_logout_when_somebody_is_signed_in(): void
    {
        $supervisor = $this->account('supervisor', 'Maria Cruz');
        $this->signIn($supervisor);

        $page = $this->page('/admin/login')->assertOk();
        $html = $page->getContent();

        $this->assertStringContainsString("You're signed in as <strong>Maria Cruz</strong> (Supervisor).", $html);
        $this->assertStringContainsString('Continue as Maria Cruz, or log out to sign in with a different account.', $html);

        $this->assertMatchesRegularExpression('/<a\s[^>]*href="' . preg_quote(route('admin.home'), '/') . '"[^>]*data-testid="signed-in-continue"/s', $html);
        $this->assertMatchesRegularExpression('/<form action="' . preg_quote(route('admin.logout'), '/') . '" method="POST">.*?Log out and switch/s', $html);

        // No form that could sign another account over this one.
        $this->assertStringNotContainsString('id="loginForm"', $html);
        $this->assertStringNotContainsString('name="password"', $html);
        $this->assertStringNotContainsString('Create Administrator Account', $html);

        // And the page itself is drawn for that account, so it follows changes.
        $this->assertSame('Maria Cruz', $this->pageAccount($page)['name']);
    }

    public function test_the_login_page_is_the_normal_form_when_nobody_is_signed_in(): void
    {
        $page = $this->page('/admin/login')->assertOk();

        $page->assertSee('id="loginForm"', false)->assertSee('name="password"', false);
        $this->assertStringNotContainsString('data-testid="signed-in-choice"', $page->getContent());
        $this->assertNull($this->pageAccount($page), 'a login page with nobody signed in is drawn for no account');
    }

    public function test_a_customer_signed_in_on_the_same_browser_does_not_count_as_a_staff_login(): void
    {
        $customer = User::create([
            'name' => 'Switch Customer', 'email' => 'switch-cust-' . Str::lower(Str::random(10)) . '@invalid.local',
            'password' => self::PASSWORD, 'role' => 'customer', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        Cache::flush();
        $this->browser(fn () => $this->post('/customer/login', ['email' => $customer->email, 'password' => self::PASSWORD]))->assertRedirect();
        $this->assertTrue($this->hasLogin('customer'));

        $page = $this->page('/admin/login')->assertOk();
        $page->assertSee('id="loginForm"', false);
        $this->assertNull($this->pageAccount($page));

        // And staff signing in leaves the customer alone, as before.
        $this->signIn($this->account('staff'));
        $this->assertTrue($this->hasLogin('customer'), 'a staff login disturbed the customer');
    }

    public function test_a_login_over_a_signed_in_account_is_refused_and_never_switches_silently(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $supervisor = $this->account('supervisor', 'Switch Supervisor');

        $this->signIn($owner);

        // Right password: refused anyway.
        $res = $this->login($supervisor);
        $res->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('session');
        $this->assertStringContainsString(
            "You're already signed in as Switch Owner (Admin). Log out first to sign in with a different account.",
            session('errors')->first('session')
        );
        $this->assertSame((int) $owner->id, (int) $this->app['auth']->guard('admin')->id(), 'the Supervisor was signed in over the Admin');

        // Wrong password: the same answer, so no credential was even checked.
        $res = $this->login($supervisor, 'tester', 'not-the-password');
        $res->assertRedirect(route('admin.login'))->assertSessionHasErrors('session');
        $this->assertSame((int) $owner->id, (int) $this->sessionData()[$this->app['auth']->guard('admin')->getName()]);

        // The same account again: also no second login.
        $this->login($owner)->assertRedirect(route('admin.login'))->assertSessionHasErrors('session');

        // The page that comes back is the choice, naming who is signed in.
        $this->page('/admin/login')->assertSee('data-testid="signed-in-choice"', false)->assertSee('Switch Owner');
    }

    public function test_switching_needs_the_logout_first_and_the_login_still_regenerates_the_session(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $supervisor = $this->account('supervisor', 'Switch Supervisor');

        $this->signIn($owner);

        $this->logout()->assertRedirect(route('admin.login'));
        $this->assertFalse($this->hasLogin('admin'));

        $beforeLogin = $this->jar['tester'];
        $this->signIn($supervisor);

        $this->assertNotSame($beforeLogin, $this->jar['tester'], 'a login must regenerate the session id (fixation)');
        $this->assertSame((int) $supervisor->id, (int) $this->sessionData()[$this->app['auth']->guard('admin')->getName()]);
        $this->assertSame('Switch Supervisor', $this->pageAccount($this->page('/admin/home'))['name']);
    }

    public function test_a_stale_login_form_submitted_while_signed_in_says_so_instead_of_session_expired(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $supervisor = $this->account('supervisor', 'Switch Supervisor');

        // The login page the second window drew BEFORE the first one signed in.
        $stale = $this->pageToken($this->page('/admin/login'));
        $this->signIn($owner);

        $this->enforceCsrf();
        $res = $this->from('/admin/login')->browser(fn () => $this->post('/admin/login', [
            '_token' => $stale, 'email' => $supervisor->email, 'password' => self::PASSWORD,
        ]));

        $res->assertRedirect(route('admin.login'))->assertSessionHasErrors('session');
        $this->assertStringContainsString("already signed in as Switch Owner (Admin)", session('errors')->first('session'));
        $this->assertStringNotContainsString('expired', session('errors')->first('session'));
    }

    // ══════════ 3. the other tabs ══════════

    public function test_the_status_endpoint_tells_a_tab_who_is_signed_in_or_that_nobody_is(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $supervisor = $this->account('supervisor', 'Switch Supervisor');

        // Open to a signed-out browser, which answers "nobody" rather than a redirect.
        $nobody = $this->page('/admin/account-status', ['Accept' => 'application/json'])->assertOk();
        $nobody->assertExactJson(['signed_in' => false, 'account' => null]);
        $this->assertStringContainsString('no-store', (string) $nobody->headers->get('Cache-Control'));

        $this->signIn($owner);
        $this->page('/admin/account-status', ['Accept' => 'application/json'])->assertOk()->assertExactJson([
            'signed_in' => true,
            'account'   => ['id' => (int) $owner->id, 'name' => 'Switch Owner', 'role' => 'admin', 'label' => 'Admin'],
        ]);

        // The other tab switches the account: this one's next poll sees it.
        $this->switchTo($supervisor);
        $this->page('/admin/account-status', ['Accept' => 'application/json'])->assertJsonPath('account.id', (int) $supervisor->id);

        // And logs out: signed_in goes false.
        $this->logout();
        $this->page('/admin/account-status', ['Accept' => 'application/json'])->assertExactJson(['signed_in' => false, 'account' => null]);
    }

    public function test_the_status_endpoint_gives_away_nothing_to_a_browser_with_no_staff_login(): void
    {
        $this->signIn($this->account('staff', 'Switch Staff'));

        // A different browser has its own cookie jar.
        $this->page('/admin/account-status', ['Accept' => 'application/json'], 'someone-else')
            ->assertExactJson(['signed_in' => false, 'account' => null]);
    }

    public function test_every_staff_page_is_drawn_for_its_account_so_it_can_notice_a_change(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $this->signIn($owner);

        foreach (['/admin/home', '/admin/completed-orders', '/admin/branches'] as $path) {
            $page = $this->page($path)->assertOk();
            $this->assertSame(
                ['id' => (int) $owner->id, 'name' => 'Switch Owner', 'role' => 'admin', 'label' => 'Admin'],
                $this->pageAccount($page),
                "$path is not drawn for the signed-in account"
            );
            $page->assertSee('peachy-staff-account', false);
        }
    }

    public function test_a_token_refresh_is_not_handed_to_a_page_drawn_for_a_different_account(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $supervisor = $this->account('supervisor', 'Switch Supervisor');

        [$stale] = $this->adminWindow($owner);

        // Still the same account: a token, as always.
        $this->page('/admin/session-token?acct=' . $stale['id'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonStructure(['token']);
        // No account named (a login page, a customer page): unchanged.
        $this->page('/admin/session-token', ['Accept' => 'application/json'])->assertJsonStructure(['token']);

        $this->switchTo($supervisor);

        // The Admin window asks as the Admin: no token, and told who is signed in.
        $res = $this->page('/admin/session-token?acct=' . $stale['id'], ['Accept' => 'application/json'])->assertOk();
        $res->assertJson(['account_changed' => true, 'account' => ['id' => (int) $supervisor->id, 'label' => 'Supervisor']]);
        $this->assertArrayNotHasKey('token', $res->json(), 'a stale window was handed the new account\'s token');

        // Logged out: told nobody is signed in.
        $this->logout();
        $this->page('/admin/session-token?acct=' . $stale['id'], ['Accept' => 'application/json'])
            ->assertJson(['account_changed' => true, 'account' => null]);
    }

    public function test_a_stale_form_after_a_switch_is_refused_with_a_sentence_not_the_419_card(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $supervisor = $this->account('supervisor', 'Switch Supervisor');
        $order = $this->pendingOrder();

        [$stale, $staleToken] = $this->adminWindow($owner);
        $this->switchTo($supervisor);

        $this->enforceCsrf();
        $res = $this->browser(fn () => $this->put('/admin/orders/' . $order->id . '/cancel', [
            '_token' => $staleToken, '_staff_acct' => (string) $stale['id'],
        ]));

        $res->assertRedirect(route('admin.home'));
        $this->assertSame(
            'This browser is now signed in as Switch Supervisor (Supervisor), so the page you used belonged to a different account. Nothing you submitted was saved.',
            session('account_notice')
        );
        $this->assertSame('pending', $order->fresh()->status);

        // The sentence is on the page they land on, and stays until closed.
        $this->app['env'] = 'testing';
        $landing = $this->browser(fn () => $this->withSession(['account_notice' => session('account_notice')])->get('/admin/home'))->assertOk();
        $landing->assertSee('data-testid="account-notice"', false)->assertSee('Nothing you submitted was saved.');
    }

    public function test_a_stale_form_after_a_logout_goes_to_the_login_page_with_a_sentence(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $order = $this->pendingOrder();

        [$stale, $staleToken] = $this->adminWindow($owner);
        $this->logout();

        $this->enforceCsrf();
        $res = $this->browser(fn () => $this->put('/admin/orders/' . $order->id . '/cancel', [
            '_token' => $staleToken, '_staff_acct' => (string) $stale['id'],
        ]));

        $res->assertRedirect(route('admin.login'))->assertSessionHasErrors('session');
        $this->assertStringContainsString('You have been signed out', session('errors')->first('session'));
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_csrf_still_refuses_a_bad_token_and_a_request_with_no_stamp_keeps_the_419(): void
    {
        $owner = $this->account('admin', 'Switch Owner');
        $order = $this->pendingOrder();

        [$stamp] = $this->adminWindow($owner);

        $this->enforceCsrf();

        // Same account, wrong token: refused, nothing saved (as before).
        $this->browser(fn () => $this->put('/admin/orders/' . $order->id . '/cancel', [
            '_token' => 'not-the-token', '_staff_acct' => (string) $stamp['id'],
        ]));
        $this->assertSame('pending', $order->fresh()->status, 'CSRF stopped protecting a stamped form');

        // No stamp at all (a client that is not our page): the plain 419, unchanged.
        $this->browser(fn () => $this->put('/admin/orders/' . $order->id . '/cancel', ['_token' => 'not-the-token']))
            ->assertStatus(419);

        // A fetch keeps its JSON 419 too.
        $this->browser(fn () => $this->putJson('/admin/orders/' . $order->id . '/cancel', ['_token' => 'not-the-token']))
            ->assertStatus(419);
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ══════════ the page script (behaviour is verified in Playwright) ══════════

    private function watch(): string
    {
        return file_get_contents(resource_path('views/admin/partials/account-watch.blade.php'));
    }

    public function test_the_layout_and_the_login_page_both_carry_the_watcher(): void
    {
        foreach (['admin/layout.blade.php', 'admin/login.blade.php'] as $view) {
            $this->assertStringContainsString(
                "@include('admin.partials.account-watch')",
                file_get_contents(resource_path('views/' . $view)),
                "$view does not watch for an account change"
            );
        }
    }

    public function test_the_watcher_says_what_the_report_asked_it_to_say_and_uses_the_apps_one_poller(): void
    {
        $js = $this->watch();

        $this->assertStringContainsString(
            "'This browser is now signed in as ' + account.name + ' (' + account.label + '). The page will reload.'",
            $js
        );
        $this->assertStringContainsString('window.location.reload()', $js);
        $this->assertStringContainsString('window.location.href = LOGIN_URL', $js, 'a logout must send the other tabs to the login page');

        // The same-browser signal, with its fallback, and the slow safety net.
        $this->assertStringContainsString('new window.BroadcastChannel(CHANNEL)', $js);
        $this->assertStringContainsString("addEventListener('storage'", $js);
        $this->assertStringContainsString('PomidaPollChain({', $js);
        $this->assertStringContainsString("addEventListener('peachy:staff-account-changed'", $js);

        // No bare timer driving a network call (see LivePollingTest).
        $this->assertSame(0, substr_count($js, 'setInterval('));

        // The stamp goes on forms, on form.submit() and on fetch.
        $this->assertStringContainsString("document.addEventListener('submit'", $js);
        $this->assertStringContainsString('HTMLFormElement.prototype.submit = function', $js);
        $this->assertStringContainsString('headers.set(HEADER', $js);
    }

    public function test_the_token_refresh_in_the_session_guard_asks_as_the_account_it_was_drawn_for(): void
    {
        $guard = file_get_contents(resource_path('views/partials/session-guard.blade.php'));

        $this->assertStringContainsString("TOKEN_URL + '?acct=' + encodeURIComponent(acct)", $guard);
        $this->assertStringContainsString("new CustomEvent('peachy:staff-account-changed'", $guard);
        // It must stop there: no token is applied when the account changed.
        $this->assertMatchesRegularExpression('/account_changed\)\s*\{.*?return;\s*\}/s', $guard);
    }
}
