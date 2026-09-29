<?php

namespace App\Support;

use App\Exceptions\MenuItemSizeUnavailableException;
use App\Models\MenuItem;
use App\Models\MenuItemSize;
use App\Models\MenuOption;

/**
 * CartPricing — the one place that turns a session cart into money.
 *
 * Why this exists
 * ---------------
 * The cart page and checkout used to price the same cart two different ways:
 *
 *   AuthController::showCart()      $total += $item['price'] * $item['quantity']
 *                                   -> the price CACHED in the session when the
 *                                      item was added to the cart.
 *
 *   OrderController::placeOrder()   $unitPrice = $menuItem->price + $optionsTotal
 *                                   -> the price the menu item has RIGHT NOW.
 *
 * Both are individually defensible — the cached price is what the customer was
 * shown, the live price is what the shop currently charges and is the one that
 * cannot be tampered with from the browser — but running them side by side
 * means the cart quotes one figure and the order is saved at another.
 *
 * Reproduced live before this fix: a cart holding 2 x "roasted chicken" cached
 * at PHP 200.00 showed Subtotal 400.00 / Total 320.00 after a 20% PWD discount,
 * while the saved orders row for the very same checkout read subtotal 520.00,
 * discount 104.00, total 416.00 — because the item's live price had been raised
 * to 260.00 while it sat in the cart. That is a real mis-charge, not a display
 * glitch: the customer is billed the higher figure.
 *
 * So both paths now call this class. The live price wins (it must — the session
 * is customer-controlled, and re-deriving from the database is exactly the
 * tamper protection placeOrder() added it for), and `repriced` lets the cart
 * page say so out loud instead of quietly quoting a number that will not be
 * honoured.
 *
 * MENU ITEM SIZES (Phase 2). A sized line carries `size_id` in the session and
 * is priced at THAT SIZE's own live price (+ add-ons, flat as ever) — never at
 * menu_items.price, which for a sized item is only the "starting from" figure.
 * This class still only PRICES: whether the size can be sold right now
 * (inactive, archived, no recipe, stock) is judged by the callers through the
 * Phase 1 functions, with the size model this returns on the line. What it
 * does flag, as `size_problem`, is a line it cannot price by size at all —
 * a sized item carted without a size (an item that gained sizes while it sat
 * in a cart), or a size id that is missing or is not this item's. Such a line
 * is priced at 0 and can never be checked out.
 */
final class CartPricing
{
    /**
     * Price a session cart from live menu data.
     *
     * @param  array     $cart      The raw session('cart') array.
     * @param  int|null  $branchId  The order's target branch, or null to skip
     *                              the branch check entirely (the cart page's
     *                              own display and the AJAX quantity sync do
     *                              not pass one — see the 'branch_mismatch'
     *                              note below for why this is opt-in).
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, repriced: bool, missing: bool, branch_mismatch: bool, size_problem: bool}
     */
    public static function price(array $cart, ?int $branchId = null): array
    {
        $menuItemIds = [];
        $optionIds = [];

        foreach ($cart as $cartKey => $cartItem) {
            $menuItemIds[] = (int) ($cartItem['menu_item_id'] ?? $cartKey);

            foreach (($cartItem['options'] ?? []) as $option) {
                if (!empty($option['id'])) {
                    $optionIds[] = (int) $option['id'];
                }
            }
        }

        $menuItems = $menuItemIds
            ? MenuItem::whereIn('id', array_unique($menuItemIds))->get()->keyBy('id')
            : collect();

        $options = $optionIds
            ? MenuOption::whereIn('id', array_unique($optionIds))->get()->keyBy('id')
            : collect();

        /*
         * Every size row of every item in the cart, archived included, in one
         * query (plus the recipe rows and their inventory, only when a size
         * exists). One read answers both questions a line can ask: "is this
         * item sized at all?" and "what does the chosen size cost?". Each menu
         * item gets its own rows as `allSizes`, so hasSizes()/sizeRecipe()
         * downstream reuse them instead of querying per item, and the SAME
         * size model — ingredients loaded — travels on the line to the stock
         * gate and to the freeze at placement.
         */
        $sizesByItem = $menuItemIds
            ? MenuItemSize::withArchived()
                ->with('ingredients.inventory')
                ->whereIn('menu_item_id', array_unique($menuItemIds))
                ->orderBy('display_order')
                ->get()
                ->groupBy('menu_item_id')
            : collect();

        foreach ($menuItems as $menuItem) {
            $menuItem->setRelation(
                'allSizes',
                new \Illuminate\Database\Eloquent\Collection($sizesByItem->get($menuItem->id, collect())->all())
            );
        }

        $lines = [];
        $subtotal = 0.0;
        $repriced = false;
        $missing = false;
        $branchMismatch = false;
        $sizeProblemInCart = false;

        foreach ($cart as $cartKey => $cartItem) {
            $menuItemId = (int) ($cartItem['menu_item_id'] ?? $cartKey);
            $menuItem = $menuItems[$menuItemId] ?? null;
            $quantity = max(1, (int) ($cartItem['quantity'] ?? 1));

            if (!$menuItem) {
                $missing = true;

                $lines[] = [
                    'cart_key'          => $cartKey,
                    'menu_item'         => null,
                    'menu_item_id'      => $menuItemId,
                    'name'              => $cartItem['name'] ?? 'Unavailable item',
                    'image'             => $cartItem['image'] ?? null,
                    'quantity'          => $quantity,
                    'unit_price'        => 0.0,
                    'cached_unit_price' => (float) ($cartItem['price'] ?? 0),
                    'subtotal'          => 0.0,
                    'option_details'    => [],
                    'option_ids'        => [],
                    'repriced'          => false,
                    'missing'           => true,
                    'branch_mismatch'   => false,
                    'size'              => null,
                    'size_id'           => null,
                    'size_name'         => null,
                    'size_problem'      => null,
                ];

                continue;
            }

            /*
             * Branch re-check (Phase 3 audit, Door C — defense in depth).
             *
             * Branches never share inventory or stock, and completing an order
             * never consults a menu item's own branch_id — it deducts
             * inventory rows. An add-on's ingredient links are filtered to
             * inventory whose branch_id matches the ORDER's branch_id
             * (InventoryDeductionService::requirementsForLine()), but a menu
             * item's base-recipe rows deduct whichever inventory rows the
             * recipe points at (each in the item's own branch by the
             * write-time rule inventoryIsSelectableForBranch()). So a cart
             * line from a different branch than the order would still
             * silently decrement that other branch's stock through its
             * recipe (reproduced live — orders 115-121, May 2026). Doors A
             * and B stop a mismatched line from ever reaching the cart; this
             * is the hard backstop in case a future regression or an edge
             * case neither of them caught still lets one through. A shared
             * item (branch_id NULL) is exempt, same as everywhere else branch
             * scoping applies.
             *
             * Opt-in via $branchId so callers that are not pricing towards a
             * specific order (the cart page's own display, the AJAX quantity
             * sync) are unaffected and keep showing whatever is actually in
             * the cart.
             */
            if ($branchId !== null && $menuItem->branch_id !== null && (int) $menuItem->branch_id !== $branchId) {
                $branchMismatch = true;

                $lines[] = [
                    'cart_key'          => $cartKey,
                    'menu_item'         => null,
                    'menu_item_id'      => $menuItemId,
                    'name'              => $cartItem['name'] ?? $menuItem->name,
                    'image'             => $cartItem['image'] ?? null,
                    'quantity'          => $quantity,
                    'unit_price'        => 0.0,
                    'cached_unit_price' => (float) ($cartItem['price'] ?? 0),
                    'subtotal'          => 0.0,
                    'option_details'    => [],
                    'option_ids'        => [],
                    'repriced'          => false,
                    'missing'           => false,
                    'branch_mismatch'   => true,
                    'size'              => null,
                    'size_id'           => null,
                    'size_name'         => null,
                    'size_problem'      => null,
                ];

                continue;
            }

            /*
             * The line's size (Menu Item Sizes, Phase 2). Resolved against
             * THIS item's own size rows only, so a size id belonging to any
             * other item — another branch's same-named item included — is
             * never priced here. A sized item carted with no size cannot be
             * priced either: menu_items.price is only its "starting from"
             * figure and is never charged.
             */
            $size = null;
            $sizeProblem = null;
            $postedSizeId = $cartItem['size_id'] ?? null;
            $sizeId = is_numeric($postedSizeId) ? (int) $postedSizeId : null;

            if ($postedSizeId !== null && $postedSizeId !== '' && $sizeId === null) {
                // Present but not an id at all.
                $sizeProblem = MenuItemSizeUnavailableException::notFound($menuItem)->getMessage();
            } elseif ($sizeId !== null) {
                $size = $menuItem->allSizes->firstWhere('id', $sizeId);

                if (! $size) {
                    $sizeProblem = MenuItemSizeUnavailableException::notFound($menuItem)->getMessage();
                }
            } elseif ($menuItem->allSizes->isNotEmpty()) {
                $sizeProblem = 'Please choose a size for ' . $menuItem->name
                    . ' — remove it from your cart and add it again from the menu.';
            }

            if ($sizeProblem !== null) {
                $sizeProblemInCart = true;

                $lines[] = [
                    'cart_key'          => $cartKey,
                    'menu_item'         => $menuItem,
                    'menu_item_id'      => $menuItem->id,
                    'name'              => $menuItem->name,
                    'image'             => $menuItem->image ?? ($cartItem['image'] ?? null),
                    'quantity'          => $quantity,
                    'unit_price'        => 0.0,
                    'cached_unit_price' => (float) ($cartItem['price'] ?? 0),
                    'subtotal'          => 0.0,
                    'option_details'    => [],
                    'option_ids'        => [],
                    'repriced'          => false,
                    'missing'           => false,
                    'branch_mismatch'   => false,
                    'size'              => null,
                    'size_id'           => $sizeId,
                    'size_name'         => null,
                    'size_problem'      => $sizeProblem,
                ];

                continue;
            }

            $lineOptionIds = [];
            $optionDetails = [];
            $optionsTotal = 0.0;

            foreach (($cartItem['options'] ?? []) as $cartOption) {
                $optionId = (int) ($cartOption['id'] ?? 0);
                $option = $options[$optionId] ?? null;

                if (!$option) {
                    // The add-on has been removed from the menu since it went
                    // into the cart. Drop it rather than charge for it.
                    $repriced = true;
                    continue;
                }

                $lineOptionIds[] = $option->id;
                $optionsTotal += (float) $option->additional_price;

                $optionDetails[] = [
                    'id'    => $option->id,
                    'name'  => $option->name,
                    'price' => (float) $option->additional_price,
                ];
            }

            // A sized line is charged its size's OWN price; add-ons stay flat.
            $basePrice = $size !== null ? (float) $size->price : (float) $menuItem->price;

            $unitPrice = round($basePrice + $optionsTotal, 2);
            $cachedUnitPrice = (float) ($cartItem['price'] ?? $unitPrice);
            $lineSubtotal = round($unitPrice * $quantity, 2);

            $lineRepriced = abs($unitPrice - $cachedUnitPrice) >= 0.005;
            $repriced = $repriced || $lineRepriced;
            $subtotal += $lineSubtotal;

            $lines[] = [
                'cart_key'          => $cartKey,
                'menu_item'         => $menuItem,
                'menu_item_id'      => $menuItem->id,
                'name'              => $size !== null ? $menuItem->name . ' (' . $size->name . ')' : $menuItem->name,
                'image'             => $menuItem->image ?? ($cartItem['image'] ?? null),
                'quantity'          => $quantity,
                'unit_price'        => $unitPrice,
                'cached_unit_price' => $cachedUnitPrice,
                'subtotal'          => $lineSubtotal,
                'option_details'    => $optionDetails,
                'option_ids'        => $lineOptionIds,
                'repriced'          => $lineRepriced,
                'missing'           => false,
                'branch_mismatch'   => false,
                'size'              => $size,
                'size_id'           => $size?->id,
                'size_name'         => $size?->name,
                'size_problem'      => null,
            ];
        }

        return [
            'lines'           => $lines,
            'subtotal'        => round($subtotal, 2),
            'repriced'        => $repriced,
            'missing'         => $missing,
            'branch_mismatch' => $branchMismatch,
            'size_problem'    => $sizeProblemInCart,
        ];
    }

    /**
     * The same cart, re-rendered for display, with every price refreshed to
     * what checkout will actually charge. Same shape the cart Blade already
     * expects, so the view keeps reading $item['price'] / ['quantity'] / etc.
     */
    public static function displayCart(array $lines): array
    {
        $display = [];

        foreach ($lines as $line) {
            $display[$line['cart_key']] = [
                'menu_item_id' => $line['menu_item_id'],
                'name'         => $line['name'],
                'price'        => $line['unit_price'],
                'base_price'   => $line['unit_price'],
                'quantity'     => $line['quantity'],
                'image'        => $line['image'],
                'options'      => $line['option_details'],
                'repriced'     => $line['repriced'],
                'missing'      => $line['missing'],
                'size_id'      => $line['size_id'] ?? null,
                'size_name'    => $line['size_name'] ?? null,
                'size_problem' => $line['size_problem'] ?? null,
            ];
        }

        return $display;
    }
}
