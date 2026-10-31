<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\OneBrowser;
use Tests\TestCase;

/**
 * Defect Report 6, problem 2: "I'm logged in on a customer account, but when I
 * log out of the staff side, the customer becomes a dine-in account."
 *
 * Customer and staff in two tabs of one browser share one Laravel session. Every
 * logout used to flush all of it, which signed the other side out too. The
 * customer came back with no order_type, and the cart's default then rendered
 * them as Dine-In. See App\Support\SessionSide.
 *
 * Every test runs as one real browser (Concerns\OneBrowser): nothing survives
 * between requests except the session cookie.
 */
class SessionSideIndependenceTest extends TestCase
{
    use DatabaseTransactions;
    use OneBrowser;

    private const PASSWORD = 'Defect6!Sides1';

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

    private function account(string $role): User
    {
        return User::create([
            'name'              => 'Side ' . ucfirst($role),
            'email'             => 'side-' . $role . '-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'          => self::PASSWORD,
            'role'              => $role,
            'is_active'         => true,
            'branch_id'         => $role === 'customer' ? null : 1,
            'email_verified_at' => now(),
        ]);
    }

    private function signIn(User $user, string $browser = 'tester'): void
    {
        Cache::flush();

        $portal = $user->role === 'customer' ? 'customer' : 'admin';

        $this->browser(fn () => $this->post("/{$portal}/login", [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ]), $browser)->assertRedirect();

        $this->assertTrue($this->hasLogin($portal === 'customer' ? 'customer' : 'admin'), "{$user->role} login must succeed first");
    }

    /** A Pick-Up customer with a branch and a cart, then staff in the other tab. */
    private function customerAndStaffInOneBrowser(string $portalRole = 'staff'): array
    {
        $customer = $this->account('customer');
        $staff = $this->account($portalRole);

        $this->signIn($customer);
        $this->browser(fn () => $this->post('/customer/select-branch', ['branch_id' => 1]));
        $this->browser(fn () => $this->withSession(['cart' => ['item' => ['qty' => 1]]])->get('/customer/more'))->assertOk();

        $this->signIn($staff);

        $this->assertTrue($this->hasLogin('customer'), 'a staff login must not disturb the customer');

        return [$customer, $staff];
    }

    private function logoutStaff(): TestResponse
    {
        return $this->browser(fn () => $this->post('/admin/logout'));
    }

    private function logoutCustomer(): TestResponse
    {
        return $this->browser(fn () => $this->post('/customer/logout'));
    }

    private function customerPage(): TestResponse
    {
        return $this->browser(fn () => $this->get('/customer/more'));
    }

    private function staffPage(): TestResponse
    {
        return $this->browser(fn () => $this->get('/admin/home'));
    }

    // ══════════ logging out of staff ══════════

    public function test_staff_logout_keeps_the_customer_signed_in_and_not_dine_in(): void
    {
        $this->customerAndStaffInOneBrowser();

        $this->logoutStaff()->assertRedirect(route('admin.login'));

        $data = $this->sessionData();
        $this->assertFalse($this->hasLogin('admin'));
        $this->assertTrue($this->hasLogin('customer'), 'the customer was signed out by a staff logout');
        $this->assertSame('pick_up', $data['order_type'] ?? null, 'the customer lost their order type');
        $this->assertSame(1, (int) ($data['branch_id'] ?? 0), 'the customer lost their branch');
        $this->assertNotEmpty($data['cart'] ?? null, 'the customer lost their cart');

        // The real pages, as the customer's tab would load them.
        $this->customerPage()->assertOk()->assertSee('Manage your account')->assertDontSee('Get more from your orders');

        $this->browser(fn () => $this->withSession(['cart' => []])->get('/customer/cart'))
            ->assertOk()
            ->assertDontSee('name="is_takeout"', false);

        $this->assertSame('pick_up', $this->sessionData()['order_type'] ?? null);
    }

    public function test_staff_logout_removes_every_staff_key_and_leaves_no_staff_access(): void
    {
        // An admin: the Viewing branch is not open to a branch-locked role.
        $this->customerAndStaffInOneBrowser('admin');

        // The Viewing branch is the staff portal's own session key.
        $this->browser(fn () => $this->get('/admin/branches/select/all'));
        $this->assertArrayHasKey('selected_branch_id', $this->sessionData());
        $this->assertArrayHasKey('admin_sign_in', $this->sessionData());

        $this->logoutStaff();

        $data = $this->sessionData();
        $this->assertArrayNotHasKey('selected_branch_id', $data);
        $this->assertArrayNotHasKey('admin_sign_in', $data);
        $this->assertArrayNotHasKey('admin', $data['single_session'] ?? []);
        $this->assertArrayHasKey('customer', $data['single_session'] ?? [], "the customer's single-session fingerprint must survive");

        $this->staffPage()->assertRedirect(route('admin.login'));
    }

    public function test_staff_logout_still_rotates_the_session_id_and_csrf_token(): void
    {
        $this->customerAndStaffInOneBrowser();

        $idBefore = $this->jar['tester'];
        $tokenBefore = $this->sessionData()['_token'];

        $this->logoutStaff();

        $this->assertNotSame($idBefore, $this->jar['tester'], 'the session id survived the logout');
        $this->assertNotSame($tokenBefore, $this->sessionData()['_token'], 'the CSRF token survived the logout');

        // The old id is dead: a copy of the old cookie is nobody.
        $this->jar['copy'] = $idBefore;
        $this->browser(fn () => $this->get('/admin/home'), 'copy')->assertRedirect(route('admin.login'));
        $this->assertFalse($this->hasLogin('customer'), 'a copied old cookie still held the customer login');
    }

    public function test_staff_logout_with_a_dine_in_guest_in_the_other_tab_keeps_their_table(): void
    {
        $this->seatAtTable('9601');
        $tableToken = $this->sessionData()[TableOccupancy::SESSION_KEY] ?? null;
        $this->assertNotNull($tableToken, 'the guest never held a table session');

        $this->signIn($this->account('staff'));
        $this->logoutStaff();

        $data = $this->sessionData();
        $this->assertSame($tableToken, $data[TableOccupancy::SESSION_KEY] ?? null);
        $this->assertSame('dine_in', $data['order_type'] ?? null);
        $this->assertSame('9601', (string) ($data['table_number'] ?? ''));
    }

    // ══════════ logging out of the customer side ══════════

    public function test_customer_logout_keeps_staff_signed_in(): void
    {
        $this->customerAndStaffInOneBrowser('admin');
        $this->browser(fn () => $this->get('/admin/branches/select/1'));

        $this->logoutCustomer()->assertRedirect(route('home'));

        $data = $this->sessionData();
        $this->assertFalse($this->hasLogin('customer'));
        $this->assertTrue($this->hasLogin('admin'), 'staff were signed out by a customer logout');
        $this->assertArrayHasKey('selected_branch_id', $data, "staff lost their Viewing branch");
        $this->assertArrayHasKey('admin_sign_in', $data);
        $this->assertArrayHasKey('admin', $data['single_session'] ?? [], "staff's single-session fingerprint must survive");

        $this->staffPage()->assertOk();
    }

    public function test_customer_logout_removes_the_customer_side_including_keys_nobody_listed(): void
    {
        $this->customerAndStaffInOneBrowser();
        $this->browser(fn () => $this->withSession(['some_future_customer_key' => 'x'])->get('/customer/more'));

        $idBefore = $this->jar['tester'];
        $tokenBefore = $this->sessionData()['_token'];

        $this->logoutCustomer();

        $data = $this->sessionData();
        foreach (['order_type', 'branch_id', 'cart', 'some_future_customer_key'] as $key) {
            $this->assertArrayNotHasKey($key, $data, "{$key} outlived the customer logout");
        }
        $this->assertArrayNotHasKey('customer', $data['single_session'] ?? []);

        $this->assertNotSame($idBefore, $this->jar['tester'], 'the session id survived the logout');
        $this->assertNotSame($tokenBefore, $data['_token'], 'the CSRF token survived the logout');

        $this->browser(fn () => $this->get('/customer/account'))->assertRedirect();
        $this->assertFalse($this->hasLogin('customer'));
    }

    // ══════════ idle logout, staff-cleared table, forced logouts ══════════

    public function test_idle_logout_of_a_customer_keeps_staff_signed_in(): void
    {
        $this->customerAndStaffInOneBrowser();

        $idBefore = $this->jar['tester'];

        $this->browser(fn () => $this->postJson('/customer/idle-logout'))->assertOk();

        $this->assertFalse($this->hasLogin('customer'));
        $this->assertArrayNotHasKey('cart', $this->sessionData());
        $this->assertTrue($this->hasLogin('admin'), 'staff are not idle because a customer tab was');
        $this->assertNotSame($idBefore, $this->jar['tester']);
        $this->staffPage()->assertOk();
    }

    public function test_idle_logout_of_a_dine_in_guest_keeps_staff_signed_in(): void
    {
        $this->seatAtTable('9602');
        $this->signIn($this->account('staff'));

        $this->browser(fn () => $this->postJson('/customer/idle-logout'))->assertOk();

        $this->assertArrayNotHasKey(TableOccupancy::SESSION_KEY, $this->sessionData());
        $this->assertTrue($this->hasLogin('admin'));
    }

    public function test_a_staff_cleared_table_touches_neither_login(): void
    {
        $customer = $this->account('customer');
        $staff = $this->account('staff');

        $this->signIn($customer);
        $this->seatAtTable('9603');
        $this->signIn($staff);

        $this->browser(fn () => $this->postJson('/admin/tables/clear', ['branch_id' => 1, 'table_number' => '9603']))->assertOk();
        $this->browser(fn () => $this->getJson('/customer/table-session-status'))->assertOk()->assertJson(['valid' => false]);

        $data = $this->sessionData();
        $this->assertTrue($this->hasLogin('customer'), 'a staff clear must not log the customer out');
        $this->assertTrue($this->hasLogin('admin'), 'a staff clear must not log staff out');
        $this->assertArrayNotHasKey('order_type', $data);
        $this->assertArrayNotHasKey('table_number', $data);

        // The customer now has no order type. They are not a Dine-In guest.
        $this->browser(fn () => $this->get('/customer/cart'))->assertOk()->assertDontSee('name="is_takeout"', false);
    }

    public function test_a_deactivated_staff_account_is_forced_out_and_the_customer_stays(): void
    {
        [, $staff] = $this->customerAndStaffInOneBrowser();

        $staff->forceFill(['is_active' => false])->save();

        $this->staffPage()->assertRedirect(route('admin.login'));

        $this->assertFalse($this->hasLogin('admin'));
        $this->assertTrue($this->hasLogin('customer'));
        $this->assertSame('pick_up', $this->sessionData()['order_type'] ?? null);
    }

    public function test_a_staff_login_ended_on_another_device_leaves_the_customer_signed_in(): void
    {
        [, $staff] = $this->customerAndStaffInOneBrowser();

        $this->signIn($staff, 'counter-pc');

        $this->staffPage()->assertRedirect(route('admin.login', ['signed_out' => 'other-device']));

        $this->assertTrue($this->hasLogin('customer'));
        $this->customerPage()->assertOk()->assertSee('Manage your account');
    }

    public function test_a_customer_login_ended_on_another_device_leaves_staff_signed_in(): void
    {
        [$customer] = $this->customerAndStaffInOneBrowser();

        $this->signIn($customer, 'phone');

        $this->customerPage()->assertRedirect(route('customer.login', ['signed_out' => 'other-device']));

        $this->assertTrue($this->hasLogin('admin'));
        $this->assertArrayNotHasKey('order_type', $this->sessionData());
        $this->staffPage()->assertOk();
    }

    /**
     * A poll from the staff tab must not move THAT tab to the customer login,
     * and the customer tab must still be told what happened.
     */
    public function test_a_poll_from_the_other_side_does_not_get_this_sides_redirect(): void
    {
        [$customer] = $this->customerAndStaffInOneBrowser();

        $this->signIn($customer, 'phone');

        $poll = $this->browser(fn () => $this->withHeaders(['Sec-Fetch-Mode' => 'cors'])->get('/admin/home'));
        $poll->assertOk();
        $this->assertFalse($poll->headers->has('X-Session-Ended'), 'the staff tab was sent to the customer login');

        // Refused as soon as the guard was checked, not left for later.
        $this->assertFalse($this->hasLogin('customer'));
        $this->assertTrue($this->hasLogin('admin'));

        $this->customerPage()->assertRedirect(route('customer.login', ['signed_out' => 'other-device']));

        // Told once, then an ordinary visitor.
        $this->customerPage()->assertOk();
    }

    /** /discount-id reads the admin guard from outside /admin. */
    public function test_a_stale_staff_login_is_not_honoured_on_a_request_from_the_customer_side(): void
    {
        [, $staff] = $this->customerAndStaffInOneBrowser();

        $this->signIn($staff, 'counter-pc');

        $this->customerPage()->assertOk();

        $this->assertFalse($this->hasLogin('admin'), 'a superseded staff login outlived a customer-side request');
        $this->assertTrue($this->hasLogin('customer'));
        $this->staffPage()->assertRedirect(route('admin.login', ['signed_out' => 'other-device']));
    }

    // ══════════ no cross-access ══════════

    public function test_a_customer_session_never_reaches_the_staff_portal(): void
    {
        $this->signIn($this->account('customer'));

        $this->staffPage()->assertRedirect(route('admin.login'));
        $this->assertTrue($this->hasLogin('customer'));

        // Nor can it sign anybody out from that side.
        $this->logoutStaff()->assertRedirect(route('admin.login'));
        $this->assertTrue($this->hasLogin('customer'), 'an unauthorised staff logout ended the customer login');
    }

    public function test_a_staff_session_never_acts_as_a_customer(): void
    {
        $this->signIn($this->account('staff'));

        $this->browser(fn () => $this->get('/customer/account'))->assertRedirect();
        $this->customerPage()->assertOk()->assertDontSee('Manage your account');

        // Pressing a customer logout is a no-op for staff.
        $this->logoutCustomer();
        $this->assertTrue($this->hasLogin('admin'), 'a customer logout ended the staff login');
        $this->staffPage()->assertOk();
    }

    public function test_after_staff_logout_the_surviving_customer_cannot_reach_staff_pages(): void
    {
        $this->customerAndStaffInOneBrowser();
        $this->logoutStaff();

        $this->staffPage()->assertRedirect(route('admin.login'));
        $this->browser(fn () => $this->get('/admin/account'))->assertRedirect(route('admin.login'));
    }

    public function test_after_customer_logout_the_surviving_staff_session_has_no_customer_access(): void
    {
        $this->customerAndStaffInOneBrowser();
        $this->logoutCustomer();

        $this->browser(fn () => $this->get('/customer/account'))->assertRedirect();
        $this->customerPage()->assertOk()->assertDontSee('Manage your account');
    }

    // ══════════ the default that made it read as Dine-In ══════════

    /**
     * The cart's order-type default read Auth::check(), the default `web` guard
     * nobody signs in on, so even a signed-in customer defaulted to Dine-In.
     */
    public function test_a_signed_in_customer_with_no_order_type_is_not_rendered_as_dine_in(): void
    {
        $this->signIn($this->account('customer'));

        // A staff clear is the real way to lose order_type while signed in.
        $this->seatAtTable('9604');
        $this->signIn($this->account('staff'));
        $this->browser(fn () => $this->postJson('/admin/tables/clear', ['branch_id' => 1, 'table_number' => '9604']))->assertOk();
        $this->browser(fn () => $this->getJson('/customer/table-session-status'));
        $this->assertArrayNotHasKey('order_type', $this->sessionData());

        $html = $this->browser(fn () => $this->get('/customer/cart'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="is_takeout"', $html);

        // With something in the cart, the checkout form itself: this hidden
        // field is what would be posted as the order's type.
        $item = \App\Models\MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();

        $html = $this->browser(fn () => $this->withSession(['branch_id' => 1, 'cart' => [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => 1,
            'image'        => $item->image,
            'options'      => [],
        ]]])->get('/customer/cart'))->assertOk()->getContent();

        $this->assertStringContainsString('name="order_type" value="pick_up"', $html);
        $this->assertStringNotContainsString('name="is_takeout"', $html);
    }

    private function seatAtTable(string $table, int $branchId = 1): void
    {
        $code = TableEntry::findOrRegister($branchId, $table)->code;

        $this->browser(fn () => $this->get("/customer/menu?branch_id={$branchId}&table={$table}&k={$code}"))->assertOk();

        $this->assertNotNull(TableOccupancy::activeFor($branchId, $table), "seating at table {$table} did not open an occupancy");
    }
}
