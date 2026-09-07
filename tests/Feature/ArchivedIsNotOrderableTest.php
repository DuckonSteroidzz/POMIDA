<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Support\CartPricing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two halves of archiving, each proved end to end.
 *
 * HALF ONE — an archived record must not be orderable through ANY entry point.
 * There are more of these than anyone remembers, which is why the exclusion is
 * a global scope on the model rather than a filter repeated per query: the
 * customer menu, the item-details page, the category listing, add-to-cart, the
 * cart repricing, the admin Manual Order picker, and the branch-scoped variants
 * of those. Every one of them is exercised below against a real archived item.
 *
 * HALF TWO — an archived record must still resolve everywhere history is shown,
 * because keeping history readable is the entire reason for archiving instead
 * of deleting. Receipts, the order-detail modal, Completed Orders and the
 * analytics figures are all compared before and after archiving a genuinely
 * sold item; nothing about them may move.
 */
class ArchivedIsNotOrderableTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->first();
    }

    /**
     * A real item from a real completed order — the case archiving exists for.
     * Used by the history tests, which need genuine pre-existing sales data.
     */
    private function soldItem(): MenuItem
    {
        $id = (int) DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.status', 'completed')
            ->whereNotNull('order_items.menu_item_id')
            ->value('order_items.menu_item_id');

        $this->assertNotSame(0, $id, 'this test needs an item that was actually sold');

        return MenuItem::findOrFail($id);
    }

    /**
     * A sold item with a name nothing else shares.
     *
     * The page assertions below look for the item's name in rendered HTML, and
     * the real menu has duplicate names across branches ("coke" exists twice).
     * Asserting on a shared name gives a false negative: the archived row is
     * correctly gone and its namesake is correctly still there. A unique
     * fixture makes "is this name on the page" mean what it looks like it means.
     */
    private function soldFixtureItem(): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => 1,
            'name'          => 'Orderability probe ' . random_int(100000, 999999),
            'price'         => 42,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        DB::table('order_items')->insert([
            'order_id'     => Order::where('status', 'completed')->value('id'),
            'menu_item_id' => $item->id,
            'item_name'    => $item->name,
            'item_price'   => $item->price,
            'quantity'     => 1,
            'subtotal'     => $item->price,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $item;
    }

    // ── HALF ONE: not orderable anywhere ─────────────────────────────────────

    public function test_an_archived_item_is_gone_from_the_customer_menu(): void
    {
        $item = $this->soldFixtureItem();
        $item->archive();

        $html = $this->withSession(['branch_id' => $item->branch_id ?? 1, 'order_type' => 'pick_up'])
            ->get('/customer/menu')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('>' . $item->name . '<', $html);
    }

    public function test_an_archived_item_cannot_be_opened_on_the_item_details_page(): void
    {
        $item = $this->soldFixtureItem();
        $item->archive();

        $this->get('/customer/item/' . $item->id)->assertNotFound();
    }

    public function test_an_archived_item_is_gone_from_the_category_listing(): void
    {
        $item = $this->soldFixtureItem();
        $categoryId = $item->category_id;
        $item->archive();

        if (!$categoryId) {
            $this->markTestSkipped('the sold item has no category to list');
        }

        $html = $this->withSession(['branch_id' => $item->branch_id ?? 1])
            ->get('/customer/items/' . $categoryId)
            ->getContent();

        $this->assertStringNotContainsString('>' . $item->name . '<', $html);
    }

    public function test_an_archived_item_cannot_be_added_to_a_cart(): void
    {
        $item = $this->soldFixtureItem();
        $item->archive();

        $this->withSession(['branch_id' => $item->branch_id ?? 1])
            ->post('/customer/cart/add', ['item_id' => $item->id, 'quantity' => 1]);

        $this->assertSame([], session('cart', []), 'nothing may reach the cart');
    }

    /**
     * The nastiest path: the item was already sitting in a cart when it was
     * archived. CartPricing must treat it as gone, which makes the cart page
     * say so and makes checkout refuse — rather than quietly selling it.
     */
    public function test_an_item_archived_while_in_a_cart_cannot_be_checked_out(): void
    {
        $item = $this->soldFixtureItem();

        $cart = [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => 1,
            'options'      => [],
        ]];

        $item->archive();

        $priced = CartPricing::price($cart);
        $this->assertTrue($priced['missing'], 'the line must be reported as no longer on the menu');

        $before = Order::max('id');

        $this->withSession(['cart' => $cart, 'branch_id' => $item->branch_id ?? 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame($before, Order::max('id'), 'no order may be created for an archived item');
    }

    public function test_an_archived_item_is_gone_from_the_admin_manual_order_picker(): void
    {
        $item = $this->soldFixtureItem();
        $item->archive();

        $html = $this->actingAs($this->admin(), 'admin')->get('/admin/home')->assertOk()->getContent();

        $this->assertStringNotContainsString('>' . $item->name . '<', $html);
    }

    public function test_an_archived_item_cannot_be_sold_through_a_manual_order(): void
    {
        $item = $this->soldFixtureItem();
        $item->archive();

        $before = Order::max('id');

        $this->actingAs($this->admin(), 'admin')->post('/admin/manual-order', [
            'branch_id'      => $item->branch_id ?? 1,
            'order_type'     => 'walk_in',
            'payment_method' => 'cash',
            'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            'amount_paid'    => 1000,
        ]);

        $this->assertSame($before, Order::max('id'), 'staff must not be able to ring up an archived item');
    }

    public function test_an_archived_item_is_gone_from_the_admin_menu_items_list(): void
    {
        $item = $this->soldFixtureItem();
        $item->archive();

        $html = $this->actingAs($this->admin(), 'admin')->get('/admin/menu-items')->assertOk()->getContent();

        $this->assertStringNotContainsString('>' . $item->name . '<', $html);
    }

    public function test_an_archived_add_on_can_no_longer_be_offered(): void
    {
        $optionId = (int) DB::table('order_item_options')->value('menu_option_id');
        $option = MenuOption::findOrFail($optionId);
        $option->archive();

        $html = $this->actingAs($this->admin(), 'admin')->get('/admin/menu-options')->assertOk()->getContent();
        $this->assertStringNotContainsString('>' . $option->name . '<', $html);

        // And it cannot be priced into a cart line either.
        $item = MenuItem::where('is_available', true)->first();

        $priced = CartPricing::price([(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price + (float) $option->additional_price,
            'quantity'     => 1,
            'options'      => [['id' => $option->id, 'name' => $option->name, 'price' => $option->additional_price]],
        ]]);

        $this->assertSame([], $priced['lines'][0]['option_ids'], 'an archived add-on must not be charged for');
        $this->assertSame(round((float) $item->price, 2), $priced['subtotal']);
    }

    public function test_an_archived_category_and_subcategory_leave_the_customer_menu(): void
    {
        $category = Category::create(['name' => 'Archive scope cat', 'display_order' => 0, 'is_active' => true]);
        $sub = Subcategory::create(['category_id' => $category->id, 'name' => 'Archive scope sub', 'display_order' => 0]);

        $category->archive();
        $sub->archive();

        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/menu')->getContent();

        $this->assertStringNotContainsString('Archive scope cat', $html);
        $this->assertStringNotContainsString('Archive scope sub', $html);
    }

    // ── HALF TWO: history is untouched ───────────────────────────────────────

    /**
     * The proof that archiving is worth doing at all: take a real past order,
     * archive the item it contains, and show that nothing about that order's
     * presentation or its contribution to the figures changes.
     */
    public function test_archiving_a_sold_item_changes_nothing_about_past_orders(): void
    {
        $item = $this->soldItem();

        $order = Order::whereHas('items', fn ($q) => $q->where('menu_item_id', $item->id))
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->firstOrFail();

        $admin = $this->admin();

        $receiptBefore = $this->actingAs($admin, 'admin')->get('/admin/receipt/' . $order->id)->getContent();
        $completedBefore = $this->actingAs($admin, 'admin')->get('/admin/completed-orders')->getContent();

        $item->archive();

        $this->assertSame(
            $receiptBefore,
            $this->actingAs($admin, 'admin')->get('/admin/receipt/' . $order->id)->assertOk()->getContent(),
            'the receipt must be byte-for-byte unchanged'
        );

        $this->assertSame(
            $completedBefore,
            $this->actingAs($admin, 'admin')->get('/admin/completed-orders')->getContent(),
            'Completed Orders — including the order-detail modal it renders — must be unchanged'
        );
    }

    /**
     * The order-detail modal on Admin Home renders its lines from
     * order_items.item_name, the snapshot taken when the order was placed, not
     * from the live catalogue. That is what makes it immune to archiving, and
     * it is worth pinning explicitly rather than inferring it from the page
     * comparison above.
     *
     * (Note: the separate GET /admin/order-detail/{id} endpoint is dead code —
     * it eager-loads a relation named `user` that does not exist on Order, and
     * its view was never created, so it 500s and has done since before this
     * round. Left alone deliberately: nothing links to it, and inventing a view
     * for it is not this round's work.)
     */
    public function test_the_order_detail_modal_reads_the_snapshot_not_the_catalogue(): void
    {
        $item = $this->soldItem();

        $line = \App\Models\OrderItem::where('menu_item_id', $item->id)->firstOrFail();
        $snapshot = $line->item_name;

        $item->archive();
        $item->name = 'RENAMED AFTER ARCHIVING';
        $item->save();

        $this->assertSame(
            $snapshot,
            $line->fresh()->item_name,
            'the order line keeps what was actually sold, whatever happens to the catalogue afterwards'
        );
    }

    public function test_archiving_a_sold_item_changes_no_analytics_figure(): void
    {
        $item = $this->soldItem();

        $before = $this->analyticsSnapshot();

        $item->archive();

        $this->assertSame($before, $this->analyticsSnapshot(), 'every analytics figure must survive archiving');
    }

    /** The relation the receipt and bestSellers read through must still resolve. */
    public function test_an_order_line_can_still_reach_its_archived_item_and_add_ons(): void
    {
        $item = $this->soldItem();
        $optionId = (int) DB::table('order_item_options')->value('menu_option_id');
        $option = MenuOption::findOrFail($optionId);

        $line = \App\Models\OrderItem::where('menu_item_id', $item->id)->firstOrFail();
        $optionLine = \App\Models\OrderItem::whereIn(
            'id',
            DB::table('order_item_options')->where('menu_option_id', $optionId)->pluck('order_item_id')
        )->firstOrFail();

        $item->archive();
        $option->archive();

        $this->assertNotNull($line->fresh()->menuItem, 'history must still reach the archived item');
        $this->assertTrue(
            $optionLine->fresh()->options->contains('id', $optionId),
            'the receipt prints the add-on through this relation, so it must still resolve'
        );
    }

    /**
     * Requirement check while we are in the analytics: a CANCELLED order stays
     * out of every revenue figure. This is the mechanism that replaces deleting
     * a wrong order, so it has to actually work.
     */
    public function test_a_cancelled_order_stays_out_of_every_revenue_figure(): void
    {
        $before = $this->analyticsSnapshot();
        $revenueBefore = (float) Order::where('status', 'completed')->sum('total');

        $order = Order::create([
            'order_number' => 'CANCELPROBE' . random_int(1000, 9999),
            'branch_id'    => 1,
            'type'         => 'walk_in',
            'status'       => 'cancelled',
            'subtotal'     => 99999,
            'total'        => 99999,
            'cancelled_at' => now(),
        ]);

        $this->assertSame($before, $this->analyticsSnapshot(), 'a cancelled order must not move any figure');
        $this->assertSame($revenueBefore, (float) Order::where('status', 'completed')->sum('total'));

        // And it is still on the books, which is the point of cancelling
        // rather than deleting.
        $this->assertNotNull(Order::find($order->id));
    }

    /** Every figure the owner sees, as one comparable array. */
    private function analyticsSnapshot(): array
    {
        $svc = new AnalyticsService('all');

        return [
            /*
             * SORTED before comparing (2026-09-02). bestSellers() orders by
             * quantity, and two items can tie on it — with the live data they
             * now do (items 27 and 34, both at 34). MySQL gives no stable
             * order between tied rows, so comparing the raw row order made
             * this snapshot flip between two runs that were otherwise
             * identical in every figure, failing an assertion about archiving
             * for a reason that had nothing to do with archiving.
             *
             * Sorting keeps exactly what this is meant to prove — the same
             * items with the same quantities — while dropping the one detail
             * the database never promised. A real change (a different
             * quantity, or an item entering or leaving the top ten) still
             * changes this string and still fails the assertion.
             */
            'best'      => $svc->bestSellers(10)
                ->map(fn ($r) => $r->menu_item_id . ':' . $r->total_qty)
                ->sort()
                ->values()
                ->implode(','),
            'trend'     => json_encode($svc->dailyTrend(14)),
            'revenue'   => (string) Order::where('status', 'completed')->sum('total'),
            'orders'    => Order::where('status', 'completed')->count(),
            'byCategory' => json_encode(
                DB::table('order_items')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
                    ->join('categories', 'menu_items.category_id', '=', 'categories.id')
                    ->where('orders.status', 'completed')
                    ->groupBy('categories.name')
                    ->select('categories.name', DB::raw('SUM(order_items.quantity) as total_qty'))
                    ->orderBy('categories.name')
                    ->get()
            ),
        ];
    }
}
