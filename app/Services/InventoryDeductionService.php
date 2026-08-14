<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single source of truth for inventory math triggered by orders.
 *
 * Two phases:
 *   1. validateStock()   — read-only check. Returns [] when okay, otherwise
 *                          a list of human-readable messages.
 *   2. deductForOrder()  — actually subtracts stock and writes a
 *                          stock_movements row per inventory item touched.
 *
 * Both methods aggregate quantities per inventory_id so that an ingredient
 * shared between the base recipe and a selected option is only checked /
 * deducted once. Only SELECTED menu options affect anything.
 */
class InventoryDeductionService
{
    /**
     * Compute the total required quantity per inventory_id for one cart line.
     *
     * @param  MenuItem  $menuItem
     * @param  int       $quantity              How many of this menu item.
     * @param  int[]     $selectedOptionIds     Option ids the customer picked.
     * @return array<int,float>                 [inventory_id => amount]
     */
    public function requirementsForLine(MenuItem $menuItem, int $quantity, array $selectedOptionIds): array
    {
        $needs = [];

        // Base recipe. Query the relation directly so we always get a Collection,
        // never null, regardless of eager-loading state.
        $recipe = $menuItem->recipeIngredients()->get();

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
            $options = MenuOption::with('ingredients')->whereIn('id', $selectedOptionIds)->get();
            foreach ($options as $option) {
                foreach ($option->ingredients as $row) {
                    $needs[$row->inventory_id] = ($needs[$row->inventory_id] ?? 0)
                        + ((float) $row->quantity_used * $quantity);
                }
            }
        }

        return $needs;
    }

    /**
     * Returns [] when there is enough stock for everything in the order.
     * Otherwise returns a list of "Not enough <ingredient> for <item> ..." messages.
     *
     * @param  Order  $order  Must have items.menuItem.ingredients + items.options loaded
     *                        when called against a persisted order. For the cart we use
     *                        validateCart() below instead.
     * @return string[]
     */
    public function validateStock(Order $order): array
    {
        $errors = [];

        foreach ($order->items as $orderItem) {
            $menuItem = $orderItem->menuItem;
            if (!$menuItem) {
                continue;
            }

            $selectedOptionIds = $orderItem->options->pluck('id')->all();
            $needs = $this->requirementsForLine($menuItem, (int) $orderItem->quantity, $selectedOptionIds);

            foreach ($needs as $inventoryId => $amount) {
                $inv = Inventory::find($inventoryId);
                if (!$inv) {
                    continue;
                }
                if ($amount > (float) $inv->quantity) {
                    $errors[] = 'Not enough ' . $inv->item_name . ' for ' . $menuItem->name
                        . ' (need ' . rtrim(rtrim(number_format($amount, 3), '0'), '.')
                        . ' ' . $inv->unit . ', have ' . $inv->quantity . ').';
                }
            }
        }

        return $errors;
    }

    /**
     * Same shape as validateStock() but works off the in-memory cart, which is the
     * format the customer checkout posts (an array of items, each with quantity
     * and an array of selected options).
     *
     * @param  array<int, array{menu_item: MenuItem, quantity:int, selected_option_ids:int[]}>  $lines
     * @return string[]
     */
    public function validateCartLines(array $lines): array
    {
        $totals = [];   // inventory_id => amount
        $labels = [];   // inventory_id => first item that needed it (for the message)

        foreach ($lines as $line) {
            $needs = $this->requirementsForLine(
                $line['menu_item'],
                (int) $line['quantity'],
                $line['selected_option_ids'] ?? []
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
            $needs = $this->requirementsForLine($menuItem, (int) $orderItem->quantity, $selectedOptionIds);
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
            $needs = $this->requirementsForLine($menuItem, (int) $orderItem->quantity, $selectedOptionIds);
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

            foreach ($needs as $invId => $amount) {
                $inv = $locked->get($invId);
                $inv->quantity = (float) $inv->quantity - $amount;
                $inv->save();

                StockMovement::create([
                    'inventory_id'   => $inv->id,
                    'movement_type'  => 'out',
                    'amount'         => $amount,
                    'quantity_after' => $inv->quantity,
                    'reason'         => $reason,
                    'source'         => 'order',
                    'reference_id'   => $order->id,
                    'user_id'        => auth()->id(),
                ]);

                $summary[] = rtrim(rtrim(number_format($amount, 3), '0'), '.')
                    . ' ' . $inv->unit . ' ' . $inv->item_name;
            }
        }

        return $summary;
    }

    /**
     * @deprecated Use deductWithLock() inside DB::transaction(). Kept only so
     *             existing call sites do not break before they are migrated.
     *
     * Perform actual deduction + write stock_movements rows.
     * Writes ONE movement per (order line × inventory item) so the reason
     * column stays readable (e.g. "Order #ORD-...: 2x Pepperoni Pizza + Extra Cheese").
     *
     * @return string[]  Human-readable summary, e.g. ["140 g Cheese", "2 pcs Pizza Dough"]
     */
    public function deductForOrder(Order $order): array
    {
        $summary = [];

        foreach ($order->items as $orderItem) {
            $menuItem = $orderItem->menuItem;
            if (!$menuItem) {
                continue;
            }

            $selectedOptionIds = $orderItem->options->pluck('id')->all();
            $needs = $this->requirementsForLine($menuItem, (int) $orderItem->quantity, $selectedOptionIds);

            if (empty($needs)) {
                // No recipe configured — skip silently (backward-compat).
                continue;
            }

            $reasonSuffix = $orderItem->options->isNotEmpty()
                ? ' + ' . $orderItem->options->pluck('name')->implode(', ')
                : '';
            $reason = 'Order #' . $order->order_number . ': ' . $orderItem->quantity . 'x ' . $menuItem->name . $reasonSuffix;

            foreach ($needs as $inventoryId => $amount) {
                $inv = Inventory::find($inventoryId);
                if (!$inv) {
                    continue;
                }
                // Defensive: don't allow stock to go below zero.
                if ($amount > (float) $inv->quantity) {
                    continue;
                }

                $inv->quantity = (float) $inv->quantity - $amount;
                $inv->save();

                StockMovement::create([
                    'inventory_id'   => $inv->id,
                    'movement_type'  => 'out',
                    'amount'         => $amount,
                    'quantity_after' => $inv->quantity,
                    'reason'         => $reason,
                    'source'         => 'order',
                    'reference_id'   => $order->id,
                    'user_id'        => auth()->id(),
                ]);

                $summary[] = rtrim(rtrim(number_format($amount, 3), '0'), '.')
                    . ' ' . $inv->unit . ' ' . $inv->item_name;
            }
        }

        return $summary;
    }
}
