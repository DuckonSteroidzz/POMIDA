<?php

namespace Tests\Feature;

use App\Models\DiscountCard;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderDiscountBeneficiary;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * PWD / Senior Citizen: no expiration date, and an explicit "Apply" step
 * (Batch 2, 2026-09-29).
 *
 * Team testing asked for two changes to the cart's PWD/Senior section:
 *
 *  1. No expiration date at all, for PWD or Senior Citizen. One date per
 *     order could not describe a group whose IDs each expire on their own
 *     day, so the requirement was removed rather than multiplied. Anything
 *     a stale page still posts under the old field name is ignored.
 *
 *  2. An "Apply" button. The ID rows can be typed freely; the discount is
 *     only previewed, and only requested, once the customer presses Apply
 *     with every listed row complete. The cart posts that intent as the
 *     hidden field discount_applied=1.
 *
 * THE SERVER RULE THESE TESTS PIN: the flag can only ever TAKE a discount
 * away. Without it there is no PWD/Senior discount, whatever else was sent.
 * With it, the amount is still Order::pwdSeniorDiscountFor($subtotal) — 20%
 * of the subtotal, once per order, however many IDs — and never a figure
 * the browser posted.
 *
 * Unchanged, and checked again here: the 20% rate, once per order, the
 * bigger-of(voucher, PWD/Senior) rule, the 50-row ceiling and staff approval.
 */
class PwdSeniorApplyStepTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::where('role', 'customer')->orderBy('id')->firstOrFail();
    }

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->orderBy('id')
            ->firstOrFail();
    }

    private function cart(MenuItem $item, int $qty): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => $qty,
            'image'        => $item->image,
            'options'      => [],
        ]];
    }

    private function rows(int $n): array
    {
        $rows = [];

        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['id_number' => 'APL-' . (2000 + $i), 'full_name' => 'Apply Guest ' . str_repeat('x', $i)];
        }

        return $rows;
    }

    /**
     * @return array{0: \Illuminate\Testing\TestResponse, 1: ?Order, 2: float}
     */
    private function place(array $extra, int $qty = 2, string $payment = 'cash'): array
    {
        $item = $this->item();
        $before = (int) Order::max('id');

        $response = $this->actingAs($this->customer(), 'customer')
            ->withSession(['cart' => $this->cart($item, $qty), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', array_merge([
                'order_type'     => 'pick_up',
                'payment_method' => $payment,
                'items'          => [['menu_item_id' => $item->id, 'quantity' => $qty]],
            ], $extra));

        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        return [$response, $order, round((float) $item->price * $qty, 2)];
    }

    private function storedRowCount(Order $order): int
    {
        return OrderDiscountBeneficiary::where('order_id', $order->id)->count();
    }

    private function cartHtml(): string
    {
        return $this->actingAs($this->customer(), 'customer')
            ->withSession(['cart' => $this->cart($this->item(), 2), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();
    }

    // ══════════ 1. applied: the one discount, 1 row or 5 ══════════

    public function test_one_applied_row_gets_the_one_discount_on_the_subtotal(): void
    {
        [$response, $order, $subtotal] = $this->place([
            'discount_type' => 'pwd', 'discount_applied' => '1', 'discount_beneficiaries' => $this->rows(1),
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('pwd', $order->discount_type);
        $this->assertSame(Order::pwdSeniorDiscountFor($subtotal), (float) $order->discount_amount);
        $this->assertSame(round($subtotal - Order::pwdSeniorDiscountFor($subtotal), 2), (float) $order->total);
        $this->assertSame('pending', $order->discount_status, 'staff approval is unchanged');
        $this->assertSame(1, $this->storedRowCount($order));
    }

    public function test_five_applied_rows_get_exactly_the_same_one_discount(): void
    {
        [, $one, $subtotal] = $this->place([
            'discount_type' => 'senior', 'discount_applied' => '1', 'discount_beneficiaries' => $this->rows(1),
        ]);
        [$response, $five] = $this->place([
            'discount_type' => 'senior', 'discount_applied' => '1', 'discount_beneficiaries' => $this->rows(5),
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(Order::pwdSeniorDiscountFor($subtotal), (float) $five->discount_amount);
        $this->assertSame((float) $one->discount_amount, (float) $five->discount_amount, 'five IDs = one discount');
        $this->assertSame((float) $one->total, (float) $five->total);
        $this->assertSame(5, $this->storedRowCount($five), 'every ID is still recorded');
    }

    // ══════════ 2. not applied: no discount, and the customer is told ══════════

    public function test_rows_filled_but_not_applied_give_no_discount_and_record_nothing(): void
    {
        [$response, $order, $subtotal] = $this->place([
            'discount_type' => 'pwd', 'discount_beneficiaries' => $this->rows(2),
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertNotNull($order, 'the order is still placed, at the regular price');
        $this->assertNull($order->discount_type);
        $this->assertSame(0.0, (float) $order->discount_amount);
        $this->assertSame($subtotal, (float) $order->total);
        $this->assertSame('approved', $order->discount_status, 'nothing for staff to verify');
        $this->assertNull($order->discount_beneficiary_name);
        $this->assertNull($order->discount_beneficiary_card_number);
        $this->assertSame(0, $this->storedRowCount($order), 'IDs never applied are not recorded');

        $response->assertRedirect(route('customer.orders'));
        $response->assertSessionHas('success', fn ($m) => str_contains($m, 'PWD/Senior Citizen discount was not applied'));
    }

    public function test_an_explicit_false_flag_is_the_same_as_no_flag(): void
    {
        foreach (['0', ''] as $flag) {
            [, $order, $subtotal] = $this->place([
                'discount_type' => 'senior', 'discount_applied' => $flag, 'discount_beneficiaries' => $this->rows(1),
            ]);

            $this->assertSame($subtotal, (float) $order->total, 'flag "' . $flag . '" must not grant a discount');
            $this->assertNull($order->discount_type);
        }
    }

    public function test_the_gcash_path_carries_the_same_not_applied_notice(): void
    {
        [$response, $order] = $this->place([
            'discount_type' => 'senior', 'discount_beneficiaries' => $this->rows(1),
        ], 2, 'gcash');

        $response->assertRedirect(route('customer.gcash-payment', $order->id));

        // Its own key, not 'success': the GCash page titles that box
        // "Payment Submitted", and nothing has been paid yet.
        $response->assertSessionHas('discount_notice', fn ($m) => str_contains($m, 'PWD/Senior Citizen discount was not applied'));
        $response->assertSessionMissing('success');

        $page = $this->actingAs($this->customer(), 'customer')
            ->withSession(['discount_notice' => 'NOTICE-PROBE'])
            ->get(route('customer.gcash-payment', $order->id));
        $page->assertOk();
        $page->assertSee('NOTICE-PROBE');
    }

    /** An applied row that is malformed is refused; the same row never applied is ignored. */
    public function test_rows_are_only_validated_when_applied(): void
    {
        $bad = [['id_number' => 'APL 1 <script>', 'full_name' => '!!']];

        [$response, $order] = $this->place(['discount_type' => 'pwd', 'discount_applied' => '1', 'discount_beneficiaries' => $bad]);
        $response->assertSessionHasErrors();
        $this->assertNull($order);

        [$response, $order, $subtotal] = $this->place(['discount_type' => 'pwd', 'discount_beneficiaries' => $bad]);
        $response->assertSessionDoesntHaveErrors();
        $this->assertSame($subtotal, (float) $order->total, 'never applied: regular price, rows not read');
        $this->assertSame(0, $this->storedRowCount($order));
    }

    public function test_a_normal_order_gets_no_notice(): void
    {
        [$response] = $this->place([]);

        $response->assertSessionHas('success', fn ($m) => ! str_contains($m, 'not applied'));
    }

    // ══════════ 3. the flag can never grant more ══════════

    public function test_a_crafted_request_cannot_raise_the_discount(): void
    {
        [$response, $order, $subtotal] = $this->place([
            'discount_type'    => 'pwd',
            'discount_applied' => '1',
            'discount_beneficiaries' => $this->rows(3),
            'discount_amount'  => '99999',
            'discount_rate'    => '1',
            'total'            => '0',
            'subtotal'         => '1',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame($subtotal, (float) $order->subtotal);
        $this->assertSame(Order::pwdSeniorDiscountFor($subtotal), (float) $order->discount_amount);
        $this->assertSame(round($subtotal - Order::pwdSeniorDiscountFor($subtotal), 2), (float) $order->total);
    }

    public function test_the_flag_alone_grants_nothing(): void
    {
        [$response, $order, $subtotal] = $this->place(['discount_applied' => '1', 'discount_amount' => '50']);

        $response->assertSessionDoesntHaveErrors();
        $this->assertNull($order->discount_type);
        $this->assertSame($subtotal, (float) $order->total);
    }

    public function test_a_malformed_flag_is_refused_before_anything_is_written(): void
    {
        [$response, $order] = $this->place([
            'discount_type' => 'pwd', 'discount_applied' => ['1', '1'], 'discount_beneficiaries' => $this->rows(1),
        ]);

        $response->assertSessionHasErrors('discount_applied');
        $this->assertNull($order);
    }

    /** Applied, but a row is half-filled: still the shared refusal, no order. */
    public function test_an_applied_half_filled_row_is_still_refused(): void
    {
        [$response, $order] = $this->place([
            'discount_type' => 'pwd', 'discount_applied' => '1',
            'discount_beneficiaries' => [['id_number' => 'APL-1', 'full_name' => '']],
        ]);

        $response->assertSessionHasErrors('discount_type');
        $this->assertNull($order);
    }

    // ══════════ 4. no expiration anywhere ══════════

    /** Whatever a stale page still posts under the old field is ignored — for PWD too. */
    public function test_any_posted_expiration_value_is_ignored_for_pwd(): void
    {
        foreach (['', '1/1/1940', '2/30/2027', 'not a date', now()->addYear()->format('n/j/Y')] as $stale) {
            [$response, $order, $subtotal] = $this->place([
                'discount_type' => 'pwd', 'discount_applied' => '1',
                'discount_beneficiaries' => $this->rows(1),
                'discount_beneficiary_expiration' => $stale,
            ]);

            $response->assertSessionDoesntHaveErrors();
            $this->assertNotNull($order, 'expiry "' . $stale . '" must not block a PWD order');
            $this->assertSame(Order::pwdSeniorDiscountFor($subtotal), (float) $order->discount_amount);
            $this->assertNull($order->discount_beneficiary_expiration, 'no expiry is stored for new orders');
        }
    }

    /** The saved-card path lost its expiry check too — and needs the Apply intent like everything else. */
    public function test_a_saved_card_needs_no_valid_expiration_but_does_need_the_apply_intent(): void
    {
        $card = DiscountCard::create([
            'user_id'         => $this->customer()->id,
            'type'            => 'pwd',
            'id_number'       => 'APL-CARD-1',
            'full_name'       => 'Saved Card Holder',
            'id_image'        => 'discount_ids/none.png',
            'expiration_date' => '1940-01-01',
            'is_verified'     => true,
            'is_active'       => true,
        ]);

        [$response, $order, $subtotal] = $this->place(['discount_card_id' => $card->id]);
        $response->assertSessionDoesntHaveErrors();
        $this->assertNull($order->discount_type, 'no Apply intent, no discount');
        $this->assertSame($subtotal, (float) $order->total);

        [$response, $order, $subtotal] = $this->place(['discount_card_id' => $card->id, 'discount_applied' => '1']);
        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('pwd', $order->discount_type, 'an old stored expiry no longer blocks the card');
        $this->assertSame(Order::pwdSeniorDiscountFor($subtotal), (float) $order->discount_amount);
        $this->assertNull($order->discount_beneficiary_expiration);
    }

    // ══════════ 5. the bigger-of rule is unchanged ══════════

    public function test_a_bigger_voucher_still_beats_an_applied_card(): void
    {
        $subtotal = round((float) $this->item()->price * 2, 2);
        $card = Order::pwdSeniorDiscountFor($subtotal);

        $voucher = Voucher::create([
            'code'            => 'APL' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'apply-step bigger-of test',
            'discount_type'   => 'fixed',
            'discount_value'  => round($card + 5, 2),
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
        ]);

        [$response, $order] = $this->place([
            'discount_type' => 'pwd', 'discount_applied' => '1', 'discount_beneficiaries' => $this->rows(2),
            'voucher_code_confirmed' => $voucher->code,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('voucher', $order->discount_type);
        $this->assertSame(0, $this->storedRowCount($order));
    }

    // ══════════ 6. the cart page ══════════

    public function test_the_cart_has_an_apply_button_and_no_expiration_field(): void
    {
        $html = $this->cartHtml();

        $this->assertStringContainsString('id="discountApplyButton"', $html);
        $this->assertStringContainsString('onclick="applyDiscountIds()"', $html);
        $this->assertMatchesRegularExpression('/<input\s+type="hidden"\s+name="discount_applied"\s+id="discountApplied"\s+value="">/', $html);

        // Nothing of the expiration UI is left.
        $this->assertStringNotContainsString('name="discount_beneficiary_expiration"', $html);
        $this->assertStringNotContainsString('discountExpirationBlock', $html);
        $this->assertStringNotContainsString('discountExpirationMonth', $html);
        $this->assertStringNotContainsString('Expiration Date', $html);
        $this->assertStringNotContainsString('discountCardExpirationError', $html);

        // The preview is gated on Apply; editing a row withdraws it.
        $this->assertStringContainsString('if (!discountIdsApplied) {', $html);
        $this->assertMatchesRegularExpression('/function onDiscountIdRowsChanged\(\) \{\s+setDiscountApplied\(false\);/', $html);

        // The confirm modal warns when rows were entered but never applied.
        $this->assertStringContainsString('id="reviewDiscountNotApplied"', $html);
    }

    // ══════════ 7. the admin approval modal ══════════

    /** New orders have no expiry, so the modal's row stays hidden; an older order's stored date is still shown. */
    public function test_the_approval_modal_only_shows_an_expiry_an_older_order_stored(): void
    {
        [, $new] = $this->place(['discount_type' => 'pwd', 'discount_applied' => '1', 'discount_beneficiaries' => $this->rows(1)]);
        [, $old] = $this->place(['discount_type' => 'pwd', 'discount_applied' => '1', 'discount_beneficiaries' => $this->rows(1)]);

        // An order placed before this change, with its validated date kept.
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $old->id)
            ->update(['discount_beneficiary_expiration' => '2031-03-04']);

        $staff = User::where('email', 'simon@peachy.com')->firstOrFail();
        $html = $this->actingAs($staff, 'admin')->get('/admin/home')->getContent();

        preg_match('/data-order-id="' . $new->id . '"[^>]*?data-expiration="([^"]*)"/s', $html, $m);
        $this->assertSame('', $m[1] ?? null, 'a new order carries no expiry');

        preg_match('/data-order-id="' . $old->id . '"[^>]*?data-expiration="([^"]*)"/s', $html, $m);
        $this->assertSame('Mar 04, 2031', $m[1] ?? null, 'an older order\'s stored value stays readable');

        $this->assertStringContainsString('id="pcDiscountExpirationRow" style="display:none;"', $html);
    }
}
