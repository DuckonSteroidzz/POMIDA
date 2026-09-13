<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression: GET /customer/cart 500'd with "Undefined variable
 * $sessionOrderType" whenever the cart was empty.
 *
 * The Confirm Your Order modal (added in 9348f22, "Add Take Out flag for
 * Dine-In orders") reads $sessionOrderType for its Dine-In-only "Take Out"
 * checkbox, but that variable was only defined inside the order-summary block,
 * which is skipped when the cart has no items. A fresh browser session — no
 * cart, no order_type — therefore hit an undefined variable.
 *
 * These tests exercise the real route the way a first visit would, with no
 * test-only session priming, so a re-broken controller is caught here.
 */
class CartEmptyOrderTypeRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_empty_cart_page_loads_for_a_fresh_guest_session(): void
    {
        $this->get('/customer/cart')
            ->assertOk()
            ->assertDontSee('Undefined variable');
    }

    public function test_empty_cart_page_loads_when_seated_dine_in_with_no_items(): void
    {
        // Order type present, but still no cart items: the summary block that
        // defines $sessionOrderType is skipped, the modal still renders.
        $this->withSession(['order_type' => 'dine_in', 'branch_id' => 1, 'table_number' => '5'])
            ->get('/customer/cart')
            ->assertOk();
    }
}
