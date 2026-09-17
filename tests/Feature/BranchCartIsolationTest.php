<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\TableEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase 3 multi-branch audit (2026-09-13) found that a customer's cart could
 * hold items from a branch different from the order's actual target branch,
 * and that checkout deducted stock from whichever branch the ITEM belonged
 * to, not the branch the order was placed at. Branches never share inventory
 * or stock — this is a firm business rule, not an assumption — so that is a
 * real mis-deduction, not a display glitch. Live orders 115-121 (May 2026)
 * already have this shape.
 *
 * Four doors closed it:
 *   A — AuthController::switchBranch() now clears the cart on ANY branch
 *       change (a pickup reselect, a QR scan, a typed code, or the
 *       phone-camera URL landing), not only between two already-known
 *       branches.
 *   B — showItem()/showItems()/addToCart() no longer skip the branch check
 *       for a branchless guest; a branch must be chosen first.
 *   C — CartPricing::price($cart, $branchId) + OrderController::placeOrder()
 *       hard-refuse checkout if any line's item belongs to a different
 *       branch than the order — the backstop for whatever A and B miss.
 *
 * Fixture items reused from the live catalogue (never mutated by these
 * tests — every write here runs inside DatabaseTransactions and is rolled
 * back): #25 Peperoni Pizza (branch 1 = Main) and #30 coke (branch 2). Both
 * have a real recipe with live inventory behind them, so a completed
 * checkout deducts real stock rows and then rolls back — the same pattern
 * every other order-lifecycle test in this suite already uses.
 */
class BranchCartIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private const MAIN_ITEM_ID = 25;   // Peperoni Pizza, branch_id 1
    private const OTHER_BRANCH_ITEM_ID = 30; // coke, branch_id 2

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
        parent::tearDown();
    }

    private function mainItem(): MenuItem
    {
        return MenuItem::findOrFail(self::MAIN_ITEM_ID);
    }

    private function otherBranchItem(): MenuItem
    {
        return MenuItem::findOrFail(self::OTHER_BRANCH_ITEM_ID);
    }

    /** The QR-scan URL a phone's camera app would open for this branch+table. */
    private function qrLandingUrl(int $branchId, string $table): string
    {
        $code = TableEntry::findOrRegister($branchId, $table)->code;

        return "/customer/menu?branch_id={$branchId}&table={$table}&k={$code}";
    }

    // ══════════════════ DOOR A — branch switch clears the cart ══════════════════

    /**
     * The exact audit reproduction: a pickup customer at Main (branch 1) adds
     * a Main item, then scans Branch 1's (id 2) table QR — a genuine dine-in
     * arrival mid-browse. The stale Main-branch cart line must not ride into
     * the new branch's order.
     */
    public function test_scanning_a_different_branchs_table_qr_clears_a_stale_pickup_cart(): void
    {
        $this->post('/customer/select-branch', ['branch_id' => 1]);
        $this->post('/customer/cart/add', ['item_id' => self::MAIN_ITEM_ID, 'quantity' => 1]);

        $this->assertNotEmpty(session('cart', []), 'fixture sanity: the item must have reached the cart');

        $this->get($this->qrLandingUrl(2, 'BCIA1'))->assertOk();

        $this->assertSame([], session('cart', []), 'the Main-branch line must not survive the Branch-2 scan');
        $this->assertSame(2, (int) session('branch_id'));
        $this->assertSame('dine_in', session('order_type'));
    }

    public function test_switching_pickup_branch_via_the_selector_clears_the_cart(): void
    {
        $this->post('/customer/select-branch', ['branch_id' => 1]);
        $this->post('/customer/cart/add', ['item_id' => self::MAIN_ITEM_ID, 'quantity' => 1]);
        $this->assertNotEmpty(session('cart', []));

        $this->post('/customer/select-branch', ['branch_id' => 2]);

        $this->assertSame([], session('cart', []));
    }

    /**
     * The negative case: re-picking the SAME branch must never wipe a cart
     * the customer is still building. Only a genuine change does.
     */
    public function test_reselecting_the_same_branch_does_not_clear_the_cart(): void
    {
        $this->post('/customer/select-branch', ['branch_id' => 1]);
        $this->post('/customer/cart/add', ['item_id' => self::MAIN_ITEM_ID, 'quantity' => 1]);
        $this->assertNotEmpty(session('cart', []));

        $this->post('/customer/select-branch', ['branch_id' => 1]);

        $this->assertNotEmpty(session('cart', []), 'reselecting the same branch must not clear an in-progress cart');
    }

    /**
     * The first branch a previously-branchless guest ever picks must ALSO
     * clear the cart — the old guard (`session('branch_id') && ...`) skipped
     * this because null is falsy, which is exactly how Door B's now-closed
     * gap could leave a branchless cart in place across the first pick.
     */
    public function test_a_branchless_guests_first_branch_pick_clears_any_stray_cart(): void
    {
        // A stray cart with no branch context at all — the shape Door B used
        // to allow before this pass.
        session(['cart' => [
            self::MAIN_ITEM_ID => [
                'menu_item_id' => self::MAIN_ITEM_ID,
                'name'         => 'stray',
                'price'        => 1,
                'quantity'     => 1,
                'options'      => [],
            ],
        ]]);

        $this->post('/customer/select-branch', ['branch_id' => 1]);

        $this->assertSame([], session('cart', []));
    }

    // ══════════════════ DOOR B — a branch must be chosen first ══════════════════

    /**
     * The exact audit reproduction: a branchless guest opens an item's detail
     * page directly by URL. Previously unscoped, this showed any branch's
     * item to anyone.
     */
    public function test_a_branchless_guest_cannot_view_an_item_details_page(): void
    {
        $this->get('/customer/item/' . self::MAIN_ITEM_ID)->assertNotFound();
    }

    public function test_a_customer_cannot_view_another_branchs_item_details_page(): void
    {
        $this->withSession(['branch_id' => 2])
            ->get('/customer/item/' . self::MAIN_ITEM_ID)
            ->assertNotFound();
    }

    public function test_a_customer_can_still_view_their_own_branchs_item_details_page(): void
    {
        $this->withSession(['branch_id' => 1])
            ->get('/customer/item/' . self::MAIN_ITEM_ID)
            ->assertOk();
    }

    /**
     * A shared (branch_id NULL) item stays visible regardless of which
     * branch is selected — the explicit design decision this pass makes:
     * "no branch chosen yet" is refused, but a genuinely branch-less ITEM is
     * not, exactly like the menu and category listings already treat it.
     */
    public function test_a_shared_menu_item_is_visible_from_any_selected_branch(): void
    {
        $shared = MenuItem::create([
            'category_id'   => Category::query()->value('id'),
            'branch_id'     => null,
            'name'          => 'Branch-Isolation Probe Shared Item ' . random_int(100000, 999999),
            'price'         => 50,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        $this->withSession(['branch_id' => 2])
            ->get('/customer/item/' . $shared->id)
            ->assertOk();
    }

    /**
     * The exact audit reproduction: a branchless guest posts straight to
     * add-to-cart. Previously the branch check was skipped entirely when no
     * branch was selected.
     */
    public function test_a_branchless_guest_cannot_add_to_cart(): void
    {
        $this->post('/customer/cart/add', ['item_id' => self::MAIN_ITEM_ID, 'quantity' => 1])
            ->assertRedirect();

        $this->assertSame([], session('cart', []), 'nothing may reach the cart without a branch');
    }

    /** Regression: add-to-cart still refuses a real branch MISMATCH, unchanged. */
    public function test_addtocart_still_refuses_a_mismatched_branch_item(): void
    {
        $this->withSession(['branch_id' => 1])
            ->post('/customer/cart/add', ['item_id' => self::OTHER_BRANCH_ITEM_ID, 'quantity' => 1]);

        $this->assertSame([], session('cart', []));
    }

    /** Regression: the ordinary, single-branch add-to-cart flow is unaffected. */
    public function test_addtocart_still_works_for_the_selected_branch(): void
    {
        $this->withSession(['branch_id' => 1])
            ->post('/customer/cart/add', ['item_id' => self::MAIN_ITEM_ID, 'quantity' => 1]);

        $this->assertArrayHasKey((string) self::MAIN_ITEM_ID, session('cart', []));
    }

    /**
     * A branchless guest (pick-up, not dine-in) browsing a category listing
     * used to see every branch's items — the check only ran for a
     * LOGGED-IN customer. It must now apply to a guest too.
     */
    public function test_a_branchless_guest_is_sent_to_pick_a_branch_before_browsing_a_category(): void
    {
        $categoryId = $this->mainItem()->category_id;

        $this->get('/customer/items/' . $categoryId)
            ->assertRedirect(route('customer.menu'));
    }

    /** Regression: the ordinary, branch-selected category listing still renders. */
    public function test_a_pickup_customer_can_still_browse_their_branchs_category(): void
    {
        $categoryId = $this->mainItem()->category_id;

        $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/items/' . $categoryId)
            ->assertOk();
    }

    // ══════════════════ DOOR C — checkout hard-refuses a contaminated cart ══════════════════

    /**
     * Simulates a cart that somehow still holds a foreign-branch line despite
     * Doors A and B — the defense-in-depth backstop this task explicitly
     * asked for. Session branch_id is 1 (Main); the cart line is item #30,
     * which belongs to branch 2.
     */
    public function test_checkout_refuses_an_order_whose_cart_holds_a_different_branchs_item(): void
    {
        $item = $this->otherBranchItem();

        $cart = [(string) $item->id => [
            'menu_item_id' => $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => 1,
            'options'      => [],
        ]];

        $before = Order::max('id');

        $this->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame($before, Order::max('id'), 'no order may be created from a cross-branch cart');
    }

    /** Regression: an ordinary, single-branch checkout still succeeds and saves the right branch. */
    public function test_checkout_still_succeeds_for_a_normal_single_branch_cart(): void
    {
        $item = $this->mainItem();

        $cart = [(string) $item->id => [
            'menu_item_id' => $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => 1,
            'options'      => [],
        ]];

        $before = Order::max('id');

        $this->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ]);

        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'a normal single-branch order must still be created');
        $this->assertSame(1, (int) $order->branch_id);
    }

    /**
     * CartPricing::price() itself, unit-level: passing no branch id (the
     * cart page's own display, and the AJAX quantity sync) never flags a
     * mismatch — the check is opt-in, so those callers are unaffected.
     */
    public function test_cartpricing_branch_check_is_opt_in(): void
    {
        $item = $this->otherBranchItem();

        $cart = [(string) $item->id => [
            'menu_item_id' => $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => 1,
            'options'      => [],
        ]];

        $withoutBranch = \App\Support\CartPricing::price($cart);
        $this->assertFalse($withoutBranch['branch_mismatch']);

        $withBranch = \App\Support\CartPricing::price($cart, 1);
        $this->assertTrue($withBranch['branch_mismatch']);
        $this->assertSame(0.0, $withBranch['subtotal'], 'a mismatched line must not be priced into the total');
    }
}
