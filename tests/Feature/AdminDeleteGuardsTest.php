<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * No admin delete may end at a raw exception page.
 *
 * Reported: deleting a menu option that had been chosen on a past order threw
 * an unhandled Illuminate\Database\QueryException and rendered the full
 * Ignition debug page, raw SQL and constraint name included
 * (order_item_options_menu_option_id_foreign). AdminController::deleteMenuOption()
 * called $option->delete() with no guard at all.
 *
 * The database is right to refuse: order_items.menu_item_id and
 * order_item_options.menu_option_id are ON DELETE RESTRICT so a past order
 * keeps saying what was actually ordered. What was wrong was translating that
 * refusal into a 500.
 *
 * This round only makes the failure graceful — it deliberately does not change
 * what is or is not deletable. The tests below therefore assert BOTH halves:
 * blocked deletes explain themselves and change nothing, and deletes that used
 * to succeed still succeed.
 */
class AdminDeleteGuardsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->first();
    }

    // ── the reported crash ───────────────────────────────────────────────────

    /**
     * THE ORIGINALLY REPORTED CRASH. Without a guard this throws
     * QueryException and the admin sees the Ignition debug page;
     * withoutExceptionHandling() is what makes the test fail loudly rather
     * than quietly rendering a 500.
     *
     * The OUTCOME changed in the round that introduced archiving: refusing was
     * still a dead end for an add-on the shop genuinely stopped selling, so it
     * is now archived instead. What this test still pins is the part that must
     * never regress — no exception reaches the screen, and the admin gets a
     * plain sentence saying what happened.
     */
    public function test_deleting_a_menu_option_used_in_past_orders_never_crashes(): void
    {
        $optionId = (int) DB::table('order_item_options')->value('menu_option_id');
        $this->assertNotSame(0, $optionId, 'this test needs an option that was used on a real order');

        $this->withoutExceptionHandling();

        $res = $this->actingAs($this->admin(), 'admin')
            ->delete('/admin/menu-options/' . $optionId);

        $res->assertRedirect(route('admin.menu-options'));
        $res->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            'archived',
            session('success'),
            'the admin should be told WHICH of delete/archive happened, in their own terms'
        );

        $this->assertNotNull(
            MenuOption::withArchived()->find($optionId),
            'the row must survive so the receipts that mention it stay complete'
        );
    }

    /** And the message must not leak SQL, table names or the constraint name. */
    public function test_the_outcome_message_leaks_no_database_internals(): void
    {
        $optionId = (int) DB::table('order_item_options')->value('menu_option_id');

        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-options/' . $optionId);

        $message = session('success') ?? session('errors')->first('error');

        $this->assertNotEmpty($message);

        foreach (['SQLSTATE', 'foreign key', 'order_item_options', 'pomida_db', 'select ', 'delete from'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $message);
        }
    }

    /** An unused option still deletes cleanly — the guard is not a blanket refusal. */
    public function test_an_unused_menu_option_still_deletes(): void
    {
        $option = MenuOption::create([
            'name' => 'Guard test option',
            'additional_price' => 5,
            'is_active' => true,
            'display_order' => 0,
        ]);

        $res = $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-options/' . $option->id);

        $res->assertRedirect(route('admin.menu-options'));
        $res->assertSessionHasNoErrors();
        $this->assertNull(MenuOption::find($option->id));
    }

    // ── every other admin delete ─────────────────────────────────────────────

    /**
     * The whole surface, exercised for real: no admin delete endpoint may
     * raise an exception, whatever state its target is in.
     */
    public function test_no_admin_delete_endpoint_ever_raises_an_exception(): void
    {
        $this->withoutExceptionHandling();

        $admin = $this->admin();

        // Targets deliberately chosen to be the awkward ones: rows that other
        // rows point at.
        $usedMenuItemId = (int) DB::table('order_items')->value('menu_item_id');
        $usedOptionId = (int) DB::table('order_item_options')->value('menu_option_id');
        $categoryWithItems = (int) MenuItem::whereNotNull('category_id')->value('category_id');

        $endpoints = [
            '/admin/menu-options/' . $usedOptionId,
            '/admin/menu-items/' . $usedMenuItemId,
            '/admin/add-category/' . $categoryWithItems,
        ];

        foreach ($endpoints as $uri) {
            $res = $this->actingAs($admin, 'admin')->delete($uri);

            $this->assertContains(
                $res->getStatusCode(),
                [302, 200],
                $uri . ' should redirect with a message, never blow up'
            );
            $this->assertNotSame(500, $res->getStatusCode(), $uri . ' returned a server error');
        }
    }

    public function test_a_category_with_menu_items_is_refused_with_a_reason(): void
    {
        $categoryId = (int) MenuItem::whereNotNull('category_id')->value('category_id');
        $this->assertNotSame(0, $categoryId);

        $res = $this->actingAs($this->admin(), 'admin')->delete('/admin/add-category/' . $categoryId);

        $res->assertSessionHasErrors('error');
        $this->assertNotNull(Category::find($categoryId));
    }

    /**
     * This used to assert the reported misbehaviour — the item was kept and
     * merely marked unavailable, so it sat in the Menu Items list forever.
     * It is now archived: the row still survives for history, but it leaves
     * the list, which is what the owner actually asked for. The full
     * lifecycle is covered in CatalogueLifecycleTest.
     */
    public function test_a_menu_item_used_in_past_orders_is_archived_rather_than_deleted(): void
    {
        $itemId = (int) DB::table('order_items')->value('menu_item_id');
        $this->assertNotSame(0, $itemId);

        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-items/' . $itemId);

        $this->assertNull(MenuItem::find($itemId), 'it must leave the normal list');

        $item = MenuItem::withArchived()->find($itemId);

        $this->assertNotNull($item, 'the row must survive, so past orders keep their details');
        $this->assertTrue($item->isArchived());
    }

    /** Deletes with no blocking references still work, unchanged by this round. */
    public function test_deletes_that_used_to_succeed_still_succeed(): void
    {
        $admin = $this->admin();

        $subcategory = Subcategory::create([
            'category_id' => Category::value('id'),
            'name' => 'Guard test subcategory',
            'display_order' => 0,
        ]);

        $this->actingAs($admin, 'admin')->delete('/admin/add-subcategory/' . $subcategory->id)
            ->assertSessionHasNoErrors();
        $this->assertNull(Subcategory::find($subcategory->id));

        $voucher = Voucher::create([
            'code' => 'GUARDTEST' . random_int(1000, 9999),
            'description' => 'Guard test',
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'max_uses' => 0,
            'used_count' => 0,
            'minimum_order' => 0,
            'is_active' => true,
            'points_required' => 0,
        ]);

        $this->actingAs($admin, 'admin')->delete('/admin/vouchers/' . $voucher->id)
            ->assertSessionHasNoErrors();
        $this->assertNull(Voucher::find($voucher->id));

        $ad = Ad::create(['title' => 'Guard test ad', 'is_active' => false]);

        $this->actingAs($admin, 'admin')->delete('/admin/ads/' . $ad->id)
            ->assertSessionHasNoErrors();
        $this->assertNull(Ad::find($ad->id));

        $inventory = Inventory::create([
            'branch_id' => 1,
            'item_code' => 'GUARD-' . random_int(1000, 9999),
            'item_name' => 'Guard test stock',
            'unit' => 'pcs',
            'quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')->delete('/admin/inventory/' . $inventory->id)
            ->assertSessionHasNoErrors();
        $this->assertNull(Inventory::find($inventory->id));
    }

    /** A delete of something that does not exist is a clean 404, not a 500. */
    public function test_deleting_a_missing_row_is_a_clean_404(): void
    {
        $admin = $this->admin();

        foreach ([
            '/admin/menu-options/99999999',
            '/admin/menu-items/99999999',
            '/admin/add-category/99999999',
            '/admin/add-subcategory/99999999',
            '/admin/vouchers/99999999',
            '/admin/ads/99999999',
            '/admin/inventory/99999999',
        ] as $uri) {
            $this->actingAs($admin, 'admin')->delete($uri)->assertNotFound();
        }
    }
}
