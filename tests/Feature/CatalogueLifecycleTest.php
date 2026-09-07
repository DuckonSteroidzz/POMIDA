<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\CatalogueLifecycle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Delete vs archive across the whole admin catalogue.
 *
 * THE REPORT
 * ----------
 * Deleting the menu item "coke" produced "Item 'coke' hidden from menu (has
 * existing orders)" and the item simply stayed in the list with Available
 * unticked. The category could then never be deleted either: "Cannot delete
 * 'ice cream' — it has 1 menu item(s) linked" — blocked by the very item the
 * owner had already tried to remove. In their words: if you want to replace an
 * item the list should get clean, "hindi ung natatago lang".
 *
 * THE FIX BEING PINNED HERE
 * -------------------------
 * One checkbox was doing two jobs. They are now separate concepts:
 *
 *   is_available = false   temporary. Stays in the list. Comes back.
 *   archived_at  = <time>  permanent. Leaves the list, stops being orderable,
 *                          restorable from the Archived area.
 *
 * and removal is decided by whether anything actually references the record:
 * nothing does -> the row is genuinely deleted; something does -> it is
 * archived so receipts and sales history stay truthful.
 *
 * Both branches are asserted for all four record types, because the branch
 * that did not exist before (a real hard delete) is the one the owner cares
 * about, and the branch that did (keeping the row) is the one the panel will
 * ask about.
 */
class CatalogueLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->first();
    }

    private function staff(): User
    {
        return User::where('role', 'staff')->orderBy('id')->first();
    }

    /** A brand-new item nothing has ever ordered. */
    private function freshItem(string $name = 'Lifecycle test item', ?int $categoryId = null, ?int $subcategoryId = null): MenuItem
    {
        return MenuItem::create([
            'category_id'    => $categoryId ?? Category::value('id'),
            'subcategory_id' => $subcategoryId,
            'branch_id'      => 1,
            'name'           => $name,
            'price'          => 25,
            'is_available'   => true,
            'display_order'  => 0,
        ]);
    }

    private function freshOption(string $name = 'Lifecycle test option'): MenuOption
    {
        return MenuOption::create([
            'name' => $name, 'additional_price' => 5, 'is_active' => true, 'display_order' => 0,
        ]);
    }

    /** Give an item a real past order line, so it becomes referenced. */
    private function sell(MenuItem $item): int
    {
        return DB::table('order_items')->insertGetId([
            'order_id'     => Order::where('status', 'completed')->value('id') ?? Order::value('id'),
            'menu_item_id' => $item->id,
            'item_name'    => $item->name,
            'item_price'   => $item->price,
            'quantity'     => 1,
            'subtotal'     => $item->price,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // ── MENU ITEMS ───────────────────────────────────────────────────────────

    public function test_an_unreferenced_menu_item_is_genuinely_deleted(): void
    {
        $item = $this->freshItem();
        $id = $item->id;

        $res = $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-items/' . $id);

        $res->assertSessionHasNoErrors();
        $this->assertStringContainsString('permanently deleted', session('success'));

        // Not archived, not hidden — gone. Checked against the raw table so a
        // scope cannot make an existing row merely look absent.
        $this->assertSame(0, DB::table('menu_items')->where('id', $id)->count());
    }

    public function test_a_referenced_menu_item_is_archived_and_leaves_the_list(): void
    {
        $item = $this->freshItem('Lifecycle sold item');
        $orderLineId = $this->sell($item);

        $res = $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-items/' . $item->id);

        $res->assertSessionHasNoErrors();
        $this->assertStringContainsString('archived, not deleted', session('success'));

        $this->assertNull(MenuItem::find($item->id), 'it must leave the normal list');
        $this->assertTrue(MenuItem::withArchived()->find($item->id)->isArchived());
        $this->assertSame(1, DB::table('menu_items')->where('id', $item->id)->count(), 'the row survives');

        DB::table('order_items')->where('id', $orderLineId)->delete();
    }

    /**
     * The distinction the whole round exists for: archiving is NOT the same as
     * unticking Available, and must not be conflated with it again.
     */
    public function test_archiving_is_a_different_thing_from_being_unavailable(): void
    {
        $unavailable = $this->freshItem('Lifecycle out of stock');
        $unavailable->is_available = false;
        $unavailable->save();

        // Temporarily unavailable: still in the list, still findable, comes back.
        $this->assertNotNull(MenuItem::find($unavailable->id));
        $this->assertFalse($unavailable->isArchived());

        $archived = $this->freshItem('Lifecycle removed');
        $archived->archive();

        // Archived: out of the list entirely, and its availability flag is
        // untouched — the two concepts do not overwrite each other.
        $this->assertNull(MenuItem::find($archived->id));
        $this->assertTrue($archived->is_available, 'archiving must not repurpose is_available');
    }

    // ── MENU OPTIONS ─────────────────────────────────────────────────────────

    public function test_an_unreferenced_menu_option_is_genuinely_deleted(): void
    {
        $option = $this->freshOption();
        $id = $option->id;

        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-options/' . $id)
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('permanently deleted', session('success'));
        $this->assertSame(0, DB::table('menu_options')->where('id', $id)->count());
    }

    public function test_a_referenced_menu_option_is_archived(): void
    {
        // The exact FK that used to crash: order_item_options.menu_option_id.
        $optionId = (int) DB::table('order_item_options')->value('menu_option_id');
        $this->assertNotSame(0, $optionId, 'this test needs an option used on a real order');

        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-options/' . $optionId)
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('archived, not deleted', session('success'));
        $this->assertNull(MenuOption::find($optionId));
        $this->assertNotNull(MenuOption::withArchived()->find($optionId));
    }

    // ── CATEGORIES ───────────────────────────────────────────────────────────

    public function test_an_empty_category_is_genuinely_deleted(): void
    {
        $category = Category::create(['name' => 'Lifecycle empty cat', 'display_order' => 0, 'is_active' => true]);
        $id = $category->id;

        $this->actingAs($this->admin(), 'admin')->delete('/admin/add-category/' . $id)
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('permanently deleted', session('success'));
        $this->assertSame(0, DB::table('categories')->where('id', $id)->count());
    }

    /** THE OWNER'S DEAD END: blocked by an item they had already removed. */
    public function test_a_category_whose_only_items_are_archived_can_be_removed(): void
    {
        $category = Category::create(['name' => 'Lifecycle ice cream', 'display_order' => 0, 'is_active' => true]);
        $item = $this->freshItem('Lifecycle cone', $category->id);
        $orderLineId = $this->sell($item);

        // Step 1: remove the item. It has history, so it archives.
        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-items/' . $item->id);
        $this->assertTrue(MenuItem::withArchived()->find($item->id)->isArchived());

        // Step 2: remove the category. This used to say "Cannot delete — it has
        // 1 menu item(s) linked" forever.
        $res = $this->actingAs($this->admin(), 'admin')->delete('/admin/add-category/' . $category->id);

        $res->assertSessionHasNoErrors();
        $this->assertStringContainsString('archived, not deleted', session('success'));
        $this->assertNull(Category::find($category->id), 'it must leave the Categories list');
        $this->assertNotNull(Category::withArchived()->find($category->id));

        // The archived item under it is untouched — that is what keeps history readable.
        $this->assertSame(1, DB::table('menu_items')->where('id', $item->id)->count());

        DB::table('order_items')->where('id', $orderLineId)->delete();
    }

    /** A category with LIVE items is still refused — that is not a dead end. */
    public function test_a_category_with_live_items_is_refused_with_an_actionable_reason(): void
    {
        $category = Category::create(['name' => 'Lifecycle busy cat', 'display_order' => 0, 'is_active' => true]);
        $this->freshItem('Lifecycle live item', $category->id);

        $res = $this->actingAs($this->admin(), 'admin')->delete('/admin/add-category/' . $category->id);

        $res->assertSessionHasErrors('error');
        $this->assertStringContainsString('Delete or archive those first', session('errors')->first('error'));
        $this->assertNotNull(Category::find($category->id));
    }

    /** Archiving a category takes its subcategories with it, so none are orphaned. */
    public function test_archiving_a_category_archives_its_subcategories(): void
    {
        $category = Category::create(['name' => 'Lifecycle parent cat', 'display_order' => 0, 'is_active' => true]);
        $sub = Subcategory::create(['category_id' => $category->id, 'name' => 'Lifecycle child sub', 'display_order' => 0]);
        $item = $this->freshItem('Lifecycle filed item', $category->id, $sub->id);
        $orderLineId = $this->sell($item);

        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-items/' . $item->id);
        $this->actingAs($this->admin(), 'admin')->delete('/admin/add-category/' . $category->id);

        $this->assertNull(Subcategory::find($sub->id), 'a subcategory must not outlive its archived parent in the list');
        $this->assertTrue(Subcategory::withArchived()->find($sub->id)->isArchived());

        DB::table('order_items')->where('id', $orderLineId)->delete();
    }

    // ── SUBCATEGORIES ────────────────────────────────────────────────────────

    public function test_an_empty_subcategory_is_genuinely_deleted(): void
    {
        $sub = Subcategory::create([
            'category_id' => Category::value('id'), 'name' => 'Lifecycle empty sub', 'display_order' => 0,
        ]);
        $id = $sub->id;

        $this->actingAs($this->admin(), 'admin')->delete('/admin/add-subcategory/' . $id)
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('permanently deleted', session('success'));
        $this->assertSame(0, DB::table('subcategories')->where('id', $id)->count());
    }

    /**
     * A subcategory holding items archives rather than deleting — not because
     * anything would break (menu_items.subcategory_id is SET NULL) but because
     * deleting it would silently un-file items the admin did not ask to touch.
     */
    public function test_a_subcategory_holding_items_is_archived_and_the_items_keep_their_filing(): void
    {
        $sub = Subcategory::create([
            'category_id' => Category::value('id'), 'name' => 'Lifecycle busy sub', 'display_order' => 0,
        ]);
        $item = $this->freshItem('Lifecycle filed live item', null, $sub->id);

        $this->actingAs($this->admin(), 'admin')->delete('/admin/add-subcategory/' . $sub->id)
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('archived, not deleted', session('success'));
        $this->assertNull(Subcategory::find($sub->id));

        // The item is untouched: still live, still filed, still orderable.
        $item->refresh();
        $this->assertSame($sub->id, $item->subcategory_id);
        $this->assertNotNull(MenuItem::find($item->id));
    }

    // ── RESTORE ──────────────────────────────────────────────────────────────

    public function test_restoring_returns_a_record_to_the_normal_list(): void
    {
        $item = $this->freshItem('Lifecycle restore me');
        $item->archive();

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/archived/menu-item/' . $item->id . '/restore')
            ->assertSessionHasNoErrors();

        $this->assertNotNull(MenuItem::find($item->id));
        $this->assertStringContainsString('was restored', session('success'));
    }

    /**
     * Restoring an item whose category was archived in the meantime restores
     * the parents too — otherwise the item returns to the admin list but still
     * cannot appear on the customer menu, which is built by joining categories.
     */
    public function test_restoring_an_item_also_restores_an_archived_parent(): void
    {
        $category = Category::create(['name' => 'Lifecycle gone cat', 'display_order' => 0, 'is_active' => true]);
        $sub = Subcategory::create(['category_id' => $category->id, 'name' => 'Lifecycle gone sub', 'display_order' => 0]);
        $item = $this->freshItem('Lifecycle orphan', $category->id, $sub->id);

        $item->archive();
        $sub->archive();
        $category->archive();

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/archived/menu-item/' . $item->id . '/restore');

        $this->assertNotNull(MenuItem::find($item->id));
        $this->assertNotNull(Category::find($category->id), 'the parent category must come back too');
        $this->assertNotNull(Subcategory::find($sub->id));
        $this->assertStringContainsString('had also been archived', session('success'));
    }

    /** Restoring a parent must NOT drag back children archived on purpose. */
    public function test_restoring_a_category_leaves_its_archived_items_archived(): void
    {
        $category = Category::create(['name' => 'Lifecycle mixed cat', 'display_order' => 0, 'is_active' => true]);
        $item = $this->freshItem('Lifecycle stays gone', $category->id);

        $item->archive();
        $category->archive();

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/archived/category/' . $category->id . '/restore');

        $this->assertNotNull(Category::find($category->id));
        $this->assertNull(MenuItem::find($item->id), 'the item was archived deliberately and stays archived');
    }

    public function test_restoring_something_already_restored_is_explained_not_crashed(): void
    {
        $item = $this->freshItem('Lifecycle not archived');

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/archived/menu-item/' . $item->id . '/restore')
            ->assertSessionHasErrors('error');
    }

    public function test_an_unknown_archive_type_is_a_404_not_a_dynamic_model_lookup(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/archived/users/1/restore')
            ->assertNotFound();
    }

    // ── THE ARCHIVED AREA ────────────────────────────────────────────────────

    public function test_the_archived_page_lists_what_was_archived_and_when(): void
    {
        $item = $this->freshItem('Lifecycle listed item');
        $item->archive();

        $html = $this->actingAs($this->admin(), 'admin')->get('/admin/archived')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Lifecycle listed item', $html);
        $this->assertStringContainsString($item->fresh()->archived_at->format('M d, Y'), $html);
        $this->assertStringContainsString('Restore', $html);
    }

    /**
     * The two standing explanatory blocks — the intro paragraph under the
     * "Archived Items" heading and the "Archived is not the same as
     * Unavailable" callout above the list — were removed on request; the
     * owner found the page read cleaner without them once it had been used a
     * few times. The empty-state card is a DIFFERENT piece of copy (only
     * shown when nothing is archived) and was explicitly asked to stay —
     * covered by test_the_archived_link_still_renders_at_zero_count() above,
     * which already asserts it is present.
     */
    public function test_the_two_standing_explanatory_blocks_are_gone(): void
    {
        $item = $this->freshItem('Lifecycle removed-copy probe');
        $item->archive();

        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/archived')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Things you have removed from the business', $html);
        $this->assertStringNotContainsString('Archived is not the same as Unavailable', $html);

        // The page itself, and this one archived row, must still be there —
        // this is a copy removal, not a feature removal.
        $this->assertStringContainsString('Archived Items', $html);
        $this->assertStringContainsString('Lifecycle removed-copy probe', $html);
    }

    /**
     * THE EXACT REPORTED BUG
     * -----------------------
     * "paano kung gusto ko i-restore o i-check ang archive, kailangan ko pa
     * mag-delete?" — the Archived entry point only rendered once something
     * had already been archived (the link sat behind `@if(!empty($archivedCount))`
     * on all four catalogue pages), so a first-time user — or anyone checking
     * whether anything needs restoring — had no way to even discover the
     * feature. It is a permanent part of these screens now, always visible,
     * reading "Archived (0)" rather than disappearing.
     *
     * Every pre-existing archived row is unarchived FIRST so this genuinely
     * exercises the zero-count case rather than happening to pass because
     * something else in the database is already archived; DatabaseTransactions
     * rolls all of it back afterward.
     */
    public function test_the_archived_link_still_renders_at_zero_count(): void
    {
        foreach ([MenuItem::class, MenuOption::class, Category::class, Subcategory::class] as $model) {
            foreach ($model::onlyArchived()->get() as $row) {
                $row->unarchive();
            }
        }

        $this->assertSame(
            0,
            MenuItem::onlyArchived()->count()
                + MenuOption::onlyArchived()->count()
                + Category::onlyArchived()->count()
                + Subcategory::onlyArchived()->count(),
            'the catalogue must be genuinely archive-free for this test to mean anything'
        );

        foreach (['/admin/menu-items', '/admin/menu-options', '/admin/add-category'] as $uri) {
            $html = $this->actingAs($this->admin(), 'admin')->get($uri)->assertOk()->getContent();

            $this->assertStringContainsString(
                route('admin.archived'),
                $html,
                $uri . ' hid the archive link once the count reached zero'
            );

            $this->assertMatchesRegularExpression(
                '/Archived\s*\(?0\)?|0\s*Archived/i',
                $html,
                $uri . ' does not show a zero count for the archive'
            );
        }

        // And the destination itself explains the feature rather than showing
        // a blank page — the friendly empty state a first-time user needs.
        $emptyPage = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/archived')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Nothing is archived', $emptyPage);
    }

    public function test_the_catalogue_pages_link_to_the_archive(): void
    {
        $item = $this->freshItem('Lifecycle link probe');
        $item->archive();

        // GET /admin/add-subcategory is only a redirect to the Categories page —
        // that is where the subcategory table actually lives — so these three
        // are every catalogue page an admin can land on.
        foreach (['/admin/menu-items', '/admin/menu-options', '/admin/add-category'] as $uri) {
            $html = $this->actingAs($this->admin(), 'admin')->get($uri)->assertOk()->getContent();

            $this->assertStringContainsString(
                route('admin.archived'),
                $html,
                $uri . ' must offer a way to reach the archive'
            );
        }
    }

    /**
     * An outcome the admin never sees is the same silent failure this round
     * exists to remove. Removing a subcategory used to redirect to
     * /admin/add-subcategory, which redirects again to the Categories page —
     * and the flash message did not survive that second hop.
     */
    public function test_the_subcategory_outcome_message_reaches_the_page_the_admin_lands_on(): void
    {
        $sub = Subcategory::create([
            'category_id' => Category::value('id'), 'name' => 'Lifecycle message probe', 'display_order' => 0,
        ]);

        $res = $this->actingAs($this->admin(), 'admin')->delete('/admin/add-subcategory/' . $sub->id);

        $res->assertRedirect(route('admin.add-category'));

        $html = $this->actingAs($this->admin(), 'admin')->get('/admin/add-category')->getContent();

        $this->assertStringContainsString('Lifecycle message probe', $html);
    }

    // ── ROLE SCOPING (item 21 matrix) ────────────────────────────────────────

    /**
     * Staff run shifts; they do not change what the business sells. Verified
     * with the real staff account against the real routes, and by checking
     * nothing actually happened — a redirect alone would not prove refusal.
     */
    public function test_staff_cannot_archive_delete_or_restore_anything(): void
    {
        $staff = $this->staff();
        $this->assertNotNull($staff, 'this test needs a real staff account');

        $item = $this->freshItem('Lifecycle staff probe');
        $archived = $this->freshItem('Lifecycle staff restore probe');
        $archived->archive();

        /*
         * VIEWING the archive is deliberately NOT in this list any more — see
         * test_staff_can_view_but_not_restore_the_archive() below. A later
         * round moved GET /admin/archived into the same role:admin,staff group
         * as GET /admin/menu-items, on the reasoning that seeing what has been
         * archived is read-only information and should follow the same rule as
         * seeing the main list; RESTORING is the actual destructive action and
         * is still probed here.
         */
        $probes = [
            ['delete', '/admin/menu-items/' . $item->id],
            ['delete', '/admin/menu-options/' . MenuOption::value('id')],
            ['delete', '/admin/add-category/' . Category::value('id')],
            ['delete', '/admin/add-subcategory/' . Subcategory::value('id')],
            ['put', '/admin/archived/menu-item/' . $archived->id . '/restore'],
        ];

        foreach ($probes as [$method, $uri]) {
            $this->actingAs($staff, 'admin')->{$method}($uri)
                ->assertRedirect(route('admin.home'));
        }

        // Nothing moved.
        $this->assertNotNull(MenuItem::find($item->id), 'staff must not be able to remove an item');
        $this->assertTrue(MenuItem::withArchived()->find($archived->id)->isArchived(), 'staff must not be able to restore');
    }

    /**
     * THE REPORTED BUG THIS PINS
     * --------------------------
     * The Archived entry point only appeared once something had actually been
     * archived, and — before this round — a staff member could not reach it at
     * all even when it did appear, because GET /admin/archived sat in the same
     * admin-only bucket as the destructive actions. That conflated "look" with
     * "touch": an owner (or a staff member covering the same screens) had no
     * way to check or restore anything without first archiving something new.
     *
     * Staff can see the Menu Items list (role:admin,staff), so seeing the
     * archive built from that same catalogue now follows the same rule.
     * Restoring stays admin-only, both on the route and in the view — a staff
     * viewer sees "View only" instead of the Restore button.
     */
    public function test_staff_can_view_but_not_restore_the_archive(): void
    {
        $staff = $this->staff();
        $archived = $this->freshItem('Lifecycle staff view probe');
        $archived->archive();

        $response = $this->actingAs($staff, 'admin')->get('/admin/archived');

        $response->assertOk();
        $response->assertSee('Lifecycle staff view probe');
        $response->assertDontSee('/restore"', false);
        $response->assertSee('View only');

        // The backend guard is what actually matters, not just the missing
        // button — probe the destructive route directly.
        $this->actingAs($staff, 'admin')
            ->put('/admin/archived/menu-item/' . $archived->id . '/restore')
            ->assertRedirect(route('admin.home'));

        $this->assertTrue(
            MenuItem::withArchived()->find($archived->id)->isArchived(),
            'staff must not be able to restore even after viewing the archive'
        );
    }

    /** But staff keep the availability toggle — that is running a shift, not editing the catalogue. */
    public function test_staff_can_still_toggle_availability(): void
    {
        $item = $this->freshItem('Lifecycle toggle probe');

        $this->actingAs($this->staff(), 'admin')
            ->put('/admin/menu-items/toggle/' . $item->id)
            ->assertRedirect();

        $this->assertFalse((bool) $item->fresh()->is_available);
        $this->assertFalse($item->fresh()->isArchived(), 'a shift toggle must never archive anything');
    }

    // ── THE SERVICE ITSELF ───────────────────────────────────────────────────

    public function test_the_lifecycle_refuses_types_it_does_not_understand(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CatalogueLifecycle::class)->remove(User::first());
    }

    public function test_re_archiving_keeps_the_original_date(): void
    {
        $item = $this->freshItem('Lifecycle double archive');
        $item->archive();

        $first = $item->fresh()->archived_at;

        $item->archive();

        $this->assertEquals($first, $item->fresh()->archived_at);
    }
}
