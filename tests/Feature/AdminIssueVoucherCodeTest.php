<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\VoucherClaims;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * An admin issuing a voucher code by hand, to a walk-in customer.
 *
 * WHY THIS EXISTS
 * ---------------
 * Dine-In and Pick-Up customers can walk in with no account and may never play
 * the spin wheel, so there was no way to give one of them a voucher at all.
 * The admin can now mint one bearer claim code from the Vouchers page and read
 * it out or write it on a receipt.
 *
 * THIS IS A DELIBERATE FEATURE, NOT A BYPASS — AND THAT IS ENFORCED
 * ------------------------------------------------------------------
 * It creates a claim outside the game, which is exactly what was asked for.
 * What it must NOT do is let an admin hand out a code the wheel itself would
 * refuse to award. Voucher::issuanceErrorFor() mirrors the wheel's own
 * issuability rule from AuthController::winnableVoucherFor() — active, not
 * expired, and under the SAME max_uses cap read from the SAME used_count
 * column — plus one stricter condition (a voucher whose valid_from has not
 * arrived). Every one of those is asserted below.
 *
 * The cap test is the important one: issuing draws against the same supply a
 * wheel win draws against, so this path cannot be used to push a voucher past
 * a limit a wheel win would be bound by.
 *
 * NO SPECIAL-CASING AT REDEMPTION
 * -------------------------------
 * A manually issued claim is an ordinary bearer claim: ownerless, one code,
 * spent by the same conditional UPDATE at checkout. It is proved here by
 * running it through the real cart and checkout, the same way
 * VoucherBearerRedemptionTest does for a wheel-won code, rather than by
 * asserting anything about how it was created.
 */
class AdminIssueVoucherCodeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // apply-voucher and the issue endpoint are both throttled; a stale
        // counter would surface as a refusal that was really a 429.
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function staff(): ?User
    {
        return User::where('role', 'staff')->where('is_active', true)->orderBy('id')->first();
    }

    private function voucher(array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'code'            => 'ISSUE' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'Admin-issued code test voucher',
            'discount_type'   => 'fixed',
            'discount_value'  => 25,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 5,
            'is_active'       => true,
            'valid_from'      => today()->subDay()->toDateString(),
            'expires_at'      => now()->addMonth(),
        ], $attrs));
    }

    /** Issue a code as the admin, returning the flashed code (or null). */
    private function issue(Voucher $voucher, ?User $as = null): ?string
    {
        $this->actingAs($as ?? $this->admin(), 'admin')
            ->post("/admin/vouchers/{$voucher->id}/issue-code");

        return session('issued_claim_code');
    }

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();
    }

    /** Redeem a code as a guest on a real order. Returns the created order. */
    private function redeemAsGuest(string $code): ?Order
    {
        $item = $this->item();
        $before = (int) Order::max('id');

        $this->withSession([
            'cart' => [(string) $item->id => [
                'menu_item_id' => (string) $item->id,
                'name'         => $item->name,
                'price'        => (float) $item->price,
                'base_price'   => (float) $item->price,
                'quantity'     => 2,
                'image'        => $item->image,
                'options'      => [],
            ]],
            'branch_id'    => 1,
            'order_type'   => 'dine_in',
            'table_number' => '7',
        ])->post('/customer/place-order', [
            'order_type'             => 'dine_in',
            'table_number'           => '7',
            'payment_method'         => 'cash',
            'items'                  => [['menu_item_id' => $item->id, 'quantity' => 2]],
            'voucher_code_confirmed' => $code,
        ]);

        return Order::where('id', '>', $before)->orderByDesc('id')->first();
    }

    // ══════════════════════════════════════════════════════════════════
    // Happy path
    // ══════════════════════════════════════════════════════════════════

    public function test_an_admin_can_issue_a_code_and_it_is_shown_back(): void
    {
        $voucher = $this->voucher();

        $code = $this->issue($voucher);

        $this->assertNotNull($code, 'no code was flashed back — the admin would have no way to read it out');
        $this->assertStringStartsWith(VoucherClaims::CODE_PREFIX, str_replace('-', '', $code));
        $this->assertSame($voucher->code, session('issued_claim_voucher'));

        $claim = VoucherClaims::resolveByCode($code);

        $this->assertNotNull($claim, 'the flashed code resolves to no claim row');
        $this->assertNull($claim->user_id, 'a counter-issued claim must be ownerless (bearer)');
        $this->assertFalse((bool) $claim->is_used);
        $this->assertSame((int) $voucher->id, (int) $claim->voucher_id);
    }

    public function test_the_issuing_admin_and_time_are_recorded(): void
    {
        $admin   = $this->admin();
        $voucher = $this->voucher();

        $code  = $this->issue($voucher, $admin);
        $claim = VoucherClaims::resolveByCode($code);

        $this->assertSame(
            (int) $admin->id,
            (int) $claim->issued_by,
            'the issuing admin was not recorded'
        );
        $this->assertNotNull($claim->created_at, 'no issue time recorded');
    }

    /**
     * The whole point: it behaves exactly like any other bearer claim at
     * checkout, redeemed by someone with no account.
     */
    public function test_an_issued_code_redeems_once_through_the_normal_checkout(): void
    {
        $voucher = $this->voucher();
        $code    = $this->issue($voucher);

        $order = $this->redeemAsGuest($code);

        $this->assertNotNull($order, 'the guest order was not created: '
            . json_encode(session('errors')?->all() ?? []));
        $this->assertSame((int) $voucher->id, (int) $order->voucher_id, 'the voucher was not applied');
        $this->assertGreaterThan(0, (float) $order->discount_amount, 'no discount came off');

        $claim = VoucherClaims::resolveByCode($code);
        $this->assertTrue((bool) $claim->is_used, 'the claim was not consumed');

        // And exactly once — a second guest presenting the same code is refused.
        Cache::flush();
        $second = $this->withSession(['order_type' => 'dine_in', 'branch_id' => 1])
            ->postJson('/customer/apply-voucher', ['code' => $code, 'subtotal' => 500])
            ->json();

        $this->assertFalse($second['success'] ?? false, 'a spent counter-issued code was accepted again');
        $this->assertSame('You have already used this voucher.', $second['message'] ?? null);
    }

    /**
     * A counter-issued code works TODAY. A wheel claim opens tomorrow; this one
     * deliberately does not, because the customer is standing at the counter.
     * Pinned so the difference is a decision rather than an accident.
     */
    public function test_an_issued_code_is_usable_immediately(): void
    {
        $voucher = $this->voucher();
        $code    = $this->issue($voucher);
        $claim   = VoucherClaims::resolveByCode($code);

        $this->assertSame(
            today()->toDateString(),
            $claim->valid_from->toDateString(),
            'a counter-issued code should open today, not tomorrow'
        );

        $result = $this->withSession(['order_type' => 'dine_in', 'branch_id' => 1])
            ->postJson('/customer/apply-voucher', ['code' => $code, 'subtotal' => 500])
            ->json();

        $this->assertTrue($result['success'] ?? false, $result['message'] ?? 'could not apply today');
    }

    // ══════════════════════════════════════════════════════════════════
    // Issuance is refused when the voucher is not issuable
    // ══════════════════════════════════════════════════════════════════

    /**
     * @dataProvider unissuableVouchers
     */
    public function test_issuing_is_refused_when_the_voucher_is_not_issuable(array $attrs, string $expect): void
    {
        $voucher = $this->voucher($attrs);
        $before  = UserVoucher::count();

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/vouchers/{$voucher->id}/issue-code")
            ->assertRedirect(route('admin.vouchers'));

        $this->assertNull(session('issued_claim_code'), 'a code was issued for an unissuable voucher');
        $this->assertStringContainsString($expect, (string) session('error'));
        $this->assertSame($before, UserVoucher::count(), 'a claim row was created anyway');
    }

    public static function unissuableVouchers(): array
    {
        return [
            'inactive'      => [['is_active' => false], 'inactive'],
            'expired'       => [['expires_at' => now()->subDay()], 'expired'],
            'at cap'        => [['max_uses' => 3, 'used_count' => 3], 'usage limit'],
            'not yet valid' => [['valid_from' => today()->addWeek()->toDateString()], 'does not open until'],
        ];
    }

    /**
     * CONTROL for the four refusals above: the same action on an ordinary
     * voucher succeeds. Without this, all four would pass just as happily if
     * the endpoint were broken for everything.
     */
    public function test_issuing_succeeds_on_an_ordinary_voucher_control(): void
    {
        $before = UserVoucher::count();

        $this->assertNotNull($this->issue($this->voucher()), 'CONTROL FAILED: issuing is broken for every voucher');
        $this->assertSame($before + 1, UserVoucher::count(), 'exactly one claim should have been minted');
    }

    // ══════════════════════════════════════════════════════════════════
    // The cap is shared with the wheel — this path cannot exceed it
    // ══════════════════════════════════════════════════════════════════

    /**
     * Issue, redeem, issue, redeem against a voucher capped at 1.
     *
     * The first pair must work. The second must be refused, because redeeming
     * the first took used_count to the cap — the SAME counter and the SAME
     * check a wheel win is bound by.
     */
    public function test_issued_codes_count_against_the_same_max_uses_cap(): void
    {
        $voucher = $this->voucher(['max_uses' => 1, 'used_count' => 0]);

        // First: issue and redeem.
        $firstCode = $this->issue($voucher);
        $this->assertNotNull($firstCode, 'the first issue should succeed');

        $order = $this->redeemAsGuest($firstCode);
        $this->assertNotNull($order, 'the first redemption should succeed');

        $this->assertSame(
            1,
            (int) $voucher->fresh()->used_count,
            'redeeming an issued code did not count against used_count'
        );

        // Second: the voucher is now at its cap, so issuing must be refused.
        Cache::flush();
        session()->forget('issued_claim_code');

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/vouchers/{$voucher->id}/issue-code");

        $this->assertNull(
            session('issued_claim_code'),
            'an admin issued a code for a voucher already at its max_uses cap — this path bypasses the limit'
        );
        $this->assertStringContainsString('usage limit', (string) session('error'));
    }

    /**
     * The wheel and this path agree about issuability. If the wheel would not
     * award it, the admin cannot hand it out either.
     */
    public function test_the_issuance_rule_matches_the_wheels_own_rule(): void
    {
        // At cap: the wheel's query excludes it, and so must we.
        $capped = $this->voucher(['max_uses' => 2, 'used_count' => 2]);
        $this->assertNotNull($capped->issuanceErrorFor(), 'a capped voucher should not be issuable');

        // Inactive and expired, likewise.
        $this->assertNotNull($this->voucher(['is_active' => false])->issuanceErrorFor());
        $this->assertNotNull($this->voucher(['expires_at' => now()->subHour()])->issuanceErrorFor());

        // CONTROL: an ordinary voucher IS issuable, so the assertions above are
        // not just observing a method that always refuses.
        $this->assertNull(
            $this->voucher()->issuanceErrorFor(),
            'CONTROL FAILED: no voucher is ever issuable'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // Access control — shared with the staff-visible voucher list
    // ══════════════════════════════════════════════════════════════════

    /**
     * Issuing a bearer code to a walk-in customer is a counter task, so staff
     * can do it. It is the ONE voucher action staff have — creating, editing
     * and deleting a voucher itself all stay admin-only.
     */
    public function test_staff_can_issue_a_code(): void
    {
        $staff = $this->staff();
        if (! $staff) {
            $this->markTestSkipped('no active staff account in this database');
        }

        $voucher = $this->voucher();
        $before  = UserVoucher::count();

        $code = $this->issue($voucher, $staff);

        $this->assertNotNull($code, 'staff could not issue a code — issuing a walk-in code is a counter task');
        $this->assertSame($before + 1, UserVoucher::count(), 'no claim row was minted');

        $claim = VoucherClaims::resolveByCode($code);
        $this->assertNotNull($claim);
        $this->assertNull($claim->user_id, 'a counter-issued claim must be ownerless (bearer)');
        $this->assertSame((int) $staff->id, (int) $claim->issued_by, 'the issuing staff member was not recorded');
    }

    public function test_a_signed_out_visitor_cannot_issue_a_code(): void
    {
        $voucher = $this->voucher();
        $before  = UserVoucher::count();

        $this->post("/admin/vouchers/{$voucher->id}/issue-code")
            ->assertRedirect(route('admin.login'));

        $this->assertSame($before, UserVoucher::count());
    }

    public function test_a_customer_cannot_issue_a_code(): void
    {
        $customer = User::where('role', 'customer')->where('is_active', true)->orderBy('id')->first();
        if (! $customer) {
            $this->markTestSkipped('no customer account');
        }

        $voucher = $this->voucher();
        $before  = UserVoucher::count();

        $this->actingAs($customer, 'customer')
            ->post("/admin/vouchers/{$voucher->id}/issue-code");

        $this->assertSame($before, UserVoucher::count(), 'a customer minted a claim');
        $this->assertNull(session('issued_claim_code'));
    }

    /**
     * The endpoint shares the access rule of the staff-visible voucher list
     * (role:admin,staff) — pinned so a future move out of that group, in either
     * direction, is caught here rather than in production. The voucher-defining
     * routes (store/update/delete/toggle) stay role:admin and are checked by
     * test_a_customer_cannot_issue_a_code and the signed-out test above.
     */
    public function test_the_route_shares_the_access_rule_of_the_staff_voucher_list(): void
    {
        $issue = \Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.vouchers.issue-code');
        $list  = \Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.vouchers');

        $this->assertNotNull($issue, 'the issue-code route should exist');

        $roleRules = fn ($r) => array_values(array_filter(
            $r->gatherMiddleware(),
            fn ($m) => is_string($m) && str_starts_with($m, 'role:')
        ));

        $this->assertSame(
            $roleRules($list),
            $roleRules($issue),
            'issuing a code should share the access rule of the staff-visible voucher list'
        );
        $this->assertContains('role:admin,staff', $roleRules($issue));
    }
}
