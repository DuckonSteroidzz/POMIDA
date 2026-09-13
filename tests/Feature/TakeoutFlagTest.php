<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\TableEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * "Take Out" — a Dine-In-only checkbox in the Confirm Your Order
 * modal, persisted as orders.is_takeout, surfaced to staff/admin as a
 * "Take Out" badge.
 *
 * THE RULES BEING PINNED
 * ----------------------
 *  - The checkbox is rendered ONLY for a Dine-In cart, never for Pick-Up
 *    (a Pick-Up order is already takeout by definition).
 *  - Ticking it on a Dine-In order persists is_takeout = true; leaving it
 *    persists false.
 *  - A Pick-Up submission that FORCES is_takeout=1 (bypassing the hidden UI)
 *    is ignored server-side — the flag comes out false.
 *  - The staff/admin Active Orders board shows a "Take Out" badge for a
 *    flagged Dine-In order and nothing for an unflagged one or a Pick-Up.
 *
 * DatabaseTransactions: every row these tests create (orders, and the
 * restaurant_tables/table_sessions rows a scan registers) is rolled back at
 * the end of each test, so there is no high-water-mark cleanup to do.
 */
class TakeoutFlagTest extends TestCase
{
    use DatabaseTransactions;

    // Kept short on purpose: orders.order_number is varchar(20) and
    // orders.table_number is varchar(10).
    private const TEST_PREFIX = 'TOT-';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
        parent::tearDown();
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();
    }

    private function cartFor(MenuItem $item, int $qty = 1): array
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

    /** Put the guest session in a seated, Dine-In state on the given table. */
    private function seatAt(string $table): void
    {
        $code = TableEntry::findOrRegister(1, $table)->code;

        $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
            'tableData' => "branch_id=1&table={$table}&k={$code}",
            'next'      => 'guest',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. The checkbox: Dine-In only
    // ══════════════════════════════════════════════════════════════════

    public function test_the_take_out_checkbox_is_rendered_on_a_dine_in_cart(): void
    {
        $item = $this->item();

        $html = $this->withSession([
                'cart'         => $this->cartFor($item),
                'branch_id'    => 1,
                'order_type'   => 'dine_in',
                'table_number' => '5',
            ])
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="is_takeout"', $html);
        $this->assertStringContainsString('>Take Out<', $html);
        // Form-associated: the input sits outside #mainOrderForm in the modal.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="is_takeout"[^>]*form="mainOrderForm"|<input[^>]*form="mainOrderForm"[^>]*name="is_takeout"/s',
            $html,
            'the is_takeout checkbox must be associated with mainOrderForm'
        );
    }

    public function test_the_take_out_checkbox_is_absent_on_a_pick_up_cart(): void
    {
        $customer = $this->customer();
        $item = $this->item();

        $html = $this->actingAs($customer, 'customer')
            ->withSession([
                'cart'       => $this->cartFor($item),
                'branch_id'  => 1,
                'order_type' => 'pick_up',
            ])
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="is_takeout"', $html);
        $this->assertStringNotContainsString('>Take Out<', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. Persistence
    // ══════════════════════════════════════════════════════════════════

    public function test_ticking_take_out_on_a_dine_in_order_persists_true(): void
    {
        $item = $this->item();
        $before = (int) Order::max('id');

        $this->seatAt(self::TEST_PREFIX . 'A');

        $this->from('/customer/cart')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1])
            ->post('/customer/place-order', [
                'order_type'     => 'dine_in',
                'table_number'   => self::TEST_PREFIX . 'A',
                'payment_method' => 'cash',
                'is_takeout'     => '1',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $order = Order::where('id', '>', $before)->orderByDesc('id')->firstOrFail();
        $this->assertSame('dine_in', $order->type);
        $this->assertTrue($order->is_takeout, 'a ticked Take Out on a Dine-In order was not persisted');
    }

    public function test_not_ticking_take_out_on_a_dine_in_order_persists_false(): void
    {
        $item = $this->item();
        $before = (int) Order::max('id');

        $this->seatAt(self::TEST_PREFIX . 'B');

        $this->from('/customer/cart')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1])
            ->post('/customer/place-order', [
                'order_type'     => 'dine_in',
                'table_number'   => self::TEST_PREFIX . 'B',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $order = Order::where('id', '>', $before)->orderByDesc('id')->firstOrFail();
        $this->assertFalse($order->is_takeout, 'an un-ticked Take Out did not persist as false');
    }

    /**
     * The defensive server-side rule: a Pick-Up submission that forces
     * is_takeout=1 (the checkbox is never rendered for Pick-Up, so this can
     * only be a tampered request) must be ignored. This hits the controller
     * directly, not through the hidden UI.
     */
    public function test_a_forced_take_out_on_a_pick_up_order_is_ignored(): void
    {
        $customer = $this->customer();
        $item = $this->item();
        $before = (int) Order::max('id');

        $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'is_takeout'     => '1',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $order = Order::where('id', '>', $before)->orderByDesc('id')->firstOrFail();
        $this->assertSame('pick_up', $order->type);
        $this->assertFalse(
            $order->is_takeout,
            'a tampered is_takeout=1 on a Pick-Up order was persisted anyway'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. The staff / admin badge
    // ══════════════════════════════════════════════════════════════════

    public function test_the_active_orders_board_badges_a_flagged_dine_in_order(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        $flagged = Order::create($this->boardOrder([
            'type'        => 'dine_in',
            'is_takeout'  => true,
            'table_number' => '7',
        ]));

        $plain = Order::create($this->boardOrder([
            'type'       => 'dine_in',
            'is_takeout' => false,
            'table_number' => '8',
        ]));

        $pickup = Order::create($this->boardOrder([
            'type'         => 'pick_up',
            'is_takeout'   => true, // should be impossible in practice; badge still must not show
            'table_number' => null,
        ]));

        $html = $this->actingAs($admin, 'admin')->get('/admin/home')->assertOk()->getContent();

        // A flagged Take Out order reads as the table reference plus "Take Out"
        // ONLY — the "Dine-in" wording is dropped so the label never implies two
        // conflicting order types. The flagged order (table 7) reads "Table 7 ·
        // Take Out"; the plain Dine-In order (table 8) still reads "Dine-in —
        // Table 8" with no modifier; the Pick-Up order carries neither.
        $this->assertMatchesRegularExpression(
            '/Table 7\s*·\s*Take Out/u',
            $html,
            'the flagged Dine-In order should read "Table 7 · Take Out" on the board'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Dine-in\s*—\s*Table 7/u',
            $html,
            'the flagged order must not still say "Dine-in — Table 7" next to "Take Out"'
        );
        $this->assertMatchesRegularExpression(
            '/Dine-in\s*—\s*Table 8/u',
            $html,
            'the un-flagged Dine-In order should still read "Dine-in — Table 8"'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Table 8[^<]*·\s*Take Out/u',
            $html,
            'the un-flagged Dine-In order must not carry the Take Out modifier'
        );
        // Pick-Up never carries it (the flag is impossible there, but assert anyway).
        $this->assertStringNotContainsString('Pickup · Take Out', $html);

        $this->assertNotFalse(strpos($html, $flagged->order_number));
        $this->assertNotFalse(strpos($html, $plain->order_number));
    }

    public function test_the_completed_orders_list_labels_a_flagged_dine_in_order(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        $flagged = Order::create($this->boardOrder([
            'type'         => 'dine_in',
            'is_takeout'   => true,
            'table_number' => '7',
            'status'       => 'completed',
        ]));

        $plain = Order::create($this->boardOrder([
            'type'         => 'dine_in',
            'is_takeout'   => false,
            'table_number' => '8',
            'status'       => 'completed',
        ]));

        $pickup = Order::create($this->boardOrder([
            'type'         => 'pick_up',
            'is_takeout'   => true, // impossible in practice; the label still must not show it
            'table_number' => null,
            'status'       => 'completed',
        ]));

        $html = $this->actingAs($admin, 'admin')
            ->get('/admin/completed-orders')
            ->assertOk()
            ->getContent();

        // The Type badge has no separate Table column beside it on this page, so a
        // flagged order reads "Table 7 · Take Out" — never "Dine In" next to
        // "Take Out".
        $this->assertMatchesRegularExpression('/Table 7\s*·\s*Take Out/u', $html);
        $this->assertDoesNotMatchRegularExpression('/Dine In[^<]*·\s*Take Out/u', $html);
        // An un-flagged Dine-In order still shows the plain "Dine In" type, no modifier.
        $this->assertDoesNotMatchRegularExpression('/Table 8\s*·\s*Take Out/u', $html);
        // Pick-Up never carries it.
        $this->assertStringNotContainsString('Pickup · Take Out', $html);

        $this->assertNotFalse(strpos($html, $flagged->order_number));
        $this->assertNotFalse(strpos($html, $plain->order_number));
        $this->assertNotFalse(strpos($html, $pickup->order_number));
    }

    private function boardOrder(array $attrs): array
    {
        return array_merge([
            'order_number'   => self::TEST_PREFIX . strtoupper(substr(uniqid(), -8)),
            'user_id'        => null,
            'branch_id'      => 1,
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ], $attrs);
    }
}
