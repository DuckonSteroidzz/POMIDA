<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\MenuItemSizeOrderFixtures;
use Tests\TestCase;

/**
 * Menu Item Sizes, Phase 2 — branch independence through the whole order
 * flow (scenarios 19-20). Phase 1 proved it for the definitions; this proves
 * it for cart, checkout, the counter, the freeze and completion.
 *
 * Two branches each sell their OWN "Test Coffee", sized independently with
 * their own inventory. Names are equal on purpose (duplicate names are normal
 * here); every assertion is by id.
 */
class MenuItemSizesOrderBranchTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    // ══════════ 19. Branch A's Large never touches Branch B ══════════

    public function test_an_order_for_branch_a_large_never_touches_branch_b(): void
    {
        $branchA = $this->sizeBranch('A');
        $branchB = $this->sizeBranch('B');
        $a = $this->sizedCoffee($branchA);
        $b = $this->sizedCoffee($branchB);

        $bSizesBefore = DB::table('menu_item_sizes')->where('menu_item_id', $b['item']->id)->orderBy('id')->get()->toArray();
        $bInvBefore = DB::table('inventory')->where('branch_id', $branchB->id)->orderBy('id')->pluck('quantity', 'id')->all();

        $this->placePickUp($branchA, $this->cartLine($a['item'], $a['large'], 2))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branchA);

        // The freeze points only at Branch A's own inventory.
        $frozen = $this->frozenRows($order->items()->sole()->id);
        $this->assertSame([$a['largeInv']->id => '2.000'], $frozen);
        $this->assertSame(
            [$branchA->id],
            DB::table('inventory')->whereIn('id', array_keys($frozen))->distinct()->pluck('branch_id')->map(fn ($id) => (int) $id)->all()
        );

        $this->completeAs($this->sizeStaff($branchA->id), $order);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(96.0, $this->rawQty($a['largeInv']));

        // Branch B: stock, sizes and orders exactly as they were.
        $this->assertSame($bInvBefore, DB::table('inventory')->where('branch_id', $branchB->id)->orderBy('id')->pluck('quantity', 'id')->all());
        $this->assertEquals($bSizesBefore, DB::table('menu_item_sizes')->where('menu_item_id', $b['item']->id)->orderBy('id')->get()->toArray());
        $this->assertCount(0, $this->ordersAt($branchB));
        $this->assertSame(0, DB::table('stock_movements')->whereIn('inventory_id', array_keys($bInvBefore))->count());
    }

    public function test_branch_b_s_size_cannot_be_ordered_on_branch_a_s_item_anywhere(): void
    {
        $branchA = $this->sizeBranch('A');
        $branchB = $this->sizeBranch('B');
        $a = $this->sizedCoffee($branchA);
        $b = $this->sizedCoffee($branchB);

        // Add to cart at Branch A, naming Branch B's Large.
        $this->withSession(['branch_id' => $branchA->id, 'order_type' => 'pick_up'])
            ->from('/customer/menu')
            ->post(route('customer.cart.add'), ['item_id' => $a['item']->id, 'size_id' => $b['large']->id, 'quantity' => 1])
            ->assertSessionHas('error', 'Sorry, that size of Test Coffee is not available.');

        // A hand-built cart line pairing A's item with B's size. CartPricing
        // itself refuses to price it (it only ever looks at the item's OWN
        // size rows) — the Phase 1 resolver at checkout is the second layer.
        $line = $this->cartLine($a['item'], $a['large'], 1);
        $key = array_key_first($line);
        $line[$key]['size_id'] = $b['large']->id;

        $priced = \App\Support\CartPricing::price($line, $branchA->id);
        $this->assertTrue($priced['size_problem']);
        $this->assertNull($priced['lines'][0]['size']);
        $this->assertSame('Sorry, that size of Test Coffee is not available.', $priced['lines'][0]['size_problem']);
        $this->assertEquals(0.0, $priced['subtotal'], 'B\'s price is never charged on A\'s item');

        $this->placePickUp($branchA, $line)
            ->assertSessionHasErrors(['items' => 'Sorry, that size of Test Coffee is not available.']);

        // Branch B's own item in a Branch A checkout: the branch backstop.
        $this->flushSession();
        $this->placePickUp($branchA, $this->cartLine($b['item'], $b['large'], 1))
            ->assertSessionHasErrors('items');

        // The counter, at Branch A, naming B's size.
        $this->counterOrder($this->sizeStaff($branchA->id), $branchA, [
            'x' => ['menu_item_id' => (string) $a['item']->id, 'quantity' => '1', 'size_id' => (string) $b['large']->id],
        ])->assertSessionHasErrors(['items' => 'Sorry, that size of Test Coffee is not available.']);

        $this->assertCount(0, $this->ordersAt($branchA));
        $this->assertCount(0, $this->ordersAt($branchB));
    }

    // ══════════ 20. No literal branch id in this phase's code ══════════

    /**
     * Every file this phase wrote or changed, scanned with comments stripped
     * (a comment elsewhere in AuthController mentions a legacy "branch_id = 1"
     * row). Branch context in this phase always comes from the menu item,
     * order or branch being acted on.
     */
    public function test_no_literal_branch_id_is_introduced_in_this_phases_code(): void
    {
        $files = [
            'app/Support/OrderTransaction.php',
            'app/Support/CartPricing.php',
            'app/Models/OrderItem.php',
            'app/Models/OrderItemSizeIngredient.php',
            'app/Models/MenuItem.php',
            'app/Services/InventoryDeductionService.php',
            'app/Http/Controllers/Customer/OrderController.php',
            'app/Http/Controllers/Customer/AuthController.php',
            'app/Http/Controllers/Admin/AdminController.php',
            'database/migrations/2026_09_27_170000_add_size_snapshot_to_order_items.php',
            'database/migrations/2026_09_27_170100_create_order_item_size_ingredients_table.php',
        ];

        $pattern = '/branch_id[\'"]?\s*(=>|===?|==|,)\s*\d+|->where\(\s*[\'"]branch_id[\'"]\s*,\s*\d+/';

        foreach ($files as $file) {
            $code = '';
            foreach (token_get_all(file_get_contents(base_path($file))) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            $this->assertDoesNotMatchRegularExpression($pattern, $code, $file . ' hard-codes a branch id');
        }

        // The scan itself can see one (so a pass is not vacuous).
        $this->assertMatchesRegularExpression($pattern, "\$q->where('branch_id', 1)");
        $this->assertMatchesRegularExpression($pattern, "'branch_id' => 2");
    }
}
