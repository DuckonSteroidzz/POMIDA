<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * BRANCH-SCOPED VOUCHERS ARE ONLY REDEEMABLE AT THEIR OWN BRANCH.
 *
 * THE GAP THIS CLOSES
 * -------------------
 * Phase 2.5 (47372b1) gave vouchers and ads a nullable branch_id and scoped a
 * Supervisor's MANAGEMENT of them to their own branch. It deliberately did not
 * touch redemption, and said so. So the column existed and meant nothing to the
 * customer: proven against the live schema before this fix, a voucher created
 * with branch_id = 3 was accepted for an order at branch 1 —
 *
 *     POST /customer/apply-voucher {code: ..., subtotal: 500}
 *     -> 200 {"success":true,"discount":50,"final_total":450}
 *
 * — because Voucher::redemptionErrorFor() asked about is_active, expires_at,
 * max_uses, the claim and valid_from, and never about the branch.
 *
 * THE RULE, WHICH IS PHASE 2.5's OWN RULE
 * ---------------------------------------
 *      branch_id NULL  -> global. Redeemable at every branch.
 *      branch_id N     -> redeemable ONLY for an order placed at branch N.
 *
 * NULL is tested first and on its own, never by integer comparison — the same
 * shape (and the same reasoning) as AdminController::promotionScopeRefusal():
 * (int) null is 0, so a comparison-first version would quietly read "valid
 * everywhere" as "valid at branch 0".
 *
 * BOTH SIDES, DELIBERATELY
 * ------------------------
 * The preview (/customer/apply-voucher) and the charge (placeOrder) are tested
 * separately and asserted to agree. This file exists because a rule that lives
 * on only one of them is the exact bug Voucher's own header comment was written
 * about: the two drifted before, and the cart ended up refusing what checkout
 * happily honoured.
 *
 * DATA HYGIENE
 * ------------
 * DatabaseTransactions, so nothing here is ever committed. Every voucher is one
 * this file mints under the VBRANCH prefix; no pre-existing row is selected for
 * mutation, which matters in a file about vouchers — the owner's live vouchers
 * (23 DISCOUNT, 9293 DISCOUNT-01, both global) are untouched by construction.
 * The branches (1..4) and menu items are only READ.
 */
class VoucherBranchRedemptionTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'VBRANCH';

    /** A menu item that is genuinely orderable at each branch under test. */
    private const ITEM_AT_BRANCH = [1 => 24, 2 => 30];

    private function voucher(?int $branchId, string $suffix): Voucher
    {
        return Voucher::create([
            'branch_id'       => $branchId,
            'code'            => self::PREFIX . '-' . $suffix,
            'description'     => 'Branch scope regression voucher',
            'discount_type'   => 'fixed',
            'discount_value'  => 25,
            'max_uses'        => 0,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'is_active'       => true,
            'points_required' => 0,
        ]);
    }

    /** The cart preview, as the cart page calls it. */
    private function preview(Voucher $voucher, int $branchId, float $subtotal = 500)
    {
        return $this->withSession(['order_type' => 'pick_up', 'branch_id' => $branchId])
            ->postJson('/customer/apply-voucher', [
                'code'     => $voucher->code,
                'subtotal' => $subtotal,
            ]);
    }

    /** Checkout, as the cart form posts it. */
    private function checkout(Voucher $voucher, int $branchId)
    {
        $item = MenuItem::findOrFail(self::ITEM_AT_BRANCH[$branchId]);

        $cart = [
            (string) $item->id => [
                'menu_item_id' => $item->id,
                'name'         => $item->name,
                'price'        => (float) $item->price,
                'quantity'     => 1,
                'options'      => [],
            ],
        ];

        return $this->withSession([
                'cart'       => $cart,
                'order_type' => 'pick_up',
                'branch_id'  => $branchId,
            ])
            ->post('/customer/place-order', [
                'items'                  => [['menu_item_id' => $item->id, 'quantity' => 1]],
                'order_type'             => 'pick_up',
                'branch_id'              => $branchId,
                'payment_method'         => 'cash',
                'voucher_code_confirmed' => $voucher->code,
            ]);
    }

    // ══════════════════ the preview ══════════════════

    public function test_a_branch_voucher_is_refused_at_another_branch(): void
    {
        $voucher = $this->voucher(2, 'OWNBRANCH');

        $this->preview($voucher, 1)
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function test_the_refusal_names_the_branch_it_belongs_to(): void
    {
        $voucher = $this->voucher(2, 'NAMED');

        $message = $this->preview($voucher, 1)->json('message');

        $this->assertStringContainsString(
            (string) $voucher->branch->name,
            (string) $message,
            'the refusal does not tell the customer where the voucher can be used'
        );
    }

    public function test_a_branch_voucher_is_accepted_at_its_own_branch(): void
    {
        $voucher = $this->voucher(2, 'ATHOME');

        $this->preview($voucher, 2)
            ->assertOk()
            ->assertJson(['success' => true, 'discount' => 25]);
    }

    public function test_a_global_voucher_is_accepted_at_every_branch(): void
    {
        $voucher = $this->voucher(null, 'GLOBAL');

        foreach ([1, 2] as $branchId) {
            $this->preview($voucher, $branchId)
                ->assertOk()
                ->assertJson(['success' => true, 'discount' => 25]);
        }
    }

    // ══════════════════ checkout, which is where the money is ══════════════════

    public function test_checkout_refuses_a_branch_voucher_at_another_branch(): void
    {
        $voucher = $this->voucher(2, 'CHECKOUT');

        $this->checkout($voucher, 1)
            ->assertSessionHasErrors('voucher_code_confirmed');

        $this->assertSame(
            0,
            (int) $voucher->fresh()->used_count,
            'a voucher refused at the wrong branch was still spent'
        );

        $this->assertSame(
            0,
            Order::where('voucher_id', $voucher->id)->count(),
            'an order was created carrying a voucher the branch rule refuses'
        );
    }

    public function test_checkout_honours_a_branch_voucher_at_its_own_branch(): void
    {
        $voucher = $this->voucher(2, 'CHECKOUTOK');

        $this->checkout($voucher, 2)->assertSessionHasNoErrors();

        $order = Order::where('voucher_id', $voucher->id)->first();

        $this->assertNotNull($order, 'the voucher was refused at its own branch');
        $this->assertSame(2, (int) $order->branch_id);
        $this->assertEqualsWithDelta(25, (float) $order->discount_amount, 0.001);
    }

    public function test_checkout_honours_a_global_voucher_anywhere(): void
    {
        $voucher = $this->voucher(null, 'CHECKOUTGLOBAL');

        $this->checkout($voucher, 1)->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            Order::where('voucher_id', $voucher->id)->count(),
            'a global voucher was refused at a branch'
        );
    }

    // ══════════ the counter, which redeems through the same validator ══════════
    //
    // A manual order is an order placed AT a branch like any other, and it
    // spends the same used_count and the same claim row the customer's checkout
    // spends — so the branch rule has to reach it too, or the counter becomes
    // the way around it. The preview is tested next to the submit for the usual
    // reason: staff read the preview out to the customer, so a preview that
    // says yes where the submit says no is its own bug.

    private function owner(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function counterPreview(Voucher $voucher, int $branchId)
    {
        return $this->actingAs($this->owner(), 'admin')
            ->postJson('/admin/manual-order/voucher-preview', [
                'code'      => $voucher->code,
                'subtotal'  => 500,
                'branch_id' => $branchId,
            ]);
    }

    public function test_the_counter_preview_refuses_a_branch_voucher_at_another_branch(): void
    {
        $voucher = $this->voucher(2, 'COUNTER');

        $this->counterPreview($voucher, 1)
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function test_the_counter_preview_accepts_a_branch_voucher_at_its_own_branch(): void
    {
        $voucher = $this->voucher(2, 'COUNTEROK');

        $this->counterPreview($voucher, 2)
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    private function manualOrder(Voucher $voucher, int $branchId)
    {
        $item = MenuItem::findOrFail(self::ITEM_AT_BRANCH[$branchId]);

        return $this->actingAs($this->owner(), 'admin')
            ->post('/admin/manual-order', [
                'branch_id'      => $branchId,
                'order_type'     => 'pick_up',
                'table_number'   => '',
                'payment_method' => 'cash',
                'amount_paid'    => '100000',
                'voucher_code'   => $voucher->code,
                'items'          => [
                    (string) $item->id => [
                        'menu_item_id' => (string) $item->id,
                        'quantity'     => '1',
                        'options'      => [],
                    ],
                ],
            ]);
    }

    public function test_a_manual_order_cannot_spend_another_branchs_voucher(): void
    {
        $voucher = $this->voucher(2, 'MANUAL');

        $this->manualOrder($voucher, 1)->assertSessionHasErrors('voucher_code');

        $this->assertSame(
            0,
            (int) $voucher->fresh()->used_count,
            'the counter spent a voucher belonging to another branch'
        );
    }

    /**
     * The acceptance direction, and the one that actually pins the call site.
     *
     * The refusal test above passes whether storeManualOrder() passes the
     * branch or nothing at all — with no branch the rule refuses anyway, so it
     * cannot tell a wired call site from an unwired one. Caught during the
     * sabotage pass: deleting the branch argument from storeManualOrder() left
     * the refusal test green. This is the test that goes red for it.
     */
    public function test_a_manual_order_spends_its_own_branchs_voucher(): void
    {
        $voucher = $this->voucher(2, 'MANUALOK');

        $this->manualOrder($voucher, 2)->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            (int) $voucher->fresh()->used_count,
            'the counter refused a voucher at the branch it belongs to'
        );
    }
}
