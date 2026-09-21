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
use App\Models\OrderItem;
use App\Models\User;
use App\Services\InventoryDeductionService;
use App\Services\ProductionCapacityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRODUCTION CAPACITY + BOTTLENECK DETECTION (Phase 2b).
 *
 * "Given the stock on the shelf right now, how many MORE orders of this menu
 * item can the kitchen produce, and which ingredient runs out first?"
 *
 *     ingredient capacity = floor(available ÷ required per order)
 *     menu capacity       = MIN(every ingredient capacity)
 *     bottleneck          = ARGMIN(the same)
 *
 * The arithmetic is NOT new. App\Services\ProductionCapacityService is an
 * analytics shape around InventoryDeductionService::availabilityBreakdownFor(),
 * which is the same calculation the checkout stock gate already runs — so what
 * this suite really pins down is that the Analytics page and the till can never
 * quote two different numbers for the same pantry.
 *
 * Two distinctions this suite exists to defend:
 *
 *   AVAILABLE ≠ ON HAND. Stock only leaves the shelf when an order is
 *   COMPLETED, so a pending order has claimed ingredients that inventory.quantity
 *   still shows. Capacity subtracts those commitments; see
 *   InventoryDeductionService::committedQuantities().
 *
 *   UNMEASURABLE ≠ ZERO. An item with no recipe and no legacy inventory link
 *   has no capacity to report. Reporting 0 would read as "sold out" — a
 *   different, and false, statement. Same distinction MenuItem::isMissingRecipe()
 *   already draws for the menu grid's "No Recipe Set" badge.
 *
 * Every row created here carries the MPCAP prefix, the file runs in
 * DatabaseTransactions, and tearDown() sweeps anything that outlived the
 * transaction by high-water mark. test_the_suite_runs_against_the_testing_database()
 * proves the target database before any of it.
 */
class ProductionCapacityTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MPCAP';
    private const ORDER_PREFIX = 'MPC-';

    private array $highWater = [];

    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'branches', 'inventory', 'menu_items', 'menu_item_ingredients',
            'menu_options', 'menu_option_ingredients', 'orders', 'order_items', 'users',
        ] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }

        $this->branchA = $this->branch('A');
        $this->branchB = $this->branch('B');
    }

    protected function tearDown(): void
    {
        $orderIds = DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where('order_number', 'like', self::ORDER_PREFIX . '%')
            ->pluck('id');

        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();

        $menuItemIds = DB::table('menu_items')
            ->where('id', '>', $this->highWater['menu_items'])
            ->where('name', 'like', self::PREFIX . '%')
            ->pluck('id');

        $optionIds = DB::table('menu_options')
            ->where('id', '>', $this->highWater['menu_options'])
            ->where('name', 'like', self::PREFIX . '%')
            ->pluck('id');

        DB::table('menu_item_ingredients')->whereIn('menu_item_id', $menuItemIds)->delete();
        DB::table('menu_option_ingredients')->whereIn('menu_option_id', $optionIds)->delete();
        DB::table('menu_item_options')->whereIn('menu_item_id', $menuItemIds)->delete();
        DB::table('menu_options')->whereIn('id', $optionIds)->delete();
        DB::table('menu_items')->whereIn('id', $menuItemIds)->delete();

        DB::table('inventory')
            ->where('id', '>', $this->highWater['inventory'])
            ->where('item_name', 'like', self::PREFIX . '%')
            ->delete();

        DB::table('users')
            ->where('id', '>', $this->highWater['users'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        DB::table('branches')
            ->where('id', '>', $this->highWater['branches'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        $this->assertSame(0, DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX . '%')->count());
        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('inventory')->where('item_name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('branches')->where('name', 'like', self::PREFIX . '%')->count());

        parent::tearDown();
    }

    // ══════════════════════════ fixtures ══════════════════════════

    private function branch(string $label): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Branch ' . $label . ' ' . uniqid(),
            'code'      => 'MPC' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address ' . $label,
            'is_active' => true,
        ]);
    }

    private function inventory(Branch $branch, string $name, float $qty, string $unit = 'g'): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branch->id,
            'item_name'       => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'item_code'       => 'MPC-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => $qty,
            'unit'            => $unit,
            'low_stock_alert' => 1,
            'unit_cost'       => 2,
            'is_active'       => true,
        ]);
    }

    /** @param array<int, array{0: Inventory, 1: float}> $recipe */
    private function menuItem(Branch $branch, string $name, array $recipe = [], float $price = 100): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branch->id,
            'name'          => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'price'         => $price,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        foreach ($recipe as [$inv, $qty]) {
            MenuItemIngredient::create([
                'menu_item_id'  => $item->id,
                'inventory_id'  => $inv->id,
                'quantity_used' => $qty,
            ]);
        }

        return $item;
    }

    /** @param array<int, array{0: Inventory, 1: float}> $ingredients */
    private function option(string $name, array $ingredients): MenuOption
    {
        $option = MenuOption::create([
            'name'             => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'additional_price' => 10,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        foreach ($ingredients as [$inv, $qty]) {
            MenuOptionIngredient::create([
                'menu_option_id' => $option->id,
                'inventory_id'   => $inv->id,
                'quantity_used'  => $qty,
            ]);
        }

        return $option;
    }

    /**
     * An order that has been PLACED but not completed — i.e. one that has
     * committed stock the shelf has not yet given up. 'pending' is one of
     * InventoryDeductionService::COMMITTED_ORDER_STATUSES.
     */
    private function openOrder(Branch $branch, MenuItem $item, int $qty, string $status = 'pending'): Order
    {
        $order = Order::create([
            'order_number' => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'    => $branch->id,
            'type'         => 'walk_in',
            'status'       => $status,
            'subtotal'     => 100 * $qty,
            'total'        => 100 * $qty,
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'menu_item_id' => $item->id,
            'item_name'    => $item->name,
            'item_price'   => 100,
            'quantity'     => $qty,
            'subtotal'     => 100 * $qty,
        ]);

        return $order;
    }

    private function capacityService(): ProductionCapacityService
    {
        return app(ProductionCapacityService::class);
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function supervisor(Branch $branch): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Supervisor ' . uniqid(),
            'email'     => 'mpcap-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'supervisor',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    /** The capacity row for one menu item out of a forBranchScope() result. */
    private function rowFor(array $rows, MenuItem $item): ?array
    {
        foreach ($rows as $row) {
            if ($row['menu_item_id'] === (int) $item->id) {
                return $row;
            }
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // DATABASE SAFETY — proven, not assumed
    // ══════════════════════════════════════════════════════════════════════

    /**
     * phpunit.xml, not .env.testing (this repository has none), is what points
     * the suite at pomida_db_testing. A migration or a stray write aimed at the
     * DEV database once wiped it, so the target is asserted rather than trusted.
     */
    public function test_the_suite_runs_against_the_testing_database(): void
    {
        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 1 — sufficient inventory produces a positive capacity
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_menu_item_with_ample_stock_reports_a_positive_capacity(): void
    {
        $flour = $this->inventory($this->branchA, 'Flour', 3000);
        $pizza = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$flour, 150]]);

        $row = $this->capacityService()->forMenuItem($pizza, $this->branchA->id);

        $this->assertTrue($row['is_measurable']);
        $this->assertSame(20, $row['capacity'], '3000g ÷ 150g per order = 20 orders');
        $this->assertGreaterThan(0, $row['capacity']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 2 — the menu capacity is the MINIMUM ingredient capacity
    // ══════════════════════════════════════════════════════════════════════

    public function test_menu_capacity_equals_the_lowest_ingredient_capacity(): void
    {
        // Flour 3000 ÷ 150 = 20, Cheese 500 ÷ 100 = 5, Sauce 400 ÷ 80 = 5.
        $flour  = $this->inventory($this->branchA, 'Flour', 3000);
        $cheese = $this->inventory($this->branchA, 'Cheese', 500);
        $sauce  = $this->inventory($this->branchA, 'Pizza Sauce', 400);

        $pizza = $this->menuItem($this->branchA, 'Cheesy Pizza', [
            [$flour, 150], [$cheese, 100], [$sauce, 80],
        ]);

        $row = $this->capacityService()->forMenuItem($pizza, $this->branchA->id);

        $this->assertSame(5, $row['capacity'], 'the tightest ingredient, not the roomiest, decides');

        // And the per-ingredient working is reported, so the number is checkable
        // on screen rather than having to be taken on trust.
        $byId = collect($row['ingredients'])->keyBy('inventory_id');
        $this->assertSame(20, $byId[$flour->id]['capacity']);
        $this->assertSame(5, $byId[$cheese->id]['capacity']);
        $this->assertSame(5, $byId[$sauce->id]['capacity']);
    }

    /**
     * The same answer, read through the method the checkout gate uses. If these
     * two ever diverge, the Analytics page has started lying about the till.
     */
    public function test_capacity_agrees_with_the_checkout_stock_gates_own_figure(): void
    {
        $cheese = $this->inventory($this->branchA, 'Cheese', 500);
        $sauce  = $this->inventory($this->branchA, 'Pizza Sauce', 400);
        $pizza  = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$cheese, 100], [$sauce, 80]]);

        $deduction = app(InventoryDeductionService::class);

        $this->assertSame(
            $deduction->unitsAvailableFor($pizza, $this->branchA->id),
            $this->capacityService()->forMenuItem($pizza, $this->branchA->id)['capacity']
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 3 — bottleneck identity is the actual ARGMIN
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_reported_bottleneck_is_the_ingredient_that_actually_limits_production(): void
    {
        // Sauce is the single tightest: 800 ÷ 160 = 5, against Cheese 6 and Flour 20.
        // Note the stock levels — Sauce holds MORE than Cheese and is still the
        // constraint, which is the whole point of the assertions below.
        $flour  = $this->inventory($this->branchA, 'Flour', 3000);
        $cheese = $this->inventory($this->branchA, 'Cheese', 600);
        $sauce  = $this->inventory($this->branchA, 'Pizza Sauce', 800);

        $pizza = $this->menuItem($this->branchA, 'Cheesy Pizza', [
            [$flour, 150], [$cheese, 100], [$sauce, 160],
        ]);

        $row = $this->capacityService()->forMenuItem($pizza, $this->branchA->id);

        $this->assertSame(5, $row['capacity']);
        $this->assertSame($sauce->id, $row['bottleneck_inventory_id']);
        $this->assertSame($sauce->item_name, $row['bottleneck_name']);
        $this->assertSame(5, $row['bottleneck_capacity']);

        // ARGMIN, not "the first recipe row" and not "the smallest stock level":
        // Sauce holds MORE grams than Cheese and still limits production,
        // because capacity is a ratio, not a quantity.
        $this->assertGreaterThan((float) $cheese->quantity, (float) $sauce->quantity);
        $this->assertNotSame($cheese->id, $row['bottleneck_inventory_id']);

        // The bottleneck's own capacity IS the menu capacity, by definition.
        $this->assertSame($row['capacity'], $row['bottleneck_capacity']);
    }

    /**
     * A tie has to resolve the same way on every request, or the "Bottleneck"
     * column flickers between two names on refresh with nothing having changed.
     * availabilityBreakdownFor() ksorts the requirements and keeps the first at
     * the minimum, so the lowest inventory id wins — deterministically.
     */
    public function test_a_tie_for_the_bottleneck_resolves_deterministically(): void
    {
        $first  = $this->inventory($this->branchA, 'Cheese', 500);
        $second = $this->inventory($this->branchA, 'Pizza Sauce', 400);

        // Both give exactly 5. Listed second-then-first in the recipe on purpose.
        $pizza = $this->menuItem($this->branchA, 'Cheesy Pizza', [
            [$second, 80], [$first, 100],
        ]);

        $service = $this->capacityService();
        $a = $service->forMenuItem($pizza, $this->branchA->id);
        $b = $service->forMenuItem($pizza->fresh(), $this->branchA->id);

        $this->assertSame(5, $a['capacity']);
        $this->assertSame($a['bottleneck_inventory_id'], $b['bottleneck_inventory_id']);
        $this->assertSame(min($first->id, $second->id), $a['bottleneck_inventory_id']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 4 — a zero ingredient means capacity 0, and it is the bottleneck
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_exhausted_ingredient_gives_capacity_zero_and_owns_the_bottleneck(): void
    {
        $flour  = $this->inventory($this->branchA, 'Flour', 3000);
        $cheese = $this->inventory($this->branchA, 'Cheese', 0);

        $pizza = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$flour, 150], [$cheese, 100]]);

        $row = $this->capacityService()->forMenuItem($pizza, $this->branchA->id);

        $this->assertTrue($row['is_measurable'], 'zero is a measured answer, not an absent one');
        $this->assertSame(0, $row['capacity']);
        $this->assertSame($cheese->id, $row['bottleneck_inventory_id']);
        $this->assertSame(0, $row['bottleneck_capacity']);
        $this->assertNull($row['unavailable_reason']);
    }

    /**
     * Over-committed stock (open orders claiming more than the shelf holds) is
     * still floored at zero rather than reported as a negative capacity.
     */
    public function test_capacity_never_goes_negative(): void
    {
        $cheese = $this->inventory($this->branchA, 'Cheese', 100);
        $pizza  = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$cheese, 100]]);

        // Five pending orders against one serving's worth of cheese.
        $this->openOrder($this->branchA, $pizza, 5);

        $row = $this->capacityService()->forMenuItem($pizza, $this->branchA->id);

        $this->assertSame(0, $row['capacity']);
        $this->assertSame(0, $row['bottleneck_capacity']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 5 — committed stock is subtracted before the division
    // ══════════════════════════════════════════════════════════════════════

    public function test_capacity_counts_available_stock_not_raw_on_hand_stock(): void
    {
        $cheese = $this->inventory($this->branchA, 'Cheese', 1000); // 10 orders on paper
        $pizza  = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$cheese, 100]]);

        $this->assertSame(10, $this->capacityService()->forMenuItem($pizza, $this->branchA->id)['capacity']);

        // Four pending orders claim 400g. The shelf still SHOWS 1000g — stock
        // only leaves at completion — but only 600g of it is actually free.
        $this->openOrder($this->branchA, $pizza, 4);

        $row = $this->capacityService()->forMenuItem($pizza->fresh(), $this->branchA->id);

        $this->assertSame(1000.0, (float) $cheese->fresh()->quantity, 'nothing has been deducted yet');
        $this->assertSame(6, $row['capacity'], '(1000 − 400) ÷ 100 = 6');
        $this->assertSame(600.0, $row['ingredients'][0]['available']);
    }

    /**
     * Cancelled and completed orders commit nothing: a cancelled order never
     * deducts, and a completed one has already left the shelf, so counting
     * either would block sales the pantry can genuinely cover.
     */
    public function test_cancelled_orders_do_not_hold_capacity_hostage(): void
    {
        $cheese = $this->inventory($this->branchA, 'Cheese', 1000);
        $pizza  = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$cheese, 100]]);

        $this->openOrder($this->branchA, $pizza, 4, 'cancelled');

        $this->assertSame(10, $this->capacityService()->forMenuItem($pizza, $this->branchA->id)['capacity']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 6 — no recipe is UNMEASURABLE, which is not zero
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_menu_item_with_no_recipe_is_unmeasurable_rather_than_zero(): void
    {
        $plain = $this->menuItem($this->branchA, 'Mystery Dish', []);

        $row = $this->capacityService()->forMenuItem($plain, $this->branchA->id);

        $this->assertFalse($row['is_measurable']);
        $this->assertNull($row['capacity'], 'null, NOT 0 — zero would read as "sold out"');
        $this->assertSame(ProductionCapacityService::REASON_NO_RECIPE, $row['unavailable_reason']);
        $this->assertNull($row['bottleneck_inventory_id']);

        // Agrees with the menu grid's own verdict on the same item.
        $this->assertTrue($plain->isMissingRecipe());
    }

    /**
     * The legacy single-ingredient link (menu_items.inventory_item_id), for
     * items that pre-date the recipe table, IS measurable — the "unmeasurable"
     * case is no recipe AND no legacy link.
     */
    public function test_the_legacy_single_ingredient_link_is_still_measurable(): void
    {
        $syrup = $this->inventory($this->branchA, 'Syrup', 500, 'mL');

        $drink = $this->menuItem($this->branchA, 'Legacy Drink', []);
        $drink->inventory_item_id = $syrup->id;
        $drink->inventory_amount_used = 50;
        $drink->save();

        $row = $this->capacityService()->forMenuItem($drink->fresh(), $this->branchA->id);

        $this->assertTrue($row['is_measurable']);
        $this->assertSame(10, $row['capacity']);
        $this->assertSame($syrup->id, $row['bottleneck_inventory_id']);
    }

    /**
     * The page says WHY, in the project's existing words, rather than printing
     * a bare dash.
     */
    public function test_the_analytics_page_explains_an_unmeasurable_item(): void
    {
        $this->menuItem($this->branchA, 'Mystery Dish', []);

        $html = $this->actingAs($this->supervisor($this->branchA), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        // Phase 2d replaced the screen's three-way Status badge with the Risk
        // column, whose vocabulary is the six documented risk states — an
        // unmeasurable item badges "Unavailable" there. The specific reason
        // did NOT move: it is still spelled out in the row's detail view, in
        // the project's existing words, which is what this test is about. The
        // print sheet keeps "No Recipe Set" (see the stock-vocabulary test).
        $this->assertStringContainsString('Unavailable', $html);
        $this->assertStringContainsString(
            'Production capacity unavailable because this menu item has no recipe.',
            $html
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 7 — repeated requirements for one ingredient accumulate
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A literal duplicate recipe row cannot exist: menu_item_ingredients carries
     * unique(menu_item_id, inventory_id), and the same is true of
     * menu_option_ingredients. Proven here rather than assumed, because it is
     * what makes the NEXT test the real accumulation case.
     */
    public function test_the_recipe_tables_refuse_a_literal_duplicate_row(): void
    {
        $cheese = $this->inventory($this->branchA, 'Cheese', 500);
        $pizza  = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$cheese, 100]]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        MenuItemIngredient::create([
            'menu_item_id'  => $pizza->id,
            'inventory_id'  => $cheese->id,
            'quantity_used' => 40,
        ]);
    }

    /**
     * The duplicate that CAN happen: one inventory row needed by both the base
     * recipe and a selected option. requirementsForLine() sums the two into a
     * single requirement — `$needs[$invId] = ($needs[$invId] ?? 0) + …` — and
     * capacity must divide by the SUM, not by either half.
     */
    public function test_an_ingredient_needed_twice_in_one_line_accumulates_into_one_requirement(): void
    {
        $cheese = $this->inventory($this->branchA, 'Cheese', 1000);
        $pizza  = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$cheese, 100]]);

        // "Extra cheese": another 100g of the SAME inventory row.
        $extraCheese = $this->option('Extra Cheese', [[$cheese, 100]]);

        $base = $this->capacityService()->forMenuItem($pizza, $this->branchA->id);
        $this->assertSame(10, $base['capacity'], '1000 ÷ 100');

        $withOption = $this->capacityService()
            ->forMenuItem($pizza, $this->branchA->id, [$extraCheese->id]);

        $this->assertCount(1, $withOption['ingredients'], 'one requirement, not two');
        $this->assertSame(200.0, $withOption['ingredients'][0]['required_per_unit']);
        $this->assertSame(5, $withOption['capacity'], '1000 ÷ (100 + 100) = 5');
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 8 — a specific branch uses only that branch's inventory
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_specific_branch_scope_lists_and_measures_only_that_branch(): void
    {
        $cheeseA = $this->inventory($this->branchA, 'Cheese A', 500);
        $cheeseB = $this->inventory($this->branchB, 'Cheese B', 5000);

        $pizzaA = $this->menuItem($this->branchA, 'Pizza A', [[$cheeseA, 100]]); // 5
        $pizzaB = $this->menuItem($this->branchB, 'Pizza B', [[$cheeseB, 100]]); // 50

        $rows = $this->capacityService()->forBranchScope($this->branchA->id);

        $this->assertNotNull($this->rowFor($rows, $pizzaA));
        $this->assertNull($this->rowFor($rows, $pizzaB), "another branch's menu item must not appear");
        $this->assertSame(5, $this->rowFor($rows, $pizzaA)['capacity']);

        // Branch B's far larger stock never reaches Branch A's figure.
        foreach ($rows as $row) {
            foreach ($row['ingredients'] as $ingredient) {
                $this->assertNotSame($cheeseB->id, $ingredient['inventory_id']);
            }
        }
    }

    /**
     * Acceptance beside the refusal: the same supervisor, looking at their OWN
     * branch, does see their own figures on the page.
     */
    public function test_the_page_shows_the_viewers_own_branch_capacity_and_not_another_branchs(): void
    {
        $cheeseA = $this->inventory($this->branchA, 'Cheese A', 500);
        $cheeseB = $this->inventory($this->branchB, 'Cheese B', 5000);

        $this->menuItem($this->branchA, 'Pizza A', [[$cheeseA, 100]]);
        $this->menuItem($this->branchB, 'Pizza B', [[$cheeseB, 100]]);

        $html = $this->actingAs($this->supervisor($this->branchA), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Menu Production Capacity', $html);
        $this->assertStringContainsString($cheeseA->item_name, $html);
        $this->assertStringNotContainsString($cheeseB->item_name, $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 9 — a branch-locked role cannot widen its own scope
    // ══════════════════════════════════════════════════════════════════════

    /**
     * getSelectedBranch() answers from AdminOrderAccess::lockedBranchId()
     * BEFORE it consults the session, so neither the branch picker's session
     * key nor an invented querystring can move a supervisor off their branch.
     * Both are tried here.
     */
    public function test_a_branch_locked_supervisor_cannot_widen_the_capacity_scope(): void
    {
        $cheeseB = $this->inventory($this->branchB, 'Cheese B', 5000);
        $pizzaB  = $this->menuItem($this->branchB, 'Pizza B', [[$cheeseB, 100]]);

        $cheeseA = $this->inventory($this->branchA, 'Cheese A', 500);
        $this->menuItem($this->branchA, 'Pizza A', [[$cheeseA, 100]]);

        $supervisor = $this->supervisor($this->branchA);

        // (a) forge the branch picker's session key
        $html = $this->actingAs($supervisor, 'admin')
            ->withSession(['selected_branch_id' => $this->branchB->id])
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($pizzaB->name, $html);
        $this->assertStringNotContainsString($cheeseB->item_name, $html);
        $this->assertStringContainsString($cheeseA->item_name, $html);

        // (b) and forge a querystring, including the 'all' consolidated scope
        foreach ([
            ['branch_id' => $this->branchB->id],
            ['branch' => $this->branchB->id],
            ['branch_id' => 'all'],
        ] as $query) {
            $html = $this->actingAs($supervisor, 'admin')
                ->get(route('admin.analytics', $query))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString($cheeseB->item_name, $html);
        }
    }

    /** Even the picker endpoint itself cannot move them. */
    public function test_the_branch_picker_endpoint_does_not_unlock_a_supervisor(): void
    {
        $cheeseB = $this->inventory($this->branchB, 'Cheese B', 5000);
        $this->menuItem($this->branchB, 'Pizza B', [[$cheeseB, 100]]);

        $supervisor = $this->supervisor($this->branchA);

        $this->actingAs($supervisor, 'admin')
            ->get(route('admin.branches.select', ['branch' => $this->branchB->id]));

        $html = $this->actingAs($supervisor, 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($cheeseB->item_name, $html);
    }

    /**
     * A recipe reaching into another branch's inventory is a data anomaly —
     * recipe rows are written same-branch only. If one ever exists, a
     * branch-scoped viewer must not learn the other branch's stock level from
     * it: the item is refused with an honest unavailable state instead of a
     * partial answer.
     */
    public function test_a_cross_branch_recipe_row_is_refused_rather_than_disclosed(): void
    {
        $cheeseB = $this->inventory($this->branchB, 'Cheese B', 5000);
        $pizzaA  = $this->menuItem($this->branchA, 'Pizza A', [[$cheeseB, 100]]);

        $row = $this->capacityService()->forMenuItem($pizzaA, $this->branchA->id);

        $this->assertFalse($row['is_measurable']);
        $this->assertNull($row['capacity']);
        $this->assertSame(ProductionCapacityService::REASON_CROSS_BRANCH_INGREDIENT, $row['unavailable_reason']);
        $this->assertSame([], $row['ingredients'], "the other branch's row is not published");

        $html = $this->actingAs($this->supervisor($this->branchA), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($cheeseB->item_name, $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 10 — "All Branches" is the consolidated owner view
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_branches_lists_every_branch_each_against_its_own_stock(): void
    {
        $cheeseA = $this->inventory($this->branchA, 'Cheese A', 500);
        $cheeseB = $this->inventory($this->branchB, 'Cheese B', 5000);

        $pizzaA = $this->menuItem($this->branchA, 'Pizza A', [[$cheeseA, 100]]);
        $pizzaB = $this->menuItem($this->branchB, 'Pizza B', [[$cheeseB, 100]]);

        $rows = $this->capacityService()->forBranchScope('all');

        // Both appear, and each is measured against its OWN branch's pantry —
        // stock is never pooled across branches.
        $this->assertSame(5, $this->rowFor($rows, $pizzaA)['capacity']);
        $this->assertSame(50, $this->rowFor($rows, $pizzaB)['capacity']);
        $this->assertSame($this->branchA->id, $this->rowFor($rows, $pizzaA)['branch_id']);
        $this->assertSame($this->branchB->id, $this->rowFor($rows, $pizzaB)['branch_id']);
    }

    public function test_an_admin_on_all_branches_sees_both_branches_on_the_page(): void
    {
        $cheeseA = $this->inventory($this->branchA, 'Cheese A', 500);
        $cheeseB = $this->inventory($this->branchB, 'Cheese B', 5000);

        $this->menuItem($this->branchA, 'Pizza A', [[$cheeseA, 100]]);
        $this->menuItem($this->branchB, 'Pizza B', [[$cheeseB, 100]]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($cheeseA->item_name, $html);
        $this->assertStringContainsString($cheeseB->item_name, $html);

        // The consolidated view names each row's branch — two branches can
        // legitimately carry a menu item of the same name — but it does NOT
        // reintroduce the removed per-branch sales comparison.
        $this->assertStringContainsString($this->branchA->name, $html);
        $this->assertStringNotContainsString('Sales per Branch', $html);
        $this->assertStringNotContainsString('Branch Performance', $html);
    }

    /**
     * Capacity is a live snapshot. The page's date filter governs the sales
     * figures above it and must not touch these numbers.
     */
    public function test_the_date_range_filter_does_not_move_capacity(): void
    {
        $cheese = $this->inventory($this->branchA, 'Cheese', 500);
        $this->menuItem($this->branchA, 'Pizza A', [[$cheese, 100]]);

        $supervisor = $this->supervisor($this->branchA);

        foreach (['today', 'last7', 'last30', 'month'] as $period) {
            $html = $this->actingAs($supervisor, 'admin')
                ->get(route('admin.analytics', ['period' => $period]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString($cheese->item_name, $html);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 11 — menu-option ingredients
    // ══════════════════════════════════════════════════════════════════════

    /**
     * When the selected options ARE known, their ingredients count in full and
     * can become the bottleneck — the same branch-filtered walk a real
     * deduction performs.
     */
    public function test_a_selected_options_ingredient_counts_and_can_be_the_bottleneck(): void
    {
        $cheese  = $this->inventory($this->branchA, 'Cheese', 1000);  // 10 on its own
        $truffle = $this->inventory($this->branchA, 'Truffle Oil', 30, 'mL');

        $pizza  = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$cheese, 100]]);
        $option = $this->option('Truffle Drizzle', [[$truffle, 10]]);       // 3

        $row = $this->capacityService()->forMenuItem($pizza, $this->branchA->id, [$option->id]);

        $this->assertSame(3, $row['capacity'], 'the option ingredient is now the tightest');
        $this->assertSame($truffle->id, $row['bottleneck_inventory_id']);
        $this->assertCount(2, $row['ingredients']);
    }

    /**
     * An option whose only ingredient link points at ANOTHER branch's inventory
     * is ignored, exactly as requirementsForLine() ignores it when deducting —
     * branches never share stock, and an unmapped option must not silently
     * drain the wrong pantry or constrain the right one.
     */
    public function test_an_option_ingredient_from_another_branch_is_ignored(): void
    {
        $cheese    = $this->inventory($this->branchA, 'Cheese', 1000);
        $truffleB  = $this->inventory($this->branchB, 'Truffle Oil B', 30, 'mL');

        $pizza  = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$cheese, 100]]);
        $option = $this->option('Truffle Drizzle', [[$truffleB, 10]]);

        $row = $this->capacityService()->forMenuItem($pizza, $this->branchA->id, [$option->id]);

        $this->assertSame(10, $row['capacity'], "Branch B's oil neither helps nor limits Branch A");
        $this->assertCount(1, $row['ingredients']);
        $this->assertSame($cheese->id, $row['ingredients'][0]['inventory_id']);
    }

    /**
     * The Analytics summary deliberately reports the BASE recipe only. An
     * add-on is a per-order choice, not a standing property of the item (the
     * convention menuItemsOutOfStock() and MenuItemCosting already follow), and
     * inventing an "average" option combination would fabricate a number the
     * database cannot support. The page states the limitation rather than
     * hiding it.
     */
    public function test_the_analytics_summary_reports_the_base_recipe_and_says_so(): void
    {
        $cheese  = $this->inventory($this->branchA, 'Cheese', 1000);
        $truffle = $this->inventory($this->branchA, 'Truffle Oil', 30, 'mL');

        $pizza  = $this->menuItem($this->branchA, 'Cheesy Pizza', [[$cheese, 100]]);
        $option = $this->option('Truffle Drizzle', [[$truffle, 10]]);
        DB::table('menu_item_options')->insert([
            'menu_item_id'   => $pizza->id,
            'menu_option_id' => $option->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $row = $this->rowFor($this->capacityService()->forBranchScope($this->branchA->id), $pizza);

        $this->assertSame(10, $row['capacity'], 'base recipe only — the add-on is not assumed');
        $this->assertCount(1, $row['ingredients']);

        $html = $this->actingAs($this->supervisor($this->branchA), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('base recipe only', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // UI SURFACE — the section, its detail view, and its status vocabulary
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_capacity_section_renders_its_columns_and_detail_view(): void
    {
        $flour  = $this->inventory($this->branchA, 'Flour', 3000);
        $cheese = $this->inventory($this->branchA, 'Cheese', 500);
        $sauce  = $this->inventory($this->branchA, 'Pizza Sauce', 400);

        $pizza = $this->menuItem($this->branchA, 'Cheesy Pizza', [
            [$flour, 150], [$cheese, 100], [$sauce, 80],
        ]);

        $html = $this->actingAs($this->supervisor($this->branchA), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        // 'Status' became 'Risk' in Phase 2d — one column answering one
        // question, rather than two adjacent columns the reader has to check
        // against each other. See the stock-vocabulary test below.
        foreach (['Menu Production Capacity', 'Current Capacity', 'Bottleneck', 'Risk'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }

        // The item, its bottleneck, and the per-ingredient working behind it.
        $this->assertStringContainsString($pizza->name, $html);
        $this->assertStringContainsString($sauce->item_name, $html);
        $this->assertStringContainsString('Required per order', $html);
        $this->assertStringContainsString('additional orders', $html);
        $this->assertStringContainsString('mpc-detail-' . $pizza->id, $html);

        // Quantities are rendered without trailing-zero noise, the way
        // InventoryDeductionService already phrases them.
        $this->assertStringContainsString('150 g', $html);
        $this->assertStringNotContainsString('150.000 g', $html);
    }

    /**
     * The CAPACITY FIGURE stays a live snapshot, even now that projections sit
     * beside it.
     *
     * This assertion has narrowed twice. It began as "the page carries no
     * forecasting at all, that is Phase 2c"; Phase 2c landed and the page-wide
     * half became obsolete, leaving "the capacity TABLE itself must stay
     * projection-free". Phase 2d has now landed too, and that half is obsolete
     * in its turn: the table gained Forecasted Demand, Potential Shortage,
     * Coverage and Risk columns by design, because demand-versus-capacity is
     * the question the phase exists to answer and splitting it across two
     * tables would put the two halves of one comparison on different screens.
     *
     * What survives — and is the thing that actually mattered all along — is
     * that CURRENT CAPACITY is not itself a projection and is not moved by the
     * date filter. So this now asserts the DISTINCTION rather than the absence:
     * the live column is unlabelled by any forecast word, the projected columns
     * are explicitly labelled as projections, and the caption tells the reader
     * which is which. A projection silently occupying the capacity column is
     * the regression this guards.
     */
    public function test_the_capacity_table_distinguishes_live_capacity_from_projections(): void
    {
        $cheese = $this->inventory($this->branchA, 'Cheese', 500);
        $this->menuItem($this->branchA, 'Cheesy Pizza', [[$cheese, 100]]);

        $html = $this->actingAs($this->supervisor($this->branchA), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        // The capacity table's own header row, and nothing else on the page.
        // Anchored on class="mpc-table" rather than the bare class name: the
        // latter also matches the .mpc-table rule in the pushed stylesheet,
        // and a slice starting there runs through the whole document.
        $from = strpos($html, 'class="mpc-table"');
        $this->assertNotFalse($from, 'The capacity table is missing from the page.');
        $header = substr($html, $from, strpos($html, '</thead>', $from) - $from);

        // The live column is named for what it is, with no projection word
        // attached to it.
        $this->assertStringContainsString('Current Capacity', $header);
        $this->assertStringNotContainsString('Forecasted Capacity', $header);
        $this->assertStringNotContainsString('Projected Capacity', $header);

        // The projected columns are named for what THEY are.
        foreach (['Forecasted Demand', 'Potential Shortage', 'Coverage', 'Risk'] as $column) {
            $this->assertStringContainsString($column, $header);
        }
        $this->assertStringContainsString('Menu', $header);
        $this->assertStringContainsString('Bottleneck', $header);

        // And the page says, in words, that the two are different things — so
        // the distinction does not rest on the reader knowing the vocabulary.
        $this->assertStringContainsString('A live snapshot', $html);
        $this->assertStringContainsString('not affected by the date range above', $html);
        $this->assertStringContainsString('are projections for the', $html);
    }

    /**
     * The printed Capacity Status column still speaks the vocabulary the menu
     * grid uses, and the screen's Risk column speaks the six documented risk
     * states — with neither able to contradict the other.
     *
     * Phase 2b.1's printed table is unchanged: In Stock / Low Stock / Out of
     * Stock / No Recipe Set, exactly as the menu grid words them. Phase 2d
     * replaced the SCREEN's copy of that badge with the Risk column, because
     * two adjacent columns answering overlapping questions is precisely the
     * shape that invites a reader to look for a contradiction between them.
     *
     * The two can never disagree, and that is the load-bearing half of this
     * test. AnalyticsIntelligenceService's ladder guarantees it:
     * unmeasurable => UNAVAILABLE, capacity <= 0 => OUT OF STOCK, and capacity
     * at or below config('inventory.low_stock_threshold') — the SAME threshold
     * the printed badge draws its "Low Stock" line at — can never come out
     * GOOD, because the low-capacity floor lifts it to at least LOW.
     */
    public function test_the_printed_status_and_the_screen_risk_column_agree(): void
    {
        $plenty = $this->inventory($this->branchA, 'Plenty', 100000);
        $scarce = $this->inventory($this->branchA, 'Scarce', 100);
        $gone   = $this->inventory($this->branchA, 'Gone', 0);

        $healthy = $this->menuItem($this->branchA, 'Healthy Item', [[$plenty, 1]]);
        $low     = $this->menuItem($this->branchA, 'Low Item', [[$scarce, 50]]);   // 2, at/under the threshold
        $out     = $this->menuItem($this->branchA, 'Out Item', [[$gone, 10]]);     // 0
        $this->menuItem($this->branchA, 'Recipeless Item', []);

        $supervisor = $this->supervisor($this->branchA);

        // The PRINT sheet keeps the existing stock vocabulary, unchanged.
        $print = $this->actingAs($supervisor, 'admin')
            ->get(route('admin.analytics.print'))
            ->assertOk()
            ->getContent();

        foreach (['In Stock', 'Low Stock', 'Out of Stock', 'No Recipe Set'] as $label) {
            $this->assertStringContainsString($label, $print);
        }

        // The SCREEN speaks the six risk states.
        $screen = $this->actingAs($supervisor, 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        foreach (['Out of Stock', 'Unavailable', 'Low'] as $label) {
            $this->assertStringContainsString($label, $screen);
        }

        // And the verdicts themselves agree, item by item, rather than merely
        // both being present somewhere on their own page.
        $intel = app(\App\Services\AnalyticsIntelligenceService::class)->build(
            $this->branchA->id,
            $this->capacityService()->forBranchScope($this->branchA->id),
            ['rows' => []],
            ['items' => []]
        );

        $riskByItem = [];
        foreach ($intel['rows'] as $row) {
            $riskByItem[$row['menu_item_id']] = $row['risk'];
        }

        $this->assertSame(
            \App\Services\AnalyticsIntelligenceService::RISK_OUT_OF_STOCK,
            $riskByItem[$out->id],
            'an item the print sheet badges Out of Stock must not read otherwise on screen'
        );
        // The Low Stock item has 2 orders of capacity but no sales history, so
        // the ladder answers INSUFFICIENT DATA at rung 3 before the low-stock
        // floor at rung 5 is ever consulted — the documented precedence, and
        // the right answer: "we cannot assess the demand risk" is not a claim
        // that the item is fine. What must NEVER happen is GOOD, which WOULD
        // contradict the printed badge. (The floor itself, on an item that does
        // have a forecast, is proven in
        // AnalyticsIntelligenceAndExportTest::test_the_existing_low_stock_threshold_prevents_a_contradictory_good.)
        $this->assertNotSame(
            \App\Services\AnalyticsIntelligenceService::RISK_GOOD,
            $riskByItem[$low->id],
            'an item the print sheet badges Low Stock must never read GOOD on screen'
        );

        // The healthy item has no forecast (no sales at all in this fixture),
        // so it is INSUFFICIENT DATA — which is honestly "we cannot assess the
        // demand risk", not a claim that it is fine. What it must NOT be is
        // Out of Stock or Critical, neither of which its shelf supports.
        $this->assertNotContains($riskByItem[$healthy->id], [
            \App\Services\AnalyticsIntelligenceService::RISK_OUT_OF_STOCK,
            \App\Services\AnalyticsIntelligenceService::RISK_CRITICAL,
        ]);
    }

    /** Staff have no access to Analytics at all; capacity does not change that. */
    public function test_staff_still_cannot_reach_the_analytics_page(): void
    {
        $staff = User::create([
            'name'      => self::PREFIX . ' Staff ' . uniqid(),
            'email'     => 'mpcap-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'staff',
            'branch_id' => $this->branchA->id,
            'is_active' => true,
        ]);

        $this->actingAs($staff, 'admin')
            ->get(route('admin.analytics'))
            ->assertRedirect();
    }

    // ══════════════════════════════════════════════════════════════════════
    // PERFORMANCE — no N+1 as the menu grows
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The whole table costs a FIXED set of queries: the menu items (recipes
     * eager-loaded), the committed-stock walk, one whereIn covering every
     * inventory row any recipe touches, and the branch names. Per-item work
     * after that is arithmetic — nothing queries inside the loop.
     *
     * Measured as "the same run twice, once with 3 menu items and once with
     * 15" rather than against a magic number: an exact count would drift with
     * unrelated eager-loading changes, but a count that does not move when the
     * menu grows fivefold is exactly the property worth defending. One
     * DB::listen listener, reset between the two runs — registering a second
     * one would count every query twice.
     */
    public function test_the_capacity_table_does_not_issue_a_query_per_menu_item(): void
    {
        $inv = $this->inventory($this->branchA, 'Shared Cheese', 10000);
        for ($i = 0; $i < 3; $i++) {
            $this->menuItem($this->branchA, 'Item ' . $i, [[$inv, 100]]);
        }

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $count = 0;
        $this->capacityService()->forBranchScope($this->branchA->id);
        $withThreeItems = $count;

        for ($i = 3; $i < 15; $i++) {
            $this->menuItem($this->branchA, 'Item ' . $i, [[$inv, 100]]);
        }

        $count = 0;
        $rows = $this->capacityService()->forBranchScope($this->branchA->id);
        $withFifteenItems = $count;

        $this->assertGreaterThanOrEqual(15, count($rows));
        $this->assertSame(
            $withThreeItems,
            $withFifteenItems,
            'query count moved from ' . $withThreeItems . ' to ' . $withFifteenItems
            . ' when the menu grew from 3 items to 15 — that is an N+1'
        );

        // Absolute ceiling too, so a fixed-but-enormous number cannot pass.
        $this->assertLessThanOrEqual(12, $withFifteenItems);
    }
}
