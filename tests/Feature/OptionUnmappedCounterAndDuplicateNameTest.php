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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The THIRD door into the pantry, and the duplicate-option-name shape.
 * Sept 2026 — raised by the owner while testing the Menu Options page.
 *
 * WHAT THE INVESTIGATION FOUND
 * ----------------------------
 * The report was that admin/menu-options lets you tick an add-on showing
 * "Branch 1: Unmapped / No active inventory" and assign it to a Branch 1 menu
 * item with no warning. Reproduced against the live dev rows: option #5
 * ("extra ice") carries exactly ONE ingredient link, to inventory #138 (White
 * Sugar, branch_id 1), so it is Mapped for Main Branch and Unmapped for
 * Branch 1 — exactly as the page said.
 *
 * The order-time consequence is NOT a cross-branch deduction and NOT a silent
 * non-deduction. All of this already held before this pass:
 *
 *   - MenuItem::optionsAvailableForBranch() hides the add-on from that
 *     branch's customer menu (it is never offered);
 *   - Customer\OrderController::placeOrder() hard-refuses a cart that holds
 *     one anyway, naming the add-on;
 *   - AdminController::storeManualOrder() hard-refuses it at the counter;
 *   - InventoryDeductionService::requirementsForLine() only ever takes the
 *     ingredient link whose inventory belongs to the ORDER's own branch.
 *
 * The first two and the walker are pinned by OptionBranchAwareDeductionTest
 * and OptionBranchEndToEndDeductionTest. The COUNTER refusal was not: its
 * message, "The \"<name>\" add-on is not available for this branch.", appeared
 * in no test in the suite, so the one door that writes payment_status = 'paid'
 * on the spot was the one nobody had pinned. That is the first half of this
 * file.
 *
 * The second half is a shape nothing covered at all: the same option NAME
 * existing TWICE as two independent MenuOption rows, one mapped per branch.
 * menu_options has no unique index on name (see the 2026_04_27_031130
 * migration), the Menu Options page lists options by name, and the live data
 * already carries duplicate MENU ITEM names ("coke" in Main Branch and
 * Branch 1, "roasted chicken" in Main Branch and Branch 3) — so two
 * identically named add-ons is a configuration an admin can reach by
 * accident. The risk being pinned is that the wrong twin is charged,
 * deducted, or deducted twice.
 *
 * Every row this file creates carries the OUCD prefix and runs inside
 * DatabaseTransactions against pomida_db_testing, so nothing survives the run
 * and no pre-existing row is modified or deleted.
 */
class OptionUnmappedCounterAndDuplicateNameTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'OUCD';
    private const MAIN = 1;

    /** Deliberately different so a deduction taken from the wrong row cannot pass by coincidence. */
    private const HOME_LINK_QTY = 7.0;
    private const FAR_LINK_QTY = 3.0;

    // ══════════════════ fixtures ══════════════════

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function staffAtMain(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function farBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'OUC' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function inventoryIn(int $branchId, string $name, float $qty = 500): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'item_code'       => 'OUCI-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => $qty,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 2,
            'is_active'       => true,
        ]);
    }

    /** A menu item with a real recipe, so the no-recipe guard never masks the add-on guard. */
    private function menuItemIn(int $branchId, Inventory $base, float $baseQty = 10.0): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => self::PREFIX . ' Coke ' . uniqid(),
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $base->id,
            'quantity_used' => $baseQty,
        ]);

        return $item->fresh();
    }

    /**
     * An option with an explicit name, so two of them can share one. $mapTo
     * null leaves it with NO ingredient link at all, which is the "extra ice
     * on a Branch 1 item" shape: unmapped everywhere.
     */
    private function option(string $name, ?Inventory $mapTo = null, float $qty = 1.0): MenuOption
    {
        $option = MenuOption::create([
            'name'             => $name,
            'additional_price' => 10,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        if ($mapTo) {
            MenuOptionIngredient::create([
                'menu_option_id' => $option->id,
                'inventory_id'   => $mapTo->id,
                'quantity_used'  => $qty,
            ]);
        }

        return $option->fresh();
    }

    private function assign(MenuItem $item, MenuOption ...$options): void
    {
        $item->options()->sync(collect($options)->pluck('id')->all());
    }

    // ══════════════════ the counter (walk-in) payload ══════════════════

    private function counterPayload(MenuItem $item, int $branchId, array $optionIds = []): array
    {
        $line = [
            'menu_item_id' => (string) $item->id,
            'quantity'     => '1',
        ];

        if ($optionIds) {
            $line['options'] = array_map('strval', $optionIds);
        }

        return [
            'branch_id'      => $branchId,
            'order_type'     => 'pick_up',
            'table_number'   => '',
            'payment_method' => 'cash',
            'amount_paid'    => '1000',
            'items'          => [$item->id => $line],
        ];
    }

    private function submitCounter(array $payload, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->admin(), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', $payload);
    }

    // ══════════════════ the customer checkout payload ══════════════════

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

    private function qty(Inventory $inv): float
    {
        return (float) $inv->fresh()->quantity;
    }

    /**
     * [inventory_id => total amount moved OUT by this order].
     *
     * stock_movements records the size of the move in `amount` with the
     * direction in `movement_type` — there is no signed quantity column — so
     * a deduction is summed as a positive amount on movement_type 'out'.
     *
     * @return array<int,float>
     */
    private function movementsFor(Order $order): array
    {
        return StockMovement::where('reference_id', $order->id)
            ->where('source', 'order')
            ->where('movement_type', 'out')
            ->get()
            ->groupBy('inventory_id')
            ->map(fn ($rows) => (float) $rows->sum('amount'))
            ->mapWithKeys(fn ($sum, $id) => [(int) $id => $sum])
            ->all();
    }

    /** How many separate 'out' rows this order wrote for one inventory item. */
    private function movementRowCount(Order $order, Inventory $inventory): int
    {
        return StockMovement::where('reference_id', $order->id)
            ->where('source', 'order')
            ->where('movement_type', 'out')
            ->where('inventory_id', $inventory->id)
            ->count();
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. THE COUNTER DOOR — an unmapped add-on must be refused, loudly
    // ══════════════════════════════════════════════════════════════════

    /**
     * The owner's exact shape, at the counter: an add-on whose only ingredient
     * link belongs to Main Branch, assigned to a FAR-branch menu item, sold as
     * a walk-in order in the far branch. It must be refused, and no order may
     * reach the database — a manual order is written payment_status = 'paid',
     * so a line that gets in here is money taken for something the kitchen has
     * no stock instruction for.
     */
    public function test_the_counter_refuses_an_add_on_that_is_unmapped_for_the_orders_branch(): void
    {
        $far = $this->farBranch();

        $mainIce = $this->inventoryIn(self::MAIN, 'Ice');
        $farBase = $this->inventoryIn($far->id, 'Cola Syrup');

        $farItem = $this->menuItemIn($far->id, $farBase);
        $extraIce = $this->option(self::PREFIX . ' extra ice ' . uniqid(), $mainIce, self::HOME_LINK_QTY);

        $this->assign($farItem, $extraIce);

        $this->assertFalse(
            $extraIce->isMappedForBranch($far->id),
            'fixture must genuinely be unmapped for the far branch'
        );

        $ordersBefore = (int) DB::table('orders')->count();

        $response = $this->submitCounter(
            $this->counterPayload($farItem, $far->id, [$extraIce->id])
        );

        $response->assertSessionHasErrors('items');
        $this->assertSame(
            $ordersBefore,
            (int) DB::table('orders')->count(),
            'an unmapped add-on must not produce a paid walk-in order'
        );
    }

    /** The refusal has to name the add-on, or staff cannot act on it with a customer waiting. */
    public function test_the_counter_refusal_names_the_add_on_and_the_branch_reason(): void
    {
        $far = $this->farBranch();
        $farItem = $this->menuItemIn($far->id, $this->inventoryIn($far->id, 'Cola Syrup'));
        $extraIce = $this->option(
            self::PREFIX . ' extra ice ' . uniqid(),
            $this->inventoryIn(self::MAIN, 'Ice'),
            self::HOME_LINK_QTY
        );

        $this->assign($farItem, $extraIce);

        $response = $this->submitCounter(
            $this->counterPayload($farItem, $far->id, [$extraIce->id])
        );

        $errors = $response->getSession()->get('errors')->getBag('default')->get('items');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString($extraIce->name, $errors[0]);
        $this->assertStringContainsString('not available for this branch', $errors[0]);
    }

    /**
     * An add-on with NO ingredient link anywhere is unmapped in every branch,
     * including the item's own. This is the state a freshly created option sits
     * in before anyone adds a recipe to it, so it is the likeliest of all.
     */
    public function test_the_counter_refuses_an_add_on_with_no_ingredient_link_at_all(): void
    {
        $item = $this->menuItemIn(self::MAIN, $this->inventoryIn(self::MAIN, 'Cola Syrup'));
        $bare = $this->option(self::PREFIX . ' bare add-on ' . uniqid());

        $this->assign($item, $bare);

        $this->assertFalse($bare->isMappedForBranch(self::MAIN));

        $ordersBefore = (int) DB::table('orders')->count();

        $this->submitCounter($this->counterPayload($item, self::MAIN, [$bare->id]))
            ->assertSessionHasErrors('items');

        $this->assertSame($ordersBefore, (int) DB::table('orders')->count());
    }

    /** Control: the same counter order goes through once the add-on is mapped locally. */
    public function test_the_counter_accepts_the_same_add_on_once_it_is_mapped_for_that_branch(): void
    {
        $far = $this->farBranch();
        $farBase = $this->inventoryIn($far->id, 'Cola Syrup');
        $farIce = $this->inventoryIn($far->id, 'Ice');

        $farItem = $this->menuItemIn($far->id, $farBase);
        $extraIce = $this->option(self::PREFIX . ' extra ice ' . uniqid(), $farIce, self::FAR_LINK_QTY);

        $this->assign($farItem, $extraIce);
        $this->assertTrue($extraIce->isMappedForBranch($far->id));

        $before = (int) Order::max('id');

        $this->submitCounter($this->counterPayload($farItem, $far->id, [$extraIce->id]))
            ->assertSessionHasNoErrors();

        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'a mapped add-on must still sell at the counter');
        $this->assertSame($far->id, (int) $order->branch_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. THE COUNTER PICKER — it must not OFFER what it will refuse
    // ══════════════════════════════════════════════════════════════════

    /**
     * /admin/home shipped data-options with every assigned add-on and no
     * branch information, so staff could tick one and only discover at submit
     * that it was refused. The page now also ships $optionBranchIds, the map
     * the option modal filters against — which is what makes the modal agree
     * with storeManualOrder() instead of dead-ending.
     */
    public function test_the_counter_picker_publishes_each_add_ons_mapped_branches(): void
    {
        $far = $this->farBranch();
        $mainIce = $this->inventoryIn(self::MAIN, 'Ice');
        $farIce = $this->inventoryIn($far->id, 'Ice');

        $mainItem = $this->menuItemIn(self::MAIN, $this->inventoryIn(self::MAIN, 'Cola Syrup'));

        $mainOnly = $this->option(self::PREFIX . ' main only ' . uniqid(), $mainIce, self::HOME_LINK_QTY);
        $bothBranches = $this->option(self::PREFIX . ' both ' . uniqid(), $mainIce, self::HOME_LINK_QTY);
        MenuOptionIngredient::create([
            'menu_option_id' => $bothBranches->id,
            'inventory_id'   => $farIce->id,
            'quantity_used'  => self::FAR_LINK_QTY,
        ]);
        $bare = $this->option(self::PREFIX . ' bare ' . uniqid());

        $this->assign($mainItem, $mainOnly, $bothBranches, $bare);

        $html = $this->actingAs($this->admin(), 'admin')->get('/admin/home')->content();

        $this->assertStringContainsString('MANUAL_OPTION_BRANCH_IDS', $html);

        $map = $this->extractOptionBranchMap($html);

        $this->assertArrayHasKey((string) $mainOnly->id, $map);
        $this->assertSame([self::MAIN], $map[(string) $mainOnly->id]);

        $bothMapped = $map[(string) $bothBranches->id];
        sort($bothMapped);
        $expected = [self::MAIN, $far->id];
        sort($expected);
        $this->assertSame($expected, $bothMapped, 'an add-on linked in two branches must list both');

        $this->assertSame(
            [],
            $map[(string) $bare->id],
            'an add-on with no ingredient link must list no branch, not be absent'
        );
    }

    /**
     * The map has to be keyed so the modal can tell "unmapped here" from
     * "unknown" — an option the page knows nothing about must be treated as
     * sellable, because the server refusal is the authoritative check and
     * guessing would hide a sellable add-on.
     */
    public function test_an_option_absent_from_the_map_is_distinguishable_from_one_mapped_nowhere(): void
    {
        $item = $this->menuItemIn(self::MAIN, $this->inventoryIn(self::MAIN, 'Cola Syrup'));
        $bare = $this->option(self::PREFIX . ' bare ' . uniqid());
        $this->assign($item, $bare);

        $unassigned = $this->option(self::PREFIX . ' never assigned ' . uniqid());

        $map = $this->extractOptionBranchMap(
            $this->actingAs($this->admin(), 'admin')->get('/admin/home')->content()
        );

        $this->assertArrayHasKey((string) $bare->id, $map, 'an assigned option must appear, with an empty branch list');
        $this->assertArrayNotHasKey(
            (string) $unassigned->id,
            $map,
            'an option on no menu item is not on this page and must not be in the map'
        );
    }

    /** Pull the JS map back out of the rendered page as data. */
    private function extractOptionBranchMap(string $html): array
    {
        $this->assertMatchesRegularExpression(
            '/const MANUAL_OPTION_BRANCH_IDS = (.+?);\s*$/m',
            $html,
            'the option/branch map must be rendered as a JS constant'
        );

        preg_match('/const MANUAL_OPTION_BRANCH_IDS = (.+?);\s*$/m', $html, $m);

        $decoded = json_decode($m[1], true);

        $this->assertIsArray($decoded, 'the option/branch map must be valid JSON');

        // json_decode gives int keys for a JSON object with numeric names;
        // normalise to strings so the assertions above read the same either way.
        $normalised = [];
        foreach ($decoded as $optionId => $branchIds) {
            $normalised[(string) $optionId] = array_map('intval', (array) $branchIds);
        }

        return $normalised;
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. DUPLICATE OPTION NAMES ACROSS BRANCHES
    // ══════════════════════════════════════════════════════════════════

    /**
     * Two DISTINCT options with the SAME name, one mapped per branch, both
     * assigned to a far-branch item. Ordering the far-branch twin must deduct
     * the far branch's ice, in the far branch's amount, exactly once — and must
     * not touch Main Branch's identically named ice at all.
     *
     * The two links carry different quantities (7 vs 3) so a deduction taken
     * from the wrong row cannot pass by coincidence, and the movement total is
     * asserted rather than just the row's existence so a double deduction
     * cannot pass either.
     */
    public function test_the_same_add_on_name_in_two_branches_deducts_only_the_local_twin_exactly_once(): void
    {
        $far = $this->farBranch();

        $mainIce = $this->inventoryIn(self::MAIN, 'Ice');
        $farIce = $this->inventoryIn($far->id, 'Ice');
        $farBase = $this->inventoryIn($far->id, 'Cola Syrup');

        $sharedName = self::PREFIX . ' extra ice ' . uniqid();

        $mainTwin = $this->option($sharedName, $mainIce, self::HOME_LINK_QTY);
        $farTwin = $this->option($sharedName, $farIce, self::FAR_LINK_QTY);

        $this->assertNotSame($mainTwin->id, $farTwin->id);
        $this->assertSame($mainTwin->name, $farTwin->name, 'the two twins must genuinely share a name');

        $farItem = $this->menuItemIn($far->id, $farBase, 10.0);
        $this->assign($farItem, $mainTwin, $farTwin);

        $mainIceBefore = $this->qty($mainIce);
        $farIceBefore = $this->qty($farIce);

        $order = $this->checkout($farItem, $farTwin, 2, $far->id);

        $this->assertNotNull($order, 'the locally mapped twin must be orderable');

        $this->complete($order);

        $moved = $this->movementsFor($order);

        $this->assertArrayHasKey($farIce->id, $moved, "the far branch's ice must move");
        $this->assertArrayNotHasKey(
            $mainIce->id,
            $moved,
            "Main Branch's identically named ice must not move for a far-branch order"
        );

        $this->assertSame(
            self::FAR_LINK_QTY * 2,
            $moved[$farIce->id],
            'the far link amount per unit ordered — no double, no wrong amount'
        );

        $this->assertSame(
            1,
            $this->movementRowCount($order, $farIce),
            'one order line touching one inventory item must write exactly one movement row'
        );

        $this->assertSame(
            $farIceBefore - (self::FAR_LINK_QTY * 2),
            $this->qty($farIce),
            "the far branch's shelf must match the movement log"
        );
        $this->assertSame(
            $mainIceBefore,
            $this->qty($mainIce),
            "Main Branch's shelf must be untouched to the decimal"
        );
    }

    /**
     * The mirror: the MAIN twin, though it shares its name with a perfectly
     * sellable far-branch option and is assigned to the same item, is refused
     * for a far-branch order. The refusal must not be defeated by the twin's
     * name resolving to a mapped option.
     */
    public function test_the_far_branch_refuses_the_identically_named_main_branch_twin(): void
    {
        $far = $this->farBranch();

        $mainIce = $this->inventoryIn(self::MAIN, 'Ice');
        $farIce = $this->inventoryIn($far->id, 'Ice');
        $farBase = $this->inventoryIn($far->id, 'Cola Syrup');

        $sharedName = self::PREFIX . ' extra ice ' . uniqid();
        $mainTwin = $this->option($sharedName, $mainIce, self::HOME_LINK_QTY);
        $farTwin = $this->option($sharedName, $farIce, self::FAR_LINK_QTY);

        $farItem = $this->menuItemIn($far->id, $farBase);
        $this->assign($farItem, $mainTwin, $farTwin);

        $mainIceBefore = $this->qty($mainIce);

        // Customer checkout.
        $this->assertNull(
            $this->checkout($farItem, $mainTwin, 1, $far->id),
            'checkout must refuse the far branch ordering the Main Branch twin'
        );

        // Counter.
        $ordersBefore = (int) DB::table('orders')->count();
        $this->submitCounter($this->counterPayload($farItem, $far->id, [$mainTwin->id]))
            ->assertSessionHasErrors('items');
        $this->assertSame($ordersBefore, (int) DB::table('orders')->count());

        $this->assertSame(
            $mainIceBefore,
            $this->qty($mainIce),
            'a refused order must leave Main Branch stock exactly where it was'
        );
    }

    /**
     * Only the locally mapped twin is offered on that branch's customer menu,
     * even though both carry the same name and both are assigned to the item.
     * Asserted on ids, not names — with duplicate names the name proves nothing.
     */
    public function test_only_the_local_twin_is_offered_on_that_branchs_menu(): void
    {
        $far = $this->farBranch();

        $mainTwinInv = $this->inventoryIn(self::MAIN, 'Ice');
        $farTwinInv = $this->inventoryIn($far->id, 'Ice');

        $sharedName = self::PREFIX . ' extra ice ' . uniqid();
        $mainTwin = $this->option($sharedName, $mainTwinInv, self::HOME_LINK_QTY);
        $farTwin = $this->option($sharedName, $farTwinInv, self::FAR_LINK_QTY);

        $farItem = $this->menuItemIn($far->id, $this->inventoryIn($far->id, 'Cola Syrup'));
        $this->assign($farItem, $mainTwin, $farTwin);

        $offered = $farItem->fresh()
            ->load('options.ingredients.inventory')
            ->optionsAvailableForBranch($far->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame([$farTwin->id], $offered);

        // And the mirror, so this is a branch rule rather than an ordering accident.
        $this->assertSame(
            [$mainTwin->id],
            $farItem->fresh()
                ->load('options.ingredients.inventory')
                ->optionsAvailableForBranch(self::MAIN)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. The counter's branch scope is not weakened by any of the above
    // ══════════════════════════════════════════════════════════════════

    /**
     * Regression guard. The add-on refusal above returns back() with an error;
     * the BRANCH refusal for a staff member raising an order in a branch that
     * is not theirs is a 404 and must stay one, since a readable error there
     * would confirm the far branch exists.
     */
    public function test_staff_still_get_a_404_raising_a_counter_order_in_another_branch(): void
    {
        $far = $this->farBranch();
        $farItem = $this->menuItemIn($far->id, $this->inventoryIn($far->id, 'Cola Syrup'));

        $this->submitCounter(
            $this->counterPayload($farItem, $far->id),
            $this->staffAtMain()
        )->assertNotFound();
    }
}
