<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cart "+"/"-": instant on-screen update, no page navigation, and a
 * debounced background sync that MUST be flushed before Place Order.
 *
 * WHY THE FLUSH MATTERS
 * --------------------
 * OrderController::placeOrder() rebuilds the order from the SESSION cart, not
 * from the posted items[] array. So a quantity the customer tapped but that is
 * still sitting in a 400ms debounce timer in the browser would be placed at
 * the STALE server-side quantity. confirmOrderNow() awaits flushCartSync()
 * before submitting; these tests pin both the server contract that makes the
 * sync possible and the markup that guarantees the flush.
 *
 * DatabaseTransactions: every row created here (orders, and the throwaway
 * inventory / menu_item / recipe rows the stock test needs) is rolled back at
 * the end of each test, so there is no high-water-mark cleanup and no
 * pre-existing row — inventory id 500 included — is ever touched.
 */
class CartQuantityInstantSyncTest extends TestCase
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

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();
    }

    private function cartFor(MenuItem $item, int $qty = 2): array
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

    // ══════════════════════════════════════════════════════════════════
    // 1. The server contract behind the background sync
    // ══════════════════════════════════════════════════════════════════

    public function test_update_cart_returns_json_with_the_authoritative_subtotal_when_json_is_requested(): void
    {
        $customer = $this->customer();
        $item = $this->item();
        $key = (string) $item->id;

        $response = $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item, 2), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->putJson("/customer/cart/update/{$key}", ['quantity' => 5]);

        $response->assertOk()
            ->assertJson([
                'success'  => true,
                'subtotal' => round($item->price * 5, 2),
            ])
            ->assertJsonPath("lines.{$key}.quantity", 5);

        $this->assertSame(5, session('cart')[$key]['quantity'], 'the session cart was not updated');
    }

    public function test_update_cart_still_redirects_for_a_plain_form_request(): void
    {
        // The seated-guest cart flow and OrderingDuringActiveOrderTest both hit
        // this endpoint as a normal PUT and expect a redirect back to the cart.
        $customer = $this->customer();
        $item = $this->item();
        $key = (string) $item->id;

        $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item, 1), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->put("/customer/cart/update/{$key}", ['quantity' => 3])
            ->assertRedirect('/customer/cart');

        $this->assertSame(3, session('cart')[$key]['quantity']);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. The race: a quantity synced immediately before Place Order
    // ══════════════════════════════════════════════════════════════════

    /**
     * The end-to-end shape of "flush, then submit": the background sync lands
     * (a JSON PUT), then Place Order is posted. The order must reflect the
     * final tapped quantity, because placeOrder() reads the session cart the
     * sync just wrote.
     *
     * If confirmOrderNow() ever stops awaiting flushCartSync() (sabotage a),
     * the sync would NOT have landed first and the order would carry the old
     * quantity — test_confirm_order_now_flushes_before_submitting below pins
     * the markup that prevents that.
     */
    public function test_place_order_reflects_a_quantity_synced_just_before_submit(): void
    {
        $customer = $this->customer();
        $item = $this->item();
        $key = (string) $item->id;

        $before = (int) Order::max('id');

        $session = ['cart' => $this->cartFor($item, 2), 'branch_id' => 1, 'order_type' => 'pick_up'];

        // The debounced sync flushes: customer tapped 2 -> 6.
        $this->actingAs($customer, 'customer')
            ->withSession($session)
            ->putJson("/customer/cart/update/{$key}", ['quantity' => 6])
            ->assertOk();

        $session['cart'][$key]['quantity'] = 6;

        // Then Place Order — the posted items[] still says the pre-tap 2, which
        // placeOrder() ignores in favour of the session cart.
        $this->actingAs($customer, 'customer')
            ->withSession($session)
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        $order = Order::where('id', '>', $before)->orderByDesc('id')->firstOrFail();
        $this->assertSame(6, (int) $order->items->firstOrFail()->quantity,
            'the order was placed against a stale quantity, not the synced one');
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. Markup guards (this codebase tests JS behaviour by asserting on the
    //    rendered source — see OrderConfirmModalTest)
    // ══════════════════════════════════════════════════════════════════

    private function cartHtml(): string
    {
        $item = $this->item();

        return $this->actingAs($this->customer(), 'customer')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();
    }

    public function test_the_quantity_controls_no_longer_submit_a_form_or_navigate(): void
    {
        $html = $this->cartHtml();

        // The old implementation wrapped each +/- in its own POST form and the
        // number field self-submitted on change. None of that should remain.
        $this->assertStringNotContainsString('onchange="this.form.submit()"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<form[^>]*cart\/update/s',
            $html,
            'the cart still ships a form that navigates on a quantity change'
        );

        // The instant client-side handlers are what replace it.
        $this->assertStringContainsString("cartChangeQty(", $html);
        $this->assertStringContainsString('data-cart-qty=', $html);
    }

    /**
     * Sabotage (a) guard: Place Order must await the debounced sync first.
     */
    public function test_confirm_order_now_flushes_the_debounced_sync_before_submitting(): void
    {
        $html = $this->cartHtml();

        $this->assertMatchesRegularExpression(
            '/async function confirmOrderNow\s*\(/',
            $html,
            'confirmOrderNow() must be async so it can await the sync flush'
        );

        // The await of flushCartSync() must sit BEFORE form.submit().
        $this->assertMatchesRegularExpression(
            '/await\s+flushCartSync\(\)[\s\S]*?form\.submit\(\)/',
            $html,
            'confirmOrderNow() submits the order without first flushing the pending quantity sync'
        );
    }

    /**
     * Sabotage (b) guard: the sync is debounced (300–500ms), not fired on
     * every tap. cartChangeQty() schedules via a timer; it must not fetch
     * directly.
     */
    public function test_the_quantity_sync_is_debounced_not_fired_per_tap(): void
    {
        $html = $this->cartHtml();

        $this->assertMatchesRegularExpression(
            '/CART_SYNC_DEBOUNCE_MS\s*=\s*(3\d\d|4\d\d|500)\b/',
            $html,
            'the debounce interval must be a value in the 300–500ms range'
        );
        $this->assertStringContainsString('setTimeout(cartFlushDirty, CART_SYNC_DEBOUNCE_MS)', $html);

        // cartChangeQty() itself must not contain a fetch( — it schedules.
        if (preg_match('/function cartChangeQty\s*\([^)]*\)\s*\{(.*?)\n        \}/s', $html, $m)) {
            $this->assertStringNotContainsString('fetch(', $m[1],
                'cartChangeQty() fires a network request on every tap instead of debouncing');
        } else {
            $this->fail('could not isolate cartChangeQty() body');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. Sabotage (c) guard: the server is still the final stock authority
    // ══════════════════════════════════════════════════════════════════

    /**
     * Even if the client lets a quantity past the stock limit through (a
     * broken clamp), the existing placeOrder() stock re-validation must still
     * catch it. This exercises that path directly with a throwaway
     * recipe-backed item whose single ingredient only covers 3 servings.
     */
    public function test_place_order_still_rejects_a_quantity_that_exceeds_ingredient_stock(): void
    {
        $customer = $this->customer();

        $inventory = Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'CQIST Ingredient ' . uniqid(),
            'item_code'       => 'CQIST-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => 30,      // 10 per serving -> 3 servings max
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);

        $menuItem = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => 1,
            'name'          => 'CQIST Item ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $menuItem->id,
            'inventory_id'  => $inventory->id,
            'quantity_used' => 10,
        ]);

        $before = (int) Order::max('id');
        $key = (string) $menuItem->id;

        $session = ['cart' => $this->cartFor($menuItem, 3), 'branch_id' => 1, 'order_type' => 'pick_up'];

        // Client "allowed" 9; server must refuse.
        $this->actingAs($customer, 'customer')->withSession($session)
            ->putJson("/customer/cart/update/{$key}", ['quantity' => 9])->assertOk();
        $session['cart'][$key]['quantity'] = 9;

        $this->actingAs($customer, 'customer')->withSession($session)
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $menuItem->id, 'quantity' => 9]],
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Order::where('id', '>', $before)->count(),
            'an over-stock quantity created an order anyway');
    }
}
