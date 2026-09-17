<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Reported live: a customer added 3 of an item to the cart with only 1 in
 * stock and the "Out of Stock" state only surfaced after they tried to place
 * the order. Investigation found addToCart() and placeOrder() already
 * re-check quantity against CURRENT stock correctly (both scale by servings,
 * not just "stock > 0") — the actual gap was AuthController::updateCart(),
 * the cart page's +/- quantity control, which wrote any quantity into the
 * session with no stock check at all. This file pins the fix: updateCart()
 * now refuses the same way addToCart() does, and MenuItem::remainingServings()
 * / isLowOnIngredientStock() (the low-stock indicator support, against
 * config('inventory.low_stock_threshold')) are correct.
 */
class CartQuantityStockGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function inventory(float $quantity, float $lowStockAlert = 2): Inventory
    {
        return Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'CQSG Ingredient ' . uniqid(),
            'item_code'       => 'CQSG-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => $quantity,
            'unit'            => 'pc',
            'low_stock_alert' => $lowStockAlert,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);
    }

    private function item(): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => 1,
            'name'          => 'CQSG Item ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function cartFor(MenuItem $item, int $qty): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => $qty,
            'options'      => [],
        ]];
    }

    // ── updateCart() stock guard ────────────────────────────────────────

    public function test_updateCart_refuses_a_quantity_the_stock_cannot_cover(): void
    {
        $item = $this->item();
        $inv = $this->inventory(1);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        $key = (string) $item->id;
        $session = ['cart' => $this->cartFor($item, 1), 'branch_id' => 1, 'order_type' => 'pick_up'];

        $this->withSession($session)
            ->putJson('/customer/cart/update/' . $key, ['quantity' => 3])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'max_quantity' => 1]);

        // Refused server-side — session must be left at the pre-refusal quantity.
        $this->assertSame(1, (int) session('cart')[$key]['quantity']);
    }

    public function test_updateCart_still_accepts_a_quantity_within_stock(): void
    {
        $item = $this->item();
        $inv = $this->inventory(5);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        $key = (string) $item->id;
        $session = ['cart' => $this->cartFor($item, 1), 'branch_id' => 1, 'order_type' => 'pick_up'];

        $this->withSession($session)
            ->putJson('/customer/cart/update/' . $key, ['quantity' => 4])
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('lines.' . $key . '.quantity', 4);

        $this->assertSame(4, (int) session('cart')[$key]['quantity']);
    }

    public function test_updateCart_refusal_via_plain_form_request_redirects_with_an_error(): void
    {
        $item = $this->item();
        $inv = $this->inventory(1);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        $key = (string) $item->id;
        $session = ['cart' => $this->cartFor($item, 1), 'branch_id' => 1, 'order_type' => 'pick_up'];

        $this->withSession($session)
            ->put('/customer/cart/update/' . $key, ['quantity' => 3])
            ->assertRedirect('/customer/cart');

        $this->assertSame(1, (int) session('cart')[$key]['quantity']);
    }

    // ── MenuItem::remainingServings() / isLowOnIngredientStock() ───────

    public function test_remainingServings_reflects_the_binding_ingredient(): void
    {
        $item = $this->item();
        MenuItemIngredient::create([
            'menu_item_id' => $item->id, 'inventory_id' => $this->inventory(50)->id, 'quantity_used' => 10,
        ]); // 5 servings
        MenuItemIngredient::create([
            'menu_item_id' => $item->id, 'inventory_id' => $this->inventory(9)->id, 'quantity_used' => 3,
        ]); // 3 servings — the binding constraint

        $this->assertSame(3, $item->fresh()->remainingServings());
    }

    public function test_remainingServings_is_null_when_there_is_no_recipe(): void
    {
        $this->assertNull($this->item()->fresh()->remainingServings());
    }

    public function test_isLowOnIngredientStock_is_true_at_or_below_the_configured_threshold(): void
    {
        $threshold = (int) config('inventory.low_stock_threshold', 3);

        $item = $this->item();
        // Remaining servings lands exactly on the configured cutoff, regardless
        // of the ingredient's own (unrelated) admin low_stock_alert.
        $inv = $this->inventory($threshold, 999);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        $this->assertTrue($item->fresh()->isLowOnIngredientStock());
        $this->assertSame($threshold, $item->fresh()->remainingServings());
    }

    public function test_isLowOnIngredientStock_is_false_above_the_configured_threshold(): void
    {
        $threshold = (int) config('inventory.low_stock_threshold', 3);

        $item = $this->item();
        $inv = $this->inventory($threshold + 1, 999);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        $this->assertFalse($item->fresh()->isLowOnIngredientStock());
    }

    public function test_isLowOnIngredientStock_is_false_when_comfortably_stocked(): void
    {
        $item = $this->item();
        $inv = $this->inventory(500, 2);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        $this->assertFalse($item->fresh()->isLowOnIngredientStock());
    }

    public function test_isLowOnIngredientStock_is_false_once_actually_out_of_stock(): void
    {
        $item = $this->item();
        $inv = $this->inventory(0, 2);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        // Out of stock is its own state, handled by the existing OOS badge —
        // not "low stock".
        $this->assertFalse($item->fresh()->isLowOnIngredientStock());
        $this->assertFalse($item->fresh()->hasIngredientStock());
    }

    // ── low-stock badge on the menu grid + item page ────────────────────

    public function test_the_menu_grid_shows_the_low_stock_badge_with_the_remaining_count(): void
    {
        $item = $this->item();
        $item->update(['name' => 'CQSG Low Stock Badge Probe ' . random_int(1000, 9999)]);
        $inv = $this->inventory(2, 2);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/items/' . $item->category_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($item->name, $html);
        $this->assertStringContainsString('2 stocks left', $html);
    }

    public function test_the_item_page_shows_the_low_stock_notice(): void
    {
        $item = $this->item();
        $inv = $this->inventory(2, 2);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/item/' . $item->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Low Stock', $html);
        $this->assertStringContainsString('2 stocks left', $html);
        // Still orderable — unlike the OOS badge, Add to cart stays enabled.
        $this->assertStringContainsString('Add to cart', $html);
    }
}
