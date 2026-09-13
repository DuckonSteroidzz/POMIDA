<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A voucher applied to one cart must not survive onto a different cart built
 * after the first one emptied out.
 *
 * THE BUG
 * -------
 * Reported: add an item, apply a voucher (accepted). Remove the item — cart
 * is now empty, no order was ever placed. Add a different item (a Coke). The
 * OLD voucher code is still shown as applied, against a cart the customer
 * never entered that code for.
 *
 * ROOT CAUSE
 * ----------
 * The applied code lives client-side only, in localStorage['peachy_voucher']
 * (cart.blade.php). A window `load` handler unconditionally reapplies it
 * whenever #voucherInput exists in the DOM — and #voucherInput only exists
 * when the cart is non-empty (the order-summary <aside> is behind
 * @if(count($cart) > 0)). Removing the cart's last item is a full page
 * reload via the DELETE /customer/cart/remove/{id} form (AuthController::
 * removeFromCart(), which never touches localStorage) — quantity can never
 * be stepped down to 0 through the +/- stepper (cartNormalizeQty floors it
 * at 1), so this is the ONLY way a cart reaches zero items. On that reload
 * #voucherInput is absent, so the load handler's guard short-circuits and
 * 'peachy_voucher' is never cleared. Add a new item later and the stale code
 * reappears and gets silently reapplied.
 *
 * THE FIX
 * -------
 * The load handler now checks the server-rendered cart state first: if the
 * cart is empty, clear 'peachy_voucher' immediately and skip the reapply.
 *
 * These are markup assertions, like CartVoucherInputFeedbackTest — the
 * behaviour lives entirely in the page's own JS and there is no server round
 * trip that could be asserted instead.
 *
 * PWD/Senior discount is untouched: it is pure in-memory DOM state with no
 * localStorage or session persistence at all (reset via clearDiscountCard()),
 * so it cannot share this bug and this fix does not touch it.
 */
class CartVoucherClearedOnEmptyCartTest extends TestCase
{
    use DatabaseTransactions;

    private function cartPageWithItem()
    {
        $item = MenuItem::findOrFail(24);

        $cart = [
            (string) $item->id => [
                'menu_item_id' => $item->id,
                'name'         => $item->name,
                'price'        => (float) $item->price,
                'quantity'     => 1,
                'options'      => [],
            ],
        ];

        return $this->withSession([
                'cart'       => $cart,
                'order_type' => 'pick_up',
                'branch_id'  => 1,
            ])
            ->get('/customer/cart');
    }

    private function emptyCartPage()
    {
        return $this->withSession([
                'cart'       => [],
                'order_type' => 'pick_up',
                'branch_id'  => 1,
            ])
            ->get('/customer/cart');
    }

    /** The window between "window.addEventListener('load'" and its closing. */
    private function loadHandlerBody(string $html): string
    {
        $start = strpos($html, "window.addEventListener('load'");
        $this->assertNotFalse($start, 'the voucher reapply-on-load handler is gone');

        // The handler is short; a fixed generous window avoids depending on
        // exact brace nesting while still isolating it from the rest of the
        // 2,000+ line page.
        return substr($html, $start, 1500);
    }

    public function test_an_empty_cart_clears_any_previously_stored_voucher_on_load(): void
    {
        $body = $this->loadHandlerBody($this->emptyCartPage()->getContent());

        $this->assertStringContainsString(
            "localStorage.removeItem('peachy_voucher')",
            $body,
            'loading an empty cart does not clear a previously stored voucher code'
        );
    }

    public function test_a_non_empty_cart_still_reapplies_a_stored_voucher(): void
    {
        $body = $this->loadHandlerBody($this->cartPageWithItem()->getContent());

        $this->assertStringContainsString(
            "localStorage.getItem('peachy_voucher')",
            $body,
            'a non-empty cart no longer reapplies a stored voucher code at all'
        );

        $this->assertStringContainsString('applyVoucher()', $body);
    }

    /**
     * Guards against the fix regressing to "always clear, never reapply" —
     * the empty-cart branch must return before reaching the reapply code,
     * not run unconditionally alongside it.
     */
    public function test_the_empty_cart_check_gates_the_reapply_not_just_precedes_it(): void
    {
        $html = $this->emptyCartPage()->getContent();
        $body = $this->loadHandlerBody($html);

        $clearPos = strpos($body, "localStorage.removeItem('peachy_voucher')");
        $returnPos = strpos($body, 'return;', $clearPos);
        $reapplyPos = strpos($body, "localStorage.getItem('peachy_voucher')");

        $this->assertNotFalse($returnPos, 'the empty-cart branch does not return before the reapply code');
        $this->assertTrue(
            $returnPos < $reapplyPos,
            'the empty-cart clear does not gate the reapply block — it could run unconditionally'
        );
    }
}
