<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Manual (counter / walk-in) orders, keyed in by staff or an admin.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * Every manual order silently failed. The PWD/Senior block is hidden with
 * display:none until the checkbox is ticked, but hiding a control does not stop
 * the browser submitting it, and <select name="discount_type"> had no empty
 * option — so it always posted its first option, "pwd". storeManualOrder() saw
 * a discount request with no beneficiary name or ID, refused the whole order
 * with back()->withErrors(), and the page re-rendered with the modal closed, so
 * the error inside it was never on screen. Staff saw nothing happen at all: no
 * order, no message, and nothing in the log, because a guard return is not an
 * exception.
 *
 * The assertions below are therefore about two things: that a normal counter
 * order actually reaches the database, and that the phantom discount cannot
 * come back.
 */
class ManualOrderCounterTest extends TestCase
{
    use DatabaseTransactions;

    /*
     * The item and add-on this test orders are resolved at runtime rather than
     * hard-coded.
     *
     * They used to be constants (Peperoni Pizza #25 + extra cheese #6). Then an
     * admin archived "extra cheese" from the live Menu Options screen — an
     * ordinary, correct thing to do — and every test in this file started
     * failing with "Invalid option selected for Peperoni Pizza", because
     * MenuOption carries a global scope that hides archived rows. The failure
     * said nothing about the real cause and had nothing to do with the code
     * under test. Picking any currently orderable pair keeps the test about
     * manual orders instead of about one row's lifecycle.
     */
    private static ?array $pair = null;

    private function pair(): array
    {
        if (self::$pair !== null) {
            return self::$pair;
        }

        $item = \App\Models\MenuItem::with('options')
            ->where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->get()
            ->first(fn ($i) => $i->options->isNotEmpty());

        $this->assertNotNull(
            $item,
            'no available branch-1 menu item with an add-on option to order in this test'
        );

        $option = $item->options->first();

        return self::$pair = [
            'item_id'   => (int) $item->id,
            'option_id' => (int) $option->id,
            'total'     => round((float) $item->price + (float) $option->additional_price, 2),
        ];
    }

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@peachycafe.com')->firstOrFail();
    }

    /** What the modal posts for one pizza with extra cheese. */
    private function payload(array $override = []): array
    {
        return array_merge([
            'branch_id'      => 1,
            'order_type'     => 'pick_up',
            'table_number'   => '',
            'payment_method' => 'gcash',
            'amount_paid'    => '1000',
            'items'          => [
                $this->pair()['item_id'] . '_' . $this->pair()['option_id'] => [
                    'menu_item_id' => (string) $this->pair()['item_id'],
                    'quantity'     => '1',
                    'options'      => [(string) $this->pair()['option_id']],
                ],
            ],
        ], $override);
    }

    private function submit(User $actor, array $payload)
    {
        return $this->actingAs($actor, 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', $payload);
    }

    /**
     * @return array{0: \Illuminate\Testing\TestResponse, 1: ?object}
     */
    private function submitAndFetch(User $actor, array $payload): array
    {
        $before   = DB::table('orders')->max('id');
        $response = $this->submit($actor, $payload);
        $order    = DB::table('orders')->where('id', '>', $before ?? 0)->orderByDesc('id')->first();

        return [$response, $order];
    }

    // ══════════ the order must actually land in the database ══════════

    public static function counterOrderCases(): array
    {
        return [
            'staff, GCash, pick-up' => ['staff', 'gcash', 'pick_up'],
            'staff, cash,  pick-up' => ['staff', 'cash',  'pick_up'],
            'staff, GCash, dine-in' => ['staff', 'gcash', 'dine_in'],
            'admin, GCash, pick-up' => ['admin', 'gcash', 'pick_up'],
            'admin, cash,  pick-up' => ['admin', 'cash',  'pick_up'],
            'admin, cash,  dine-in' => ['admin', 'cash',  'dine_in'],
        ];
    }

    /**
     * @dataProvider counterOrderCases
     */
    public function test_a_counter_order_is_created(string $role, string $payment, string $type): void
    {
        $actor = $role === 'admin' ? $this->admin() : $this->staff();

        [$response, $order] = $this->submitAndFetch($actor, $this->payload([
            'payment_method' => $payment,
            'order_type'     => $type,
            'table_number'   => $type === 'dine_in' ? '9' : '',
        ]));

        $response->assertRedirect(route('admin.home'));
        $response->assertSessionHasNoErrors();

        $this->assertNotNull($order, 'the counter order never reached the database');
        $this->assertSame($type, $order->type);
        $this->assertSame($payment, $order->payment_method);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('pending', $order->status);
        $this->assertSame((int) $actor->id, (int) $order->processed_by);
        $this->assertEqualsWithDelta($this->pair()['total'], (float) $order->total, 0.001);

        // The line itself, not just the header row.
        $this->assertSame(1, DB::table('order_items')->where('order_id', $order->id)->count());
    }

    public function test_the_order_appears_on_the_active_orders_board(): void
    {
        $staff = $this->staff();

        [, $order] = $this->submitAndFetch($staff, $this->payload());
        $this->assertNotNull($order);

        $board = $this->actingAs($staff, 'admin')->get('/admin/home');

        $board->assertOk();
        $board->assertSee($order->order_number);
        $this->assertTrue(
            $board->viewData('pendingOrders')->contains('id', $order->id),
            'the order was created but the board did not list it'
        );
    }

    // ══════════ the PWD / Senior discount ══════════

    public function test_a_discount_with_name_and_id_is_applied(): void
    {
        [$response, $order] = $this->submitAndFetch($this->staff(), $this->payload([
            'discount_type'             => 'pwd',
            'discount_beneficiary_name' => 'Juan Dela Cruz',
            'discount_beneficiary_id'   => 'PWD-12345',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);
        $this->assertSame('pwd', $order->discount_type);
        $this->assertSame('Juan Dela Cruz', $order->discount_beneficiary_name);
        // 20% of the line, and staff verified the card in person, so no
        // photo-verification step: approved on the spot.
        $expectedDiscount = round($this->pair()['total'] * 0.20, 2);
        $this->assertEqualsWithDelta($expectedDiscount, (float) $order->discount_amount, 0.001);
        $this->assertEqualsWithDelta(
            round($this->pair()['total'] - $expectedDiscount, 2),
            (float) $order->total,
            0.001
        );
        $this->assertSame('approved', $order->discount_status);
    }

    public function test_a_discount_without_name_and_id_is_refused_and_says_why(): void
    {
        $before = DB::table('orders')->count();

        $response = $this->submit($this->staff(), $this->payload([
            'discount_type'             => 'senior',
            'discount_beneficiary_name' => '',
            'discount_beneficiary_id'   => '',
        ]));

        $response->assertSessionHasErrors('discount_type');
        $this->assertSame($before, DB::table('orders')->count(), 'a refused order must not be saved');
    }

    /**
     * The regression guard for the actual bug.
     *
     * A submission carrying no discount at all must succeed. This is the exact
     * shape the browser now sends with the checkbox unticked, because the three
     * discount controls are disabled and disabled controls are not submitted.
     */
    public function test_an_order_with_no_discount_fields_succeeds(): void
    {
        [$response, $order] = $this->submitAndFetch($this->staff(), $this->payload());

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);
        $this->assertNull($order->discount_type);
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.001);
    }

    /**
     * The other half of the guard, on the markup itself.
     *
     * The controls must ship disabled. If this fails, the browser is once again
     * free to post discount_type=pwd on an order nobody asked to discount, and
     * every counter order starts bouncing again.
     */
    public function test_the_discount_controls_ship_disabled(): void
    {
        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->content();

        foreach ([
            'discount_type',
            'discount_beneficiary_name',
            'discount_beneficiary_id',
        ] as $field) {
            $this->assertMatchesRegularExpression(
                '/<(?:select|input)\b[^>]*\bname="' . preg_quote($field, '/') . '"[^>]*\bdisabled\b[^>]*>/is',
                $html,
                "{$field} must be rendered disabled, or an unticked discount box submits it anyway"
            );
        }

        // And the script that re-enables them with the checkbox is present.
        $this->assertStringContainsString('function setManualDiscountEnabled', $html);
    }

    /**
     * A rejected submission must come back visible. The modal is closed on
     * reload, so the error block inside it is only useful if something reopens
     * it — otherwise staff get the silent no-op this whole file is about.
     */
    public function test_a_rejected_submission_reopens_the_modal_with_the_error(): void
    {
        $this->submit($this->staff(), $this->payload([
            'discount_type'             => 'pwd',
            'discount_beneficiary_name' => '',
            'discount_beneficiary_id'   => '',
        ]));

        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->content();

        $this->assertStringContainsString('Please provide the beneficiary name and ID number.', $html);
        $this->assertStringContainsString('id="manualOrderErrors"', $html);
        // The reopen routine only fires when this page load is a bounced
        // manual order, which it detects from the flashed amount_paid.
        $this->assertStringContainsString('reopenRejectedManualOrder', $html);
        $this->assertStringContainsString('const failed = true', $html);
    }

    public function test_an_empty_cart_is_refused(): void
    {
        $before = DB::table('orders')->count();

        $payload = $this->payload();
        unset($payload['items']);

        $this->submit($this->staff(), $payload)->assertSessionHasErrors('items');

        $this->assertSame($before, DB::table('orders')->count());
    }
}
