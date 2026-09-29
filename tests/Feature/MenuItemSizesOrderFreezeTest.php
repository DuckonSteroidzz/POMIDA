<?php

namespace Tests\Feature;

use App\Models\MenuItemSizeIngredient;
use App\Models\Order;
use App\Services\InventoryDeductionService;
use App\Services\MenuItemSizes;
use App\Services\ProfitCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\MenuItemSizeOrderFixtures;
use Tests\TestCase;

/**
 * Menu Item Sizes, Phase 2 — placement freezes the size, completion deducts
 * the freeze (scenarios 8-12), and costing follows it.
 *
 * The rule under test: once an order exists, NOTHING that happens to the size
 * definition — archive, deactivate, recipe change, price change, deletion —
 * changes what that order deducts or whether it can be completed. And the
 * size's base recipe (an EMPTY shelf in these fixtures) is never read for a
 * sized line: if it were, completion would throw "Not enough".
 */
class MenuItemSizesOrderFreezeTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    /** Place a guest pick-up order for $qty x Large and return it. */
    private function placeLarge(array $f, $branch, int $qty = 2): Order
    {
        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], $qty))
            ->assertRedirect(route('customer.orders'));

        return $this->latestOrderAt($branch);
    }

    private function assertCompleted(Order $order): void
    {
        $this->assertSame('completed', $order->fresh()->status, 'the order completed');
    }

    // ══════════ 8. Placement records the size and freezes its recipe ══════════

    public function test_placing_a_sized_order_records_the_size_and_freezes_its_recipe(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);

        $order = $this->placeLarge($f, $branch, 2);
        $line = $order->items()->sole();

        $this->assertSame($f['large']->id, (int) $line->menu_item_size_id);
        $this->assertSame('Large', $line->size_name);
        $this->assertSame('Test Coffee', $line->item_name, 'item_name stays the item\'s own snapshot');
        $this->assertSame('Test Coffee (Large)', $line->displayName());
        $this->assertSame('150.00', (string) $line->item_price);

        // Exactly Large's recipe, per ONE unit — not Regular's, not the base.
        $this->assertSame([$f['largeInv']->id => '2.000'], $this->frozenRows($line->id));

        // Placing deducts nothing, as ever.
        $this->assertSame(100.0, $this->rawQty($f['largeInv']));
    }

    // ══════════ 9. Archived (or deactivated) after placement ══════════

    public function test_archiving_the_size_after_placement_does_not_block_completion(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $order = $this->placeLarge($f, $branch, 2);

        app(MenuItemSizes::class)->archive($f['large']);
        $this->assertNotNull($this->rawSize($f['large']->id)->archived_at);

        $this->completeAs($this->sizeOwner(), $order);

        $this->assertCompleted($order);
        $this->assertSame(96.0, $this->rawQty($f['largeInv']), 'the frozen 2 per unit x 2');
        $this->assertSame(0.0, $this->rawQty($f['baseInv']), 'the base recipe was never touched');
        $this->assertSame(100.0, $this->rawQty($f['regInv']));

        $movement = DB::table('stock_movements')
            ->where('reference_id', $order->id)->where('inventory_id', $f['largeInv']->id)->sole();
        $this->assertSame('out', $movement->movement_type);
        $this->assertEquals(4, (float) $movement->amount);
        $this->assertStringContainsString('2x Test Coffee (Large)', $movement->reason);
    }

    public function test_deactivating_the_size_after_placement_does_not_block_completion(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $order = $this->placeLarge($f, $branch, 1);

        app(MenuItemSizes::class)->update($f['large'], 150, false);

        $this->completeAs($this->sizeOwner(), $order);

        $this->assertCompleted($order);
        $this->assertSame(98.0, $this->rawQty($f['largeInv']));
    }

    // ══════════ 10. Recipe / price changed after placement ══════════

    public function test_changing_the_size_recipe_and_price_after_placement_changes_nothing_for_the_placed_order(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $order = $this->placeLarge($f, $branch, 2);

        // Large is re-costed and re-recipe'd while the order waits.
        MenuItemSizeIngredient::where('menu_item_size_id', $f['large']->id)->delete();
        $newInv = $this->sizeInventory($branch->id, 100, 50, 'NewLarge');
        $this->sizeRecipeLine($f['large'], $newInv, 5);
        app(MenuItemSizes::class)->update($f['large'], 999, true);

        $this->completeAs($this->sizeOwner(), $order);
        $this->assertCompleted($order);

        $this->assertSame(96.0, $this->rawQty($f['largeInv']), 'the ORIGINAL recipe was deducted');
        $this->assertSame(100.0, $this->rawQty($newInv), 'the new recipe was not');

        $line = $order->items()->sole();
        $this->assertSame('150.00', (string) $line->item_price, 'charged price unchanged');
        // COGS snapshot = frozen recipe x unit cost at completion: 2 x ₱3.
        $this->assertSame('6.00', (string) $line->ingredient_cost);

        // And the profit report carries exactly that: revenue 2 x 150, cost 2 x 6.
        $profit = app(ProfitCalculationService::class)->forRange(
            now()->subDay(), now()->addDay(), $branch->id
        );
        $this->assertEquals(300.0, $profit['gross_revenue']);
        $this->assertEquals(12.0, $profit['cogs']);
        $this->assertSame(0, $profit['legacy_fallback_count'], 'costed from the snapshot, not today\'s base recipe');

        // A NEW order gets the NEW recipe and price.
        $this->flushSession();
        $second = $this->placeLarge($f, $branch, 1);
        $this->assertSame([$newInv->id => '5.000'], $this->frozenRows($second->items()->sole()->id));
        $this->assertSame('999.00', (string) $second->items()->sole()->item_price);
    }

    // ══════════ 11. The size row deleted after placement ══════════

    public function test_deleting_the_size_row_keeps_the_snapshot_and_completion_still_works(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $order = $this->placeLarge($f, $branch, 2);
        $lineId = $order->items()->sole()->id;

        // Phase 1 has no per-size delete; the row goes only when its item is
        // permanently deleted (FK cascade). Delete it directly to prove the
        // order line does not depend on it at all.
        $f['large']->delete();
        $this->assertNull($this->rawSize($f['large']->id));

        $raw = DB::table('order_items')->where('id', $lineId)->first();
        $this->assertNull($raw->menu_item_size_id, 'the live link was cut (SET NULL)');
        $this->assertSame('Large', $raw->size_name, 'the snapshot was not');
        $this->assertSame([$f['largeInv']->id => '2.000'], $this->frozenRows($lineId), 'nor was the frozen recipe');

        $this->completeAs($this->sizeOwner(), $order);
        $this->assertCompleted($order);
        $this->assertSame(96.0, $this->rawQty($f['largeInv']));
    }

    public function test_a_frozen_line_whose_inventory_row_was_permanently_deleted_still_completes(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $shot = $this->addOn($f['item'], $branch, 20);
        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], 1, [$shot]))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);

        // The inventory row itself is permanently deleted: nothing is left on
        // any shelf to take, and its frozen line goes with it (FK cascade) —
        // completion must not stop on "Inventory item #N is missing".
        DB::table('inventory')->where('id', $f['largeInv']->id)->delete();
        $this->assertSame([], $this->frozenRows($order->items()->sole()->id));

        $this->completeAs($this->sizeOwner(), $order);
        $this->assertCompleted($order);
        $this->assertSame(0.0, $this->rawQty($f['baseInv']), 'and it never fell back to the base recipe');
    }

    // ══════════ 12. Unsized lines unchanged ══════════

    public function test_an_unsized_line_is_placed_and_completed_exactly_as_before_even_beside_a_sized_line(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $teaInv = $this->sizeInventory($branch->id, 10);
        $tea = $this->unsizedItem($branch, $teaInv);

        $this->placePickUp($branch, $this->cartLine($tea, null, 3) + $this->cartLine($f['item'], $f['regular'], 2))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);

        $teaLine = $order->items()->where('menu_item_id', $tea->id)->sole();
        $this->assertNull($teaLine->size_name);
        $this->assertSame([], $this->frozenRows($teaLine->id));

        // The tea's base recipe changes before completion: an unsized line
        // keeps today's behaviour and deducts its LIVE base recipe.
        DB::table('menu_item_ingredients')->where('menu_item_id', $tea->id)->update(['quantity_used' => 2]);

        $this->completeAs($this->sizeOwner(), $order);
        $this->assertCompleted($order);
        $this->assertSame(4.0, $this->rawQty($teaInv), 'unsized: live base recipe, 3 x 2');
        $this->assertSame(98.0, $this->rawQty($f['regInv']), 'sized: frozen Regular recipe, 2 x 1');
    }

    // ══════════ Open orders after the size changes ══════════

    public function test_an_open_order_for_an_archived_size_still_holds_its_stock_and_breaks_nothing(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $this->placeLarge($f, $branch, 2);

        app(MenuItemSizes::class)->archive($f['large']);

        // committedQuantities() reads the freeze — it neither throws on the
        // archived size nor forgets the stock the order holds.
        $committed = app(InventoryDeductionService::class)->committedQuantities();
        $this->assertEquals(4.0, $committed[$f['largeInv']->id]);
        $this->assertArrayNotHasKey($f['baseInv']->id, $committed);

        // Every page built on it still renders.
        $session = ['branch_id' => $branch->id, 'order_type' => 'pick_up'];
        $this->withSession($session)->get(route('customer.menu'))->assertOk();
        $this->withSession($session)->get(route('customer.item', $f['item']->id))->assertOk();
        $this->withSession($session)->get(route('customer.items', $f['item']->category_id))->assertOk();
        $this->actingAs($this->sizeOwner(), 'admin')->get('/admin/home')->assertOk();
    }

    public function test_the_freeze_happens_inside_the_order_transaction(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);

        // Make the frozen-row insert fail: the order and its lines must roll
        // back with it — never an order line with nothing frozen.
        DB::beforeExecuting(function (string $query) {
            if (str_starts_with($query, 'insert into `order_item_size_ingredients`')) {
                throw new \RuntimeException('forced freeze failure');
            }
        });

        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], 1))
            ->assertSessionHasErrors('error');

        $this->assertCount(0, $this->ordersAt($branch), 'no order survives a failed freeze');
    }
}
