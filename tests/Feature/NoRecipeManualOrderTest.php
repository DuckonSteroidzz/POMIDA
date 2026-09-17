<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * No-recipe guard on the counter / manual order flow (Phase 3 audit, Finding #9).
 *
 * THE GAP
 * -------
 * The customer path already refuses to sell an item with no recipe set at all
 * (MenuItem::orderBlockedReason(), enforced in Customer\OrderController). The
 * counter flow (AdminController::storeManualOrder(), the walk-in order staff key
 * in directly) never applied the same guard: staff could add a no-recipe item to
 * a manual order, and completeOrder() would later deduct nothing for that line
 * since it has no bill of materials, exactly the silent-non-deduction failure
 * the customer-side guard exists to prevent.
 *
 * This file proves the guard now exists on both sides staff touch: the picker
 * on /admin/home (badge + disabled card) and the server-side refusal in
 * storeManualOrder() itself, which is the one that actually matters since the
 * picker is only a convenience.
 */
class NoRecipeManualOrderTest extends TestCase
{
    use DatabaseTransactions;

    private function inventory(float $quantity = 1000): Inventory
    {
        return Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'NRM Ingredient ' . uniqid(),
            'item_code'       => 'NRM-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => $quantity,
            'unit'            => 'g',
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
            'name'          => 'NRM Item ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ], $attrs));
    }

    private function itemWithRecipe(): MenuItem
    {
        $item = $this->item();
        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $this->inventory()->id,
            'quantity_used' => 1,
        ]);

        return $item->fresh();
    }

    private function itemWithoutRecipe(): MenuItem
    {
        return $this->item()->fresh();
    }

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function payloadFor(MenuItem $item): array
    {
        return [
            'branch_id'      => 1,
            'order_type'     => 'pick_up',
            'table_number'   => '',
            'payment_method' => 'cash',
            'amount_paid'    => '1000',
            'items'          => [
                $item->id => [
                    'menu_item_id' => (string) $item->id,
                    'quantity'     => '1',
                ],
            ],
        ];
    }

    private function submit(array $payload)
    {
        return $this->actingAs($this->staff(), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', $payload);
    }

    // ══════════ acceptance: an item WITH a recipe still works ══════════

    public function test_a_manual_order_for_an_item_with_a_recipe_succeeds(): void
    {
        $item   = $this->itemWithRecipe();
        $before = DB::table('orders')->max('id');

        $response = $this->submit($this->payloadFor($item));

        $response->assertRedirect(route('admin.home'));
        $response->assertSessionHasNoErrors();

        $order = DB::table('orders')->where('id', '>', $before ?? 0)->orderByDesc('id')->first();
        $this->assertNotNull($order, 'a manual order for a recipe-backed item must still be created');
        $this->assertSame(
            1,
            DB::table('order_items')->where('order_id', $order->id)->where('menu_item_id', $item->id)->count()
        );
    }

    // ══════════ refusal: an item with NO recipe is hard-refused ══════════

    public function test_a_manual_order_for_an_item_with_no_recipe_is_refused(): void
    {
        $item   = $this->itemWithoutRecipe();
        $this->assertTrue($item->isMissingRecipe(), 'fixture item must genuinely have no recipe');
        $before = DB::table('orders')->count();

        $response = $this->submit($this->payloadFor($item));

        $response->assertSessionHasErrors('items');
        $this->assertSame($before, DB::table('orders')->count(), 'a no-recipe line must not reach the database');
    }

    public function test_the_refusal_names_the_item_and_the_reason(): void
    {
        $item = $this->itemWithoutRecipe();

        $response = $this->submit($this->payloadFor($item));

        $errors = $response->getSession()->get('errors')->getBag('default')->get('items');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString($item->name, $errors[0]);
        $this->assertStringContainsString('no recipe', strtolower($errors[0]));
    }

    // ══════════ the picker on /admin/home ══════════

    public function test_the_picker_disables_a_no_recipe_item_and_badges_it(): void
    {
        $item = $this->itemWithoutRecipe();

        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->content();

        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*\bdata-id="' . $item->id . '"[^>]*\bdisabled\b[^>]*>/is',
            $html,
            'a no-recipe item must ship as a disabled card in the manual order picker'
        );
        $this->assertStringContainsString('No Recipe Set', $html);
    }

    public function test_the_picker_leaves_a_recipe_backed_item_clickable(): void
    {
        $item = $this->itemWithRecipe();

        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->content();

        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*\bdata-id="' . $item->id . '"[^>]*\bonclick="addManualItem\(' . $item->id . '\)"/is',
            $html,
            'a recipe-backed item must remain clickable in the manual order picker'
        );
    }
}
