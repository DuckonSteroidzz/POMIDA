<?php

namespace App\Support;

use App\Models\MenuItem;
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
 */
final class CartPricing
{
    /**
     * Price a session cart from live menu data.
     *
     * @param  array  $cart  The raw session('cart') array.
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, repriced: bool, missing: bool}
     */
    public static function price(array $cart): array
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

        $lines = [];
        $subtotal = 0.0;
        $repriced = false;
        $missing = false;

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

            $unitPrice = round((float) $menuItem->price + $optionsTotal, 2);
            $cachedUnitPrice = (float) ($cartItem['price'] ?? $unitPrice);
            $lineSubtotal = round($unitPrice * $quantity, 2);

            $lineRepriced = abs($unitPrice - $cachedUnitPrice) >= 0.005;
            $repriced = $repriced || $lineRepriced;
            $subtotal += $lineSubtotal;

            $lines[] = [
                'cart_key'          => $cartKey,
                'menu_item'         => $menuItem,
                'menu_item_id'      => $menuItem->id,
                'name'              => $menuItem->name,
                'image'             => $menuItem->image ?? ($cartItem['image'] ?? null),
                'quantity'          => $quantity,
                'unit_price'        => $unitPrice,
                'cached_unit_price' => $cachedUnitPrice,
                'subtotal'          => $lineSubtotal,
                'option_details'    => $optionDetails,
                'option_ids'        => $lineOptionIds,
                'repriced'          => $lineRepriced,
                'missing'           => false,
            ];
        }

        return [
            'lines'    => $lines,
            'subtotal' => round($subtotal, 2),
            'repriced' => $repriced,
            'missing'  => $missing,
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
            ];
        }

        return $display;
    }
}
