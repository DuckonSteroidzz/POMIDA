<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single source of truth for inventory math triggered by orders.
 *
 * Two phases, and only these two are order guards:
 *   1. cartShortfalls()   — THE stock gate. Counts what open orders have
 *                           already committed, and with $lock = true takes
 *                           SELECT … FOR UPDATE so two simultaneous orders
 *                           queue up instead of both passing. BOTH doors into
 *                           the pantry call it as the first statement of their
 *                           order transaction — the customer checkout
 *                           (OrderController::placeOrder) and the walk-in
 *                           counter (AdminController::storeManualOrder).
 *   2. deductWithLock()   — inside a DB::transaction(), locks every inventory
 *                           row it will touch, subtracts stock and writes a
 *                           stock_movements row per inventory item touched.
 *                           Called from exactly one place,
 *                           AdminController::completeOrder(), because
 *                           completion is the only moment stock actually
 *                           leaves the shelf.
 *
 * validateCartLines() below is NOT one of them — see the warning on it.
 *
 * Every method here aggregates quantities per inventory_id so that an
 * ingredient shared between the base recipe and a selected option is only
 * checked / deducted once. Only SELECTED menu options affect anything.
 *
 * PRECISION. Recipes are decimal(10,3) and inventory.quantity is
 * decimal(12,3) — they have to match. It used to be decimal(10,2), so a
 * recipe line finer than a hundredth was rounded away on write: the
 * stock_movements row was correct, the shelf never moved, and nothing threw.
 * See the 2026_09_16_000002 migration and the Inventory model's $casts.
 *
 * BRANCH-AWARE OPTION DEDUCTION (Phase 3 audit, Finding #3, Sept 2026).
 * menu_options/menu_item_options/order_item_options stay global tables on
 * purpose — see App\Models\MenuOption::isMappedForBranch() for why. What
 * changed is requirementsForLine(): an option's ingredient links
 * (MenuOptionIngredient) can each point at a DIFFERENT branch's inventory
 * row, and only the one matching the order/cart's own branch is ever
 * deducted. Reproduced live before this fix (rolled-back transaction,
 * pomida_db_testing): a Branch B order selecting an option whose only
 * ingredient link pointed at Branch A's inventory silently deducted
 * Branch A's stock, and a Branch A shortage blocked the Branch B order with
 * an indistinguishable "Not enough <ingredient>" message.
 */
class InventoryDeductionService
{
    /**
     * Compute the total required quantity per inventory_id for one cart line.
     *
     * @param  MenuItem   $menuItem
     * @param  int        $quantity              How many of this menu item.
     * @param  int[]      $selectedOptionIds     Option ids the customer picked.
     * @param  int|null   $branchId              The order/cart's OWN branch (Phase 3
     *                                            audit, Finding #3). menu_options is a
     *                                            global table, but each ingredient link
     *                                            an option carries (MenuOptionIngredient)
     *                                            points at ONE branch's inventory row —
     *                                            branches never share stock, no
     *                                            exceptions. Only the link matching this
     *                                            branch is deducted; every other branch's
     *                                            link for the same option is ignored.
     *                                            Base-recipe rows are unaffected — a menu
     *                                            item's own recipe already points at its
     *                                            own branch's inventory (enforced at
     *                                            write time by
     *                                            inventoryIsSelectableForBranch()).
     * @return array<int,float>                 [inventory_id => amount]
     */
    public function requirementsForLine(MenuItem $menuItem, int $quantity, array $selectedOptionIds, ?int $branchId = null): array
    {
        $needs = [];

        // Base recipe. Always ends up a Collection, never null, regardless of
        // eager-loading state — but an eager-loaded relation is reused rather
        // than re-queried, which is what keeps committedQuantities() below to a
        // handful of queries instead of one per open order line.
        $recipe = $menuItem->relationLoaded('recipeIngredients')
            ? $menuItem->recipeIngredients
            : $menuItem->recipeIngredients()->get();

        if ($recipe->isEmpty()) {
            // Legacy single-ingredient fallback for items that pre-date the recipe table.
            if ($menuItem->inventory_item_id) {
                $needs[$menuItem->inventory_item_id] = ($menuItem->inventory_amount_used ?: 1) * $quantity;
            }
            // Otherwise: no recipe, no legacy link — skip silently (handled by caller).
        } else {
            foreach ($recipe as $row) {
                $needs[$row->inventory_id] = ($needs[$row->inventory_id] ?? 0)
                    + ((float) $row->quantity_used * $quantity);
            }
        }

        // Selected options only.
        if (!empty($selectedOptionIds)) {
            $options = MenuOption::with('ingredients.inventory')->whereIn('id', $selectedOptionIds)->get();
            foreach ($options as $option) {
                foreach ($option->ingredients as $row) {
                    // Branch filter. $branchId === null means the caller has no
                    // branch to resolve (should never happen on a real order/cart
                    // path — every call site below supplies one) — deduct nothing
                    // rather than guess, which is the failure mode that used to
                    // silently drain another branch's stock. Same for a row whose
                    // inventory doesn't match this branch, or is missing entirely.
                    if ($branchId === null || !$row->inventory || (int) $row->inventory->branch_id !== $branchId) {
                        continue;
                    }
                    $needs[$row->inventory_id] = ($needs[$row->inventory_id] ?? 0)
                        + ((float) $row->quantity_used * $quantity);
                }
            }
        }

        return $needs;
    }

    /**
     * RAW pantry check — NOT an order guard. Use cartShortfalls() for that.
     *
     * This measures the requested lines against inventory.quantity exactly as
     * it stands, with no row lock and without subtracting what already-placed
     * orders have committed. Since stock only leaves the shelf at completion,
     * that makes it blind to every pending order in the kitchen: it will
     * happily approve the last serving twice.
     *
     * The walk-in counter used to gate orders on this, which is how staff
     * could sell a serving an online order had already claimed — and because a
     * manual order is written payment_status = 'paid', the shortage only
     * surfaced later, at completion, on an order already paid for. Both order
     * paths call cartShortfalls() now. What is left here is the honest
     * "what does the shelf literally hold" question, which is all the
     * remaining callers (tests asserting the branch-aware requirement walk)
     * ask of it.
     *
     * Works off the in-memory cart format the customer checkout posts (an
     * array of items, each with quantity and an array of selected options).
     *
     * @param  array<int, array{menu_item: MenuItem, quantity:int, selected_option_ids:int[]}>  $lines
     * @param  int|null  $branchId  The one branch this whole cart/order targets — see
     *                              requirementsForLine(). A single cart/order always
     *                              targets exactly one branch (enforced upstream by the
     *                              branch_mismatch backstop), so one value applies to
     *                              every line.
     * @return string[]
     */
    public function validateCartLines(array $lines, ?int $branchId = null): array
    {
        $totals = [];   // inventory_id => amount
        $labels = [];   // inventory_id => first item that needed it (for the message)

        foreach ($lines as $line) {
            $needs = $this->requirementsForLine(
                $line['menu_item'],
                (int) $line['quantity'],
                $line['selected_option_ids'] ?? [],
                $branchId
            );
            foreach ($needs as $invId => $amount) {
                $totals[$invId] = ($totals[$invId] ?? 0) + $amount;
                $labels[$invId] = $labels[$invId] ?? $line['menu_item']->name;
            }
        }

        $errors = [];
        foreach ($totals as $invId => $amount) {
            $inv = Inventory::find($invId);
            if (!$inv) {
                continue;
            }
            if ($amount > (float) $inv->quantity) {
                $errors[] = 'Not enough ' . $inv->item_name . ' for ' . $labels[$invId]
                    . ' (need ' . rtrim(rtrim(number_format($amount, 3), '0'), '.')
                    . ' ' . $inv->unit . ', have ' . $inv->quantity . ').';
            }
        }

        return $errors;
    }

    /**
     * Order statuses that have COMMITTED stock without having spent it yet.
     *
     * Inventory is only ever decremented when staff completes an order
     * (deductWithLock() below). An order sitting in any of these statuses has
     * therefore been promised to a customer but is still invisible in
     * inventory.quantity — which is precisely what let two customers be sold
     * the same last unit. 'completed' is excluded because its stock is already
     * gone from quantity (counting it again would block sales the pantry can
     * still cover); 'cancelled' is excluded because it never deducts at all.
     */
    public const COMMITTED_ORDER_STATUSES = ['pending', 'preparing', 'serving'];

    /**
     * The two UNMEASURABLE verdicts availabilityBreakdownFor() can report in
     * its $reason. Named here, where they are produced, so the analytics layer
     * that reads them (App\Services\ProductionCapacityService, whose own
     * constants alias these) cannot drift from a bare string typed twice.
     */
    public const UNMEASURABLE_NO_RECIPE = 'no_recipe';

    /** Recipe exists, but every line is zero-quantity or points at a vanished row. */
    public const UNMEASURABLE_NO_INGREDIENT = 'no_measurable_ingredient';

    /**
     * How much of each inventory row is already spoken for by orders that have
     * been placed but not yet completed.
     *
     * Keyed by inventory_id, which is branch-safe on its own: an inventory row
     * belongs to exactly one branch and branches never share stock, so summing
     * across every open order can never mix two branches' pantries.
     *
     * @param  int|null  $excludeOrderId  Ignore this order's own lines — used when
     *                                    re-validating an order that already exists.
     * @return array<int,float>  [inventory_id => amount committed]
     */
    public function committedQuantities(?int $excludeOrderId = null): array
    {
        $orders = Order::whereIn('status', self::COMMITTED_ORDER_STATUSES)
            ->when($excludeOrderId !== null, fn ($q) => $q->whereKeyNot($excludeOrderId))
            ->with([
                'items.menuItem.recipeIngredients',
                'items.options.ingredients.inventory',
            ])
            ->get();

        $totals = [];

        foreach ($orders as $order) {
            foreach ($this->totalRequirements($order) as $invId => $amount) {
                $totals[$invId] = ($totals[$invId] ?? 0) + $amount;
            }
        }

        return $totals;
    }

    /**
     * The customer-facing refusal for one menu item that cannot be supplied in
     * the quantity asked for. Single source of the wording so the cart guard,
     * the quantity control and checkout all phrase it identically.
     *
     * The zero case deliberately keeps the long-standing
     * "out of stock due to ingredient availability" phrasing — that exact
     * sentence is what the Out-of-Stock UI and its tests already speak.
     */
    public function shortfallMessage(string $itemName, int $available, int $requested): string
    {
        if ($available <= 0) {
            return 'Sorry, ' . $itemName
                . ' is currently out of stock due to ingredient availability'
                . ' — please remove it from your order.';
        }

        return 'Only ' . $available . ' ' . $itemName . ' left in stock'
            . ' — please adjust your order (you asked for ' . $requested . ').';
    }

    /**
     * How many more units of $menuItem can still be promised right now:
     * inventory MINUS everything open orders have already committed.
     *
     * Returns null when nothing constrains the item (no recipe and no legacy
     * link), matching MenuItem::remainingServings()'s "unmeasurable" case.
     *
     * @param  array<int,float>|null  $committed  Pass a map from committedQuantities()
     *                                            to avoid recomputing it per item.
     */
    public function unitsAvailableFor(
        MenuItem $menuItem,
        ?int $branchId = null,
        array $selectedOptionIds = [],
        ?array $committed = null
    ): ?int {
        return $this->availabilityBreakdownFor($menuItem, $branchId, $selectedOptionIds, $committed)['units'];
    }

    /**
     * unitsAvailableFor() with its working shown: the same MIN, plus the
     * ARGMIN it was already computing and throwing away.
     *
     * Added Phase 2b (production capacity). unitsAvailableFor() above is now a
     * one-line wrapper over this, deliberately — the capacity analytics needed
     * to know WHICH ingredient decides the minimum, and the one thing that
     * must not happen is a second copy of this arithmetic drifting from the
     * copy the checkout gate depends on. The ?int contract and every value
     * that method can return are unchanged; its three call sites did not move.
     *
     * Equivalence note on the clamp. The old body clamped once at the end,
     * max(0, min(every row)); this one clamps per row, min(every max(0, row)).
     * max(0, ·) is monotonic, so min-of-clamped IS clamp-of-min — identical
     * output, and per-row capacities that are never negative in the UI.
     *
     * "Unmeasurable" (units === null) is a different fact from "can make
     * zero", exactly as in MenuItem::isMissingRecipe() / remainingServings().
     * $reason says which of the two unmeasurable cases applied.
     *
     * @param  array<int,float>|null  $committed  Map from committedQuantities(), to
     *                                            avoid recomputing it per item.
     * @param  \Illuminate\Support\Collection|null  $inventoryById  Inventory rows already
     *                                            loaded and keyed by id. Supply it when
     *                                            walking many menu items and this method
     *                                            issues no query at all; omit it and one
     *                                            whereIn per call is taken, as before.
     * @return array{
     *     units: int|null,
     *     reason: string|null,
     *     bottleneck: array{inventory_id:int, name:string, unit:string, capacity:int}|null,
     *     ingredients: array<int, array{inventory_id:int, name:string|null, unit:string|null,
     *                                   branch_id:int|null, required_per_unit:float,
     *                                   available:float|null, capacity:int|null, is_missing:bool}>
     * }
     */
    public function availabilityBreakdownFor(
        MenuItem $menuItem,
        ?int $branchId = null,
        array $selectedOptionIds = [],
        ?array $committed = null,
        $inventoryById = null
    ): array {
        $perUnit = $this->requirementsForLine($menuItem, 1, $selectedOptionIds, $branchId);

        if (empty($perUnit)) {
            return ['units' => null, 'reason' => self::UNMEASURABLE_NO_RECIPE, 'bottleneck' => null, 'ingredients' => []];
        }

        // Deterministic walk, so a tie between two equally limiting ingredients
        // always names the same one instead of whichever the recipe happened to
        // list first.
        ksort($perUnit);

        $committed = $committed ?? $this->committedQuantities();
        $inventory = $inventoryById ?? Inventory::whereIn('id', array_keys($perUnit))->get()->keyBy('id');

        $max = null;
        $bottleneck = null;
        $rows = [];

        foreach ($perUnit as $invId => $amount) {
            $inv = $inventory->get($invId);

            if ($amount <= 0 || !$inv) {
                // Neither constrains production: a zero-quantity recipe line
                // needs nothing, and a missing inventory row has never blocked
                // anything on any path in this class. Both are still LISTED, so
                // the detail view can show why they carry no number.
                $rows[] = [
                    'inventory_id'      => (int) $invId,
                    'name'              => $inv ? $inv->item_name : null,
                    'unit'              => $inv ? $inv->unit : null,
                    'branch_id'         => ($inv && $inv->branch_id !== null) ? (int) $inv->branch_id : null,
                    'required_per_unit' => (float) $amount,
                    'available'         => null,
                    'capacity'          => null,
                    'is_missing'        => !$inv,
                ];
                continue;
            }

            $free = (float) $inv->quantity - (float) ($committed[$invId] ?? 0);
            $canMake = max(0, (int) floor($free / $amount));

            $rows[] = [
                'inventory_id'      => (int) $invId,
                'name'              => $inv->item_name,
                'unit'              => $inv->unit,
                'branch_id'         => $inv->branch_id !== null ? (int) $inv->branch_id : null,
                'required_per_unit' => (float) $amount,
                'available'         => $free,
                'capacity'          => $canMake,
                'is_missing'        => false,
            ];

            // Strict <, so the FIRST ingredient sitting at the minimum keeps the
            // title on a tie — with the ksort() above that is the lowest
            // inventory id, which is stable from one request to the next.
            if ($max === null || $canMake < $max) {
                $max = $canMake;
                $bottleneck = [
                    'inventory_id' => (int) $invId,
                    'name'         => (string) $inv->item_name,
                    'unit'         => (string) $inv->unit,
                    'capacity'     => $canMake,
                ];
            }
        }

        if ($max === null) {
            // Every requirement was a zero-quantity line or a vanished inventory
            // row. Nothing measurable to divide by — say so rather than call it
            // zero, which would read as "sold out".
            return [
                'units'       => null,
                'reason'      => self::UNMEASURABLE_NO_INGREDIENT,
                'bottleneck'  => null,
                'ingredients' => $rows,
            ];
        }

        return ['units' => $max, 'reason' => null, 'bottleneck' => $bottleneck, 'ingredients' => $rows];
    }

    /**
     * THE checkout stock gate. Returns one customer-facing message per cart
     * line that cannot be supplied — every short line, not just the first, so
     * a customer fixing a multi-item order is told about all of it at once.
     *
     * Lines are settled in cart order against a running pool, so two lines
     * sharing an ingredient cannot both be told the whole remaining stock is
     * theirs.
     *
     * @param  array<int, array{menu_item: MenuItem, quantity:int, selected_option_ids?:int[]}>  $lines
     * @param  bool  $lock  SELECT … FOR UPDATE the inventory rows first. Only valid
     *                      inside a transaction; this is what makes two simultaneous
     *                      checkouts queue up instead of both passing the check.
     * @return string[]
     */
    public function cartShortfalls(array $lines, ?int $branchId = null, bool $lock = false): array
    {
        $perUnit = [];
        $ids = [];

        foreach ($lines as $i => $line) {
            $needs = $this->requirementsForLine(
                $line['menu_item'],
                1,
                $line['selected_option_ids'] ?? [],
                $branchId
            );

            $perUnit[$i] = $needs;
            foreach (array_keys($needs) as $invId) {
                $ids[$invId] = true;
            }
        }

        if (empty($ids)) {
            return [];
        }

        // Same ordering as deductWithLock() so the two paths can never grab the
        // same rows in opposite orders and deadlock each other.
        $ids = array_keys($ids);
        sort($ids);

        $inventory = Inventory::whereIn('id', $ids)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get()
            ->keyBy('id');

        // Read AFTER the lock above, so a checkout that just lost the race sees
        // the winner's order rather than the pantry as it looked before it.
        $committed = $this->committedQuantities();

        $remaining = [];
        foreach ($ids as $invId) {
            $inv = $inventory->get($invId);
            $remaining[$invId] = $inv
                ? (float) $inv->quantity - (float) ($committed[$invId] ?? 0)
                : null; // Missing row — treated as unconstrained, as everywhere else.
        }

        $messages = [];

        foreach ($lines as $i => $line) {
            $needs = $perUnit[$i];
            if (empty($needs)) {
                continue; // No recipe / no legacy link — the recipe guard's business.
            }

            $requested = (int) $line['quantity'];
            $max = null;

            foreach ($needs as $invId => $amount) {
                if ($amount <= 0 || $remaining[$invId] === null) {
                    continue;
                }
                $canMake = (int) floor($remaining[$invId] / $amount);
                $max = $max === null ? $canMake : min($max, $canMake);
            }

            if ($max === null) {
                continue;
            }

            $max = max(0, $max);

            // Whatever this line can actually have is taken out of the pool so
            // the next line is judged against what is genuinely left.
            $granted = min($requested, $max);
            foreach ($needs as $invId => $amount) {
                if ($remaining[$invId] !== null) {
                    $remaining[$invId] -= $amount * $granted;
                }
            }

            if ($requested > $max) {
                $messages[] = $this->shortfallMessage($line['menu_item']->name, $max, $requested);
            }
        }

        return $messages;
    }

    /**
     * Aggregate every line in the order into [inventory_id => total amount needed].
     * Helper for the locked deduction below.
     *
     * @return array<int,float>
     */
    private function totalRequirements(Order $order): array
    {
        $totals = [];
        foreach ($order->items as $orderItem) {
            $menuItem = $orderItem->menuItem;
            if (!$menuItem) {
                continue;
            }
            $selectedOptionIds = $orderItem->options->pluck('id')->all();
            $needs = $this->requirementsForLine(
                $menuItem,
                (int) $orderItem->quantity,
                $selectedOptionIds,
                $order->branch_id !== null ? (int) $order->branch_id : null
            );
            foreach ($needs as $invId => $amount) {
                $totals[$invId] = ($totals[$invId] ?? 0) + $amount;
            }
        }
        return $totals;
    }

    /**
     * SAFE deduction path. Call this inside a DB::transaction() from the controller.
     *
     * Steps inside the same transaction:
     *  1. SELECT … FOR UPDATE on every inventory row the order needs
     *     (locks the rows so no other completion/stockIn can change them).
     *  2. Re-check that every locked row still has enough — this is the final,
     *     authoritative check.
     *  3. Subtract and write one stock_movements row per (order line × inventory item).
     *
     * Throws RuntimeException with a user-friendly message if stock is short.
     * Caller wraps in transaction so a thrown exception rolls back everything —
     * no partial deductions, no stock_movements written.
     *
     * @return string[]  Per-ingredient summary (e.g. ["140 g Cheese", ...])
     */
    public function deductWithLock(Order $order): array
    {
        $totals = $this->totalRequirements($order);
        if (empty($totals)) {
            return []; // Nothing to deduct (no recipe, no legacy link).
        }

        // Lock all needed inventory rows in one go to avoid deadlocks from
        // acquiring them in different orders across concurrent transactions.
        $ids = array_keys($totals);
        sort($ids);
        $locked = Inventory::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

        // Final re-check under the lock.
        foreach ($totals as $invId => $needed) {
            $inv = $locked->get($invId);
            if (!$inv) {
                throw new RuntimeException('Inventory item #' . $invId . ' is missing.');
            }
            if ($needed > (float) $inv->quantity) {
                throw new RuntimeException(
                    'Not enough ' . $inv->item_name
                    . ' (need ' . rtrim(rtrim(number_format($needed, 3), '0'), '.')
                    . ' ' . $inv->unit . ', have ' . $inv->quantity . ').'
                );
            }
        }

        // Safe to deduct. Write one log per (order line × inventory item).
        $summary = [];
        foreach ($order->items as $orderItem) {
            $menuItem = $orderItem->menuItem;
            if (!$menuItem) {
                continue;
            }
            $selectedOptionIds = $orderItem->options->pluck('id')->all();
            $needs = $this->requirementsForLine(
                $menuItem,
                (int) $orderItem->quantity,
                $selectedOptionIds,
                $order->branch_id !== null ? (int) $order->branch_id : null
            );
            if (empty($needs)) {
                continue;
            }
            // Use the MenuOption's live name for the human-readable reason.
            // (option_name on the pivot is the snapshot and also fine — both work
            // now that withPivot('option_name') is declared on the relation.)
            $reasonSuffix = $orderItem->options->isNotEmpty()
                ? ' + ' . $orderItem->options->pluck('name')->implode(', ')
                : '';
            $reason = 'Order #' . $order->order_number . ': ' . $orderItem->quantity . 'x ' . $menuItem->name . $reasonSuffix;

            // COGS snapshot: total recipe cost for THIS line, priced at the
            // unit_cost each inventory row has right now (read under the same
            // lock as the deduction below, so it can't drift mid-transaction).
            // Divided by quantity to get the single-unit cost that
            // order_items.ingredient_cost stores — this is a point-in-time
            // snapshot and must never be recomputed from a later unit_cost.
            $lineCost = 0.0;
            foreach ($needs as $invId => $amount) {
                $inv = $locked->get($invId);
                $lineCost += $amount * (float) $inv->unit_cost;
            }
            $unitIngredientCost = $orderItem->quantity > 0
                ? round($lineCost / $orderItem->quantity, 2)
                : 0.0;
            $orderItem->ingredient_cost = $unitIngredientCost;
            $orderItem->save();

            foreach ($needs as $invId => $amount) {
                $inv = $locked->get($invId);

                // round(…, 3) to the precision the column actually stores
                // (decimal(12,3)) rather than letting binary float noise —
                // 9.995999999999999 for 10 - 0.004 — reach the database and
                // the stock_movements row separately. quantity_after is read
                // back off the model straight after, so the audit log and the
                // shelf can never disagree.
                $inv->quantity = round((float) $inv->quantity - $amount, 3);
                $inv->save();

                StockMovement::create([
                    'inventory_id'   => $inv->id,
                    'movement_type'  => 'out',
                    'amount'         => $amount,
                    'quantity_after' => $inv->quantity,
                    'reason'         => $reason,
                    'source'         => 'order',
                    'reference_id'   => $order->id,
                    // Deduction is always triggered by staff completing an order, so the
                    // admin guard is the authoritative one here. auth()->id() alone reads
                    // the default 'web' guard and would record NULL ("System").
                    'user_id'        => Auth::guard('admin')->id() ?? Auth::id(),
                ]);

                $summary[] = rtrim(rtrim(number_format($amount, 3), '0'), '.')
                    . ' ' . $inv->unit . ' ' . $inv->item_name;
            }
        }

        return $summary;
    }
}
