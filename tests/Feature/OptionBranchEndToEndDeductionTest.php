<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Branch-scoped add-on deduction, END TO END, with the mapping present in TWO
 * branches at once.
 *
 * The investigation into "the Branch 2 add-on had no effect" proved by a real
 * rolled-back probe that option-level branch mapping and order-time deduction
 * are correct, and that the symptom was missing setup (no Branch 2 inventory,
 * no gravy link, no recipe). What it also found is the one shape nothing
 * automated covered: a real checkout followed by a real completion of an
 * order whose add-on has a link in BOTH Main and another branch at the same
 * time. OptionBranchAwareDeductionTest pins the walker
 * (requirementsForLine()) with seeded orders; InventoryDeductionEndToEndTest
 * drives the whole journey but only ever with a single-branch option. This is
 * the manual probe, made permanent:
 *
 *     POST /customer/place-order   ->  PUT /admin/orders/{id}/complete
 *
 * The two links carry DIFFERENT quantities (20 g in Main, 5 g in the other
 * branch) so a deduction taken from the wrong row cannot pass by coincidence.
 *
 * Every row carries the OBDE prefix and runs in DatabaseTransactions against
 * pomida_db_testing, so nothing survives the run.
 */
class OptionBranchEndToEndDeductionTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'OBDE';
    private const MAIN = 1;

    private const MAIN_LINK_QTY = 20.0;
    private const OTHER_LINK_QTY = 5.0;

    // ══════════════════ fixtures ══════════════════

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function otherBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Branch 2 ' . uniqid(),
            'code'      => 'OBD' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function inventoryIn(int $branchId, string $name, float $qty = 100): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'item_code'       => 'OBDI-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => $qty,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 2,
            'is_active'       => true,
        ]);
    }

    private function menuItemIn(int $branchId, Inventory $base, float $baseQty): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => self::PREFIX . ' Fried Chicken ' . uniqid(),
            'price'         => 120,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $base->id,
            'quantity_used' => $baseQty,
        ]);

        return $item;
    }

    /** One global add-on, linked to BOTH branches' gravy at once. */
    private function gravyLinkedInBoth(Inventory $mainGravy, Inventory $otherGravy): MenuOption
    {
        $option = MenuOption::create([
            'name'             => self::PREFIX . ' Extra Gravy ' . uniqid(),
            'additional_price' => 15,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $mainGravy->id,
            'quantity_used'  => self::MAIN_LINK_QTY,
        ]);
        MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $otherGravy->id,
            'quantity_used'  => self::OTHER_LINK_QTY,
        ]);

        return $option;
    }

    /** The session-cart line AuthController::addToCart() writes and CartPricing::price() reads. */
    private function cartFor(MenuItem $item, MenuOption $option, int $qty): array
    {
        return [$item->id . '_' . $option->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price + (float) $option->additional_price,
            'base_price'   => (float) $item->price,
            'quantity'     => $qty,
            'image'        => $item->image,
            'options'      => [[
                'id'    => $option->id,
                'name'  => $option->name,
                'price' => (float) $option->additional_price,
            ]],
        ]];
    }

    /** Place the cart through the real customer checkout at $branchId. Null if refused. */
    private function checkout(MenuItem $item, MenuOption $option, int $qty, int $branchId): ?Order
    {
        $before = (int) Order::max('id');

        $this->withSession([
            'cart'       => $this->cartFor($item, $option, $qty),
            'branch_id'  => $branchId,
            'order_type' => 'pick_up',
        ])->post('/customer/place-order', [
            'order_type'     => 'pick_up',
            'payment_method' => 'cash',
            'items'          => [['menu_item_id' => $item->id, 'quantity' => $qty]],
        ]);

        return Order::where('id', '>', $before)->orderByDesc('id')->first();
    }

    private function complete(Order $order): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/orders/' . $order->id . '/complete');
    }

    /** @return int[]  inventory ids that got a stock_movements row from this order, sorted */
    private function movedInventoryIds(Order $order): array
    {
        $ids = StockMovement::where('reference_id', $order->id)
            ->where('source', 'order')
            ->pluck('inventory_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        sort($ids);

        return $ids;
    }

    private function qty(Inventory $inv): float
    {
        return (float) $inv->fresh()->quantity;
    }

    // ══════════════════ the coverage gap ══════════════════

    public function test_a_branch_2_order_deducts_only_the_branch_2_link_of_an_add_on_mapped_in_both_branches(): void
    {
        $other       = $this->otherBranch();
        $mainGravy   = $this->inventoryIn(self::MAIN, 'Gravy Main');
        $otherGravy  = $this->inventoryIn($other->id, 'Gravy B2');
        $otherChick  = $this->inventoryIn($other->id, 'Chicken B2', 50);

        $option = $this->gravyLinkedInBoth($mainGravy, $otherGravy);
        $item   = $this->menuItemIn($other->id, $otherChick, 10);
        $item->options()->attach($option->id);

        // Fixture sanity — the scenario under test: one option, a live link in
        // EACH branch at the same moment.
        $this->assertSame(2, MenuOptionIngredient::where('menu_option_id', $option->id)->count());
        $option = $option->fresh()->load('ingredients.inventory');
        $this->assertTrue($option->isMappedForBranch(self::MAIN), 'fixture: mapped in Main');
        $this->assertTrue($option->isMappedForBranch($other->id), 'fixture: mapped in Branch 2');

        $order = $this->checkout($item, $option, 2, $other->id);
        $this->assertNotNull($order, 'checkout refused an order both branches\' stock could clearly cover');
        $this->assertSame($other->id, (int) $order->branch_id, 'the order must be a Branch 2 order');

        // Placing must not deduct — stock only leaves the shelf at completion.
        $this->assertSame(100.0, $this->qty($otherGravy));

        $this->complete($order);
        $this->assertSame('completed', $order->fresh()->status, 'the order never reached completed');

        // Branch 2's own rows: 2 servings x (10 g chicken; 5 g gravy).
        $this->assertSame(30.0, $this->qty($otherChick), '2 x 10 g of Branch 2 base recipe');
        $this->assertSame(90.0, $this->qty($otherGravy), '2 x the Branch 2 link (5 g) — NOT the Main link (20 g)');

        // Main's row is not touched: same link, same option, other branch.
        $this->assertSame(100.0, $this->qty($mainGravy), "a Branch 2 order must never draw down Main's gravy");

        // The audit log tells the same story: exactly the two Branch 2 rows,
        // and nothing at all — from any order — for Main's gravy.
        $this->assertSame(
            collect([$otherChick->id, $otherGravy->id])->sort()->values()->all(),
            $this->movedInventoryIds($order),
            'stock_movements must be written for the Branch 2 rows only'
        );
        $this->assertSame(0, StockMovement::where('inventory_id', $mainGravy->id)->count());

        $gravyMove = StockMovement::where('reference_id', $order->id)
            ->where('inventory_id', $otherGravy->id)
            ->firstOrFail();
        $this->assertSame('out', $gravyMove->movement_type);
        $this->assertSame(10.0, (float) $gravyMove->amount, '2 x 5 g');
        $this->assertSame(90.0, (float) $gravyMove->quantity_after);
    }

    public function test_the_mirror_a_main_order_deducts_only_the_main_link_of_the_same_kind_of_add_on(): void
    {
        // The other direction, so the first test cannot pass merely because
        // "the non-Main link always wins".
        $other       = $this->otherBranch();
        $mainGravy   = $this->inventoryIn(self::MAIN, 'Gravy Main');
        $otherGravy  = $this->inventoryIn($other->id, 'Gravy B2');
        $mainChick   = $this->inventoryIn(self::MAIN, 'Chicken Main', 50);

        $option = $this->gravyLinkedInBoth($mainGravy, $otherGravy);
        $item   = $this->menuItemIn(self::MAIN, $mainChick, 10);
        $item->options()->attach($option->id);

        $order = $this->checkout($item, $option, 2, self::MAIN);
        $this->assertNotNull($order, 'checkout refused a Main order the pantry can clearly cover');
        $this->assertSame(self::MAIN, (int) $order->branch_id);

        $this->complete($order);
        $this->assertSame('completed', $order->fresh()->status);

        $this->assertSame(30.0, $this->qty($mainChick), '2 x 10 g of Main base recipe');
        $this->assertSame(60.0, $this->qty($mainGravy), '2 x the Main link (20 g) — NOT the Branch 2 link (5 g)');
        $this->assertSame(100.0, $this->qty($otherGravy), "a Main order must never draw down Branch 2's gravy");

        $this->assertSame(
            collect([$mainChick->id, $mainGravy->id])->sort()->values()->all(),
            $this->movedInventoryIds($order),
            'stock_movements must be written for the Main rows only'
        );
        $this->assertSame(0, StockMovement::where('inventory_id', $otherGravy->id)->count());
    }
}
