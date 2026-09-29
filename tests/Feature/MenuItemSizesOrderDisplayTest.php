<?php

namespace Tests\Feature;

use App\Services\CatalogueLifecycle;
use App\Services\MenuItemSizes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\MenuItemSizeOrderFixtures;
use Tests\TestCase;

/**
 * Menu Item Sizes, Phase 2 — what customers and staff SEE (scenarios 15-18),
 * plus the customer menu card and item page that sell by size.
 *
 * Every order-history surface prints the line's size from its size_name
 * snapshot (OrderItem::displayName()), never from a live size row, so it
 * reads the same after the size is archived or deleted. An unsized line
 * reads exactly as before.
 */
class MenuItemSizesOrderDisplayTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    /** One guest order: 2 x Large + 1 unsized tea, placed through checkout. */
    private function orderWithSizeAndTea(): array
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $tea = $this->unsizedItem($branch, $this->sizeInventory($branch->id, 50));

        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], 2) + $this->cartLine($tea, null, 1))
            ->assertRedirect(route('customer.orders'));

        return [$branch, $f, $this->latestOrderAt($branch)];
    }

    // ══════════ 15. Customer order tracking ══════════

    public function test_the_customer_order_tracking_page_shows_the_ordered_size(): void
    {
        [, , $order] = $this->orderWithSizeAndTea();

        $page = $this->get(route('customer.orders'))->assertOk();
        $page->assertSee('Test Coffee (Large)');
        $page->assertSee('₱150.00 each', false);
        $page->assertSee('Plain Tea');
        $page->assertDontSee('Plain Tea (', false);
        $this->assertSame($order->id, (int) session('customer_order_id'));
    }

    // ══════════ 16. Customer and admin receipts ══════════

    public function test_customer_and_admin_receipts_show_the_ordered_size(): void
    {
        [$branch, , $order] = $this->orderWithSizeAndTea();

        $this->get(route('customer.receipt', $order->id))
            ->assertOk()
            ->assertSee('Test Coffee (Large)')
            ->assertSee('₱300.00', false);

        $this->actingAs($this->sizeStaff($branch->id), 'admin')
            ->get(route('admin.receipt', $order->id))
            ->assertOk()
            ->assertSee('Test Coffee (Large)');
    }

    // ══════════ 17. Admin board, completed orders, print ══════════

    public function test_the_admin_board_and_completed_orders_list_and_print_show_the_ordered_size(): void
    {
        [$branch, , $order] = $this->orderWithSizeAndTea();
        $staff = $this->sizeStaff($branch->id);

        // The live board, while the order is open — card lines and the full
        // item list the details modal reads.
        $board = $this->actingAs($staff, 'admin')->get('/admin/home')->assertOk();
        $board->assertSee('Test Coffee (Large)');

        $this->completeAs($staff, $order);
        $this->assertSame('completed', $order->fresh()->status);

        $this->actingAs($staff, 'admin')->get(route('admin.completed-orders'))
            ->assertOk()
            ->assertSee('2x Test Coffee (Large)')
            ->assertSee('1x Plain Tea');

        $this->actingAs($staff, 'admin')
            ->get(route('admin.completed-orders.print', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('2x Test Coffee (Large)');
    }

    // ══════════ 18. Snapshot, not a live lookup ══════════

    public function test_the_size_still_reads_correctly_after_the_size_row_is_archived_then_deleted(): void
    {
        [$branch, $f, $order] = $this->orderWithSizeAndTea();
        $staff = $this->sizeStaff($branch->id);

        app(MenuItemSizes::class)->archive($f['large']);
        $this->get(route('customer.receipt', $order->id))->assertSee('Test Coffee (Large)');

        $f['large']->delete();
        $this->assertNull($order->items()->where('size_name', 'Large')->sole()->menu_item_size_id);

        $this->get(route('customer.receipt', $order->id))->assertOk()->assertSee('Test Coffee (Large)');
        $this->get(route('customer.orders'))->assertOk()->assertSee('Test Coffee (Large)');
        $this->actingAs($staff, 'admin')->get('/admin/home')->assertOk()->assertSee('Test Coffee (Large)');
    }

    public function test_the_size_survives_its_menu_item_being_permanently_deleted_after_completion(): void
    {
        [$branch, $f, $order] = $this->orderWithSizeAndTea();
        $this->completeAs($this->sizeStaff($branch->id), $order);
        $this->assertSame('completed', $order->fresh()->status);

        // The item is archived, then permanently deleted by the owner —
        // its sizes go with it (FK cascade) — while the sale stays.
        $f['item']->fresh()->archive();
        $outcome = app(CatalogueLifecycle::class)->permanentlyDeleteMenuItem($f['item']);
        $this->assertSame(CatalogueLifecycle::DELETED, $outcome['action'], $outcome['message']);

        $this->assertSame(0, DB::table('menu_item_sizes')->where('menu_item_id', $f['item']->id)->count());
        $line = DB::table('order_items')->where('order_id', $order->id)->where('size_name', 'Large')->first();
        $this->assertNull($line->menu_item_id);
        $this->assertNull($line->menu_item_size_id);

        $this->get(route('customer.receipt', $order->id))->assertOk()->assertSee('Test Coffee (Large)');
        $this->actingAs($this->sizeStaff($branch->id), 'admin')
            ->get(route('admin.completed-orders'))
            ->assertOk()
            ->assertSee('2x Test Coffee (Large)');
    }

    // ══════════ The customer menu card and item page ══════════

    public function test_the_menu_card_of_a_sized_item_is_judged_by_its_sizes_and_shows_the_starting_price(): void
    {
        $branch = $this->sizeBranch('A');
        $session = ['branch_id' => $branch->id, 'order_type' => 'pick_up'];

        // No base recipe at all; Regular sellable -> orderable, "From ₱100.00".
        $item = $this->sizeItem($branch->id, 'Card Coffee');
        [$regular] = $this->enableSizes($item, 100, 150);
        $this->sizeRecipeLine($regular, $this->sizeInventory($branch->id, 50), 1);

        $page = $this->withSession($session)->get(route('customer.items', $item->category_id))->assertOk();
        $card = $this->menuCard($page->getContent(), 'card coffee');
        $this->assertStringContainsString('From', $card);
        $this->assertStringContainsString('₱100.00', $card);
        $this->assertStringNotContainsString('No Recipe Set', $card, 'the empty base recipe is irrelevant');
        $this->assertStringNotContainsString('Out of Stock', $card);

        // Both sizes without a recipe -> "No Recipe Set".
        $bare = $this->sizeItem($branch->id, 'Bare Coffee');
        $this->enableSizes($bare, 80, 120);
        $card = $this->menuCard($this->withSession($session)->get(route('customer.items', $bare->category_id))->getContent(), 'bare coffee');
        $this->assertStringContainsString('No Recipe Set', $card);

        // Every size with a recipe but none in stock -> "Out of Stock".
        $dry = $this->sizeItem($branch->id, 'Dry Coffee');
        [$dryReg, $dryLarge] = $this->enableSizes($dry, 80, 120);
        $empty = $this->sizeInventory($branch->id, 0);
        $this->sizeRecipeLine($dryReg, $empty, 1);
        $this->sizeRecipeLine($dryLarge, $empty, 2);
        $card = $this->menuCard($this->withSession($session)->get(route('customer.items', $dry->category_id))->getContent(), 'dry coffee');
        $this->assertStringContainsString('Out of Stock', $card);

        // Every size switched off -> plainly "Unavailable".
        app(MenuItemSizes::class)->update($dryReg->fresh(), 80, false);
        app(MenuItemSizes::class)->update($dryLarge->fresh(), 120, false);
        $card = $this->menuCard($this->withSession($session)->get(route('customer.items', $dry->category_id))->getContent(), 'dry coffee');
        $this->assertStringContainsString('Unavailable', $card);
        $this->assertStringNotContainsString('No Recipe Set', $card);
    }

    public function test_the_item_page_offers_each_size_at_its_own_price_and_state(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id, 'Pick Coffee');
        [$regular, $large] = $this->enableSizes($item, 100, 150);
        $this->sizeRecipeLine($regular, $this->sizeInventory($branch->id, 50), 1);
        // Large: no recipe.

        $page = $this->withSession(['branch_id' => $branch->id, 'order_type' => 'pick_up'])
            ->get(route('customer.item', $item->id))->assertOk();
        $html = $page->getContent();

        $page->assertSee('Choose a size');
        $this->assertMatchesRegularExpression('/name="size_id" value="' . $regular->id . '"[^>]*checked/s', $html, 'Regular starts selected');
        $this->assertMatchesRegularExpression('/name="size_id" value="' . $large->id . '"[^>]*disabled/s', $html, 'Large cannot be picked');
        $page->assertSee('No Recipe Set');
        $page->assertSee('₱150.00', false);
        $page->assertSee('let BASE_PRICE = 100;', false);

        // An unsized item's page has no size picker at all.
        $plain = $this->unsizedItem($branch, $this->sizeInventory($branch->id, 50));
        $this->withSession(['branch_id' => $branch->id, 'order_type' => 'pick_up'])
            ->get(route('customer.item', $plain->id))
            ->assertOk()
            ->assertDontSee('Choose a size')
            ->assertDontSee('name="size_id"', false);
    }

    /** The category-grid card for an item, found by its lower-cased name. */
    private function menuCard(string $html, string $lowerName): string
    {
        $start = strpos($html, 'data-name="' . $lowerName . '"');
        $this->assertNotFalse($start, 'card rendered for ' . $lowerName);

        return substr($html, $start, (int) strpos($html, '</a>', $start) - $start);
    }
}
