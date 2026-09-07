<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Automatic "Out of Stock" from ingredient inventory.
 *
 * An item is out of stock when ANY line of its recipe points at an inventory
 * row that is empty or holds less than one serving needs. This is computed,
 * never stored, and is deliberately INDEPENDENT of the is_available admin
 * toggle: an admin can pull an item off the menu, but the app decides on its
 * own when the kitchen physically cannot make it.
 *
 * The customer sees a badge and a disabled order button; the cart-add and
 * checkout paths refuse the item outright with a plain message.
 */
class AutomaticOutOfStockTest extends TestCase
{
    use DatabaseTransactions;

    private function inventory(float $quantity, string $unit = 'g'): Inventory
    {
        return Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'OOS Ingredient ' . uniqid(),
            'item_code'       => 'OOS-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => $quantity,
            'unit'            => $unit,
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);
    }

    private function item(array $attrs = []): MenuItem
    {
        return MenuItem::create(array_merge([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => 1,
            'name'          => 'OOS Item ' . uniqid(),
            'price'         => 120,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ], $attrs));
    }

    private function recipe(MenuItem $item, Inventory $inv, float $qtyUsed): void
    {
        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $inv->id,
            'quantity_used' => $qtyUsed,
        ]);
    }

    // ── the model method ────────────────────────────────────────────────────

    public function test_a_fully_stocked_recipe_is_in_stock(): void
    {
        $item = $this->item();
        $this->recipe($item, $this->inventory(500), 10);
        $this->recipe($item, $this->inventory(500), 5);

        $this->assertTrue($item->fresh()->hasIngredientStock());
        $this->assertFalse($item->fresh()->isIngredientOutOfStock());
    }

    public function test_any_ingredient_at_zero_makes_the_item_out_of_stock(): void
    {
        $item = $this->item();
        $this->recipe($item, $this->inventory(500), 10);   // plenty
        $this->recipe($item, $this->inventory(0), 5);      // empty

        $this->assertFalse($item->fresh()->hasIngredientStock());
    }

    public function test_an_ingredient_below_one_serving_makes_the_item_out_of_stock(): void
    {
        $item = $this->item();
        // Needs 10 per serving, only 4 on the shelf.
        $this->recipe($item, $this->inventory(4), 10);

        $this->assertFalse($item->fresh()->hasIngredientStock());
    }

    public function test_the_servings_argument_is_respected(): void
    {
        $item = $this->item();
        $this->recipe($item, $this->inventory(15), 10);

        $this->assertTrue($item->fresh()->hasIngredientStock(1), 'one serving needs 10, have 15');
        $this->assertFalse($item->fresh()->hasIngredientStock(2), 'two servings need 20, have 15');
    }

    public function test_an_item_with_no_recipe_is_never_blocked_by_the_ingredient_check(): void
    {
        // hasIngredientStock() is only about inventory levels — with no recipe
        // there is nothing to measure, so it still returns true. Orderability is
        // a separate question, covered by the hasRecipe() tests below.
        $this->assertTrue($this->item()->fresh()->hasIngredientStock());
    }

    // ── the recipe guard: no recipe means not orderable ─────────────────────

    public function test_has_recipe_reflects_recipe_lines_and_the_legacy_link(): void
    {
        $bare = $this->item();
        $this->assertFalse($bare->fresh()->hasRecipe());
        $this->assertTrue($bare->fresh()->isMissingRecipe());
        $this->assertFalse($bare->fresh()->isOrderable());

        $withRecipe = $this->item();
        $this->recipe($withRecipe, $this->inventory(500), 5);
        $this->assertTrue($withRecipe->fresh()->hasRecipe());
        $this->assertTrue($withRecipe->fresh()->isOrderable());

        $legacy = $this->item([
            'inventory_item_id'     => $this->inventory(500)->id,
            'inventory_amount_used' => 1,
        ]);
        $this->assertTrue($legacy->fresh()->hasRecipe());
    }

    public function test_the_recipe_guard_never_rewrites_the_admin_flags(): void
    {
        $item = $this->item(['is_available' => true, 'stock_quantity' => 9]);

        $this->assertTrue($item->fresh()->isMissingRecipe());
        // Computed, not stored — the admin's columns are untouched.
        $this->assertTrue($item->fresh()->is_available);
        $this->assertSame(9, (int) $item->fresh()->stock_quantity);
    }

    public function test_an_item_with_no_recipe_cannot_be_added_to_the_cart(): void
    {
        $item = $this->item(['name' => 'No Recipe Cart Probe']);

        $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/cart/add', ['item_id' => $item->id, 'quantity' => 1]);

        $this->assertSame([], session('cart', []), 'a recipe-less item may not reach the cart');
        $this->assertStringContainsString('no recipe has been set', (string) session('error'));
    }

    public function test_checkout_refuses_an_item_with_no_recipe(): void
    {
        $item = $this->item(['name' => 'No Recipe Checkout Probe']);

        $cart = [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => 1,
            'options'      => [],
        ]];

        $before = Order::max('id');

        $this->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('items');

        $this->assertStringContainsString(
            'no recipe has been set',
            session('errors')->first('items')
        );
        $this->assertSame($before, Order::max('id'), 'no order may be created');
    }

    public function test_the_menu_greys_out_an_item_with_no_recipe(): void
    {
        $item = $this->item(['name' => 'No Recipe Menu Probe ' . random_int(1000, 9999)]);

        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/items/' . $item->category_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($item->name, $html, 'the item stays on the menu');
        $this->assertStringContainsString('No Recipe Set', $html);
    }

    public function test_the_item_page_disables_add_for_an_item_with_no_recipe(): void
    {
        $item = $this->item();

        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/item/' . $item->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No Recipe Set', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*disabled/i', $html);
    }

    public function test_the_legacy_single_ingredient_link_is_honoured(): void
    {
        $inv  = $this->inventory(2, 'pc');
        $item = $this->item([
            'inventory_item_id'     => $inv->id,
            'inventory_amount_used' => 3,
        ]);

        $this->assertFalse($item->fresh()->hasIngredientStock(), 'need 3 pc, have 2');

        $inv->update(['quantity' => 10]);
        $this->assertTrue($item->fresh()->hasIngredientStock());
    }

    public function test_the_stock_check_is_independent_of_the_is_available_toggle(): void
    {
        $item = $this->item(['is_available' => true]);
        $this->recipe($item, $this->inventory(0), 5);

        // Out of stock by ingredients...
        $this->assertFalse($item->fresh()->hasIngredientStock());
        // ...but the admin's manual flag is untouched.
        $this->assertTrue($item->fresh()->is_available, 'the stock check must not rewrite is_available');
    }

    // ── customer menu UI ────────────────────────────────────────────────────

    public function test_the_category_listing_shows_an_out_of_stock_badge_and_keeps_the_item_visible(): void
    {
        $item = $this->item(['name' => 'OOS Visible Probe ' . random_int(1000, 9999)]);
        $this->recipe($item, $this->inventory(0), 5);

        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/items/' . $item->category_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($item->name, $html, 'the item stays on the menu');
        $this->assertStringContainsString('Out of Stock', $html, 'the badge is shown');
    }

    public function test_the_item_details_page_disables_the_add_button_when_out_of_stock(): void
    {
        $item = $this->item();
        $this->recipe($item, $this->inventory(0), 5);

        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/item/' . $item->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Out of Stock', $html);
        // The submit buttons carry the disabled attribute.
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*disabled/i', $html);
    }

    public function test_an_in_stock_item_details_page_keeps_the_add_button_enabled(): void
    {
        $item = $this->item();
        $this->recipe($item, $this->inventory(500), 5);

        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/item/' . $item->id)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Out of Stock', $html);
        $this->assertStringContainsString('Add to cart', $html);
    }

    // ── cart + checkout guards ──────────────────────────────────────────────

    public function test_an_out_of_stock_item_cannot_be_added_to_the_cart(): void
    {
        $item = $this->item(['name' => 'OOS Cart Probe']);
        $this->recipe($item, $this->inventory(0), 5);

        $res = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/cart/add', ['item_id' => $item->id, 'quantity' => 1]);

        $this->assertSame([], session('cart', []), 'nothing may reach the cart');
        $this->assertStringContainsString(
            'out of stock due to ingredient availability',
            (string) session('error')
        );
    }

    public function test_an_in_stock_item_still_adds_to_the_cart(): void
    {
        $item = $this->item();
        $this->recipe($item, $this->inventory(500), 5);

        $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/cart/add', ['item_id' => $item->id, 'quantity' => 1]);

        $this->assertArrayHasKey((string) $item->id, session('cart', []));
    }

    public function test_checkout_refuses_an_out_of_stock_item_with_a_plain_message(): void
    {
        $item = $this->item(['name' => 'OOS Checkout Probe']);
        $inv  = $this->inventory(500);
        $this->recipe($item, $inv, 5);

        $cart = [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => 1,
            'options'      => [],
        ]];

        // Emptied after the item is already in the cart — the hardest path.
        $inv->update(['quantity' => 0]);

        $before = Order::max('id');

        $res = $this->withSession([
            'cart'       => $cart,
            'branch_id'  => 1,
            'order_type' => 'pick_up',
        ])->post('/customer/place-order', [
            'order_type'     => 'pick_up',
            'payment_method' => 'cash',
            'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $res->assertSessionHasErrors('items');
        $this->assertStringContainsString(
            'out of stock due to ingredient availability',
            session('errors')->first('items')
        );
        $this->assertSame($before, Order::max('id'), 'no order may be created');
    }

    public function test_the_cart_page_flags_an_out_of_stock_line_and_disables_place_order(): void
    {
        $item = $this->item(['name' => 'OOS Cart Page Probe']);
        $inv  = $this->inventory(500);
        $this->recipe($item, $inv, 5);

        $cart = [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => 1,
            'options'      => [],
        ]];

        $inv->update(['quantity' => 0]);

        $html = $this->withSession([
            'cart'       => $cart,
            'branch_id'  => 1,
            'order_type' => 'pick_up',
        ])->get('/customer/cart')->assertOk()->getContent();

        $this->assertStringContainsString('out of stock due to ingredient availability', $html);
        $this->assertMatchesRegularExpression('/onclick="openOrderConfirmation\(\)"[^>]*disabled/s', $html);
    }
}
