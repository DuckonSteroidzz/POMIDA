<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Subcategories had no Edit, only Delete — the only path to fixing a typo or
 * a wrong parent category was delete and re-create, which loses every menu
 * item filed under it (2026-09-02).
 *
 * INVESTIGATION
 * -------------
 * storeSubcategory() validates 'category_id' => 'required|exists:categories,id'
 * and 'name' => 'required|string|max:255', and enforces uniqueness with a
 * manual query — subcategories has NO unique index at all (confirmed against
 * the create-table migration) — scoped to (category_id, name): the SAME name
 * is fine in two different categories, only a duplicate within the same
 * parent is refused. updateSubcategory() already existed in the controller
 * but was dead code: no route pointed at it, and it was ALSO missing the
 * uniqueness check store has — the one place it had already drifted. Both
 * are fixed here, mirroring create exactly.
 *
 * menu_items -> subcategory is a direct foreign key (subcategory_id, ON
 * DELETE SET NULL), not a pivot — confirmed against the migration and
 * Subcategory::menuItems() (hasMany).
 *
 * THE PARENT-CHANGE QUESTION, answered before writing any code: menu_items
 * carries its OWN category_id, entirely independent of subcategory_id — both
 * are plain nullable foreign keys, and storeNewMenuItem() accepts them as two
 * uncorrelated inputs with no cross-check. The customer menu page filters by
 * MenuItem.category_id directly (AuthController::showMenu()) and never
 * consults subcategory_id. So moving a subcategory to a new parent, with
 * nothing else done, would leave every item filed under it showing on the
 * customer's OLD category while the subcategory itself claimed the new one —
 * a real, visible inconsistency. Smallest correct handling: cascade
 * category_id onto every item attached to the subcategory, in the same
 * transaction, only when the parent actually changes. subcategory_id is
 * never touched by this — items stay exactly where they were filed.
 *
 * LIVE DATA CHECKED, NOTHING MODIFIED: "Desserts" (id 799) is filed under
 * "ice cream" (id 24), not "Pizza" as described — reporting the data actually
 * found. One item, "Cookies and Cream" (id 34), is attached, with
 * category_id already consistent (24) with its subcategory's current parent.
 * Read only; this file never touches that row.
 */
class SubcategoryEditTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function category(string $name): Category
    {
        return Category::create(['name' => $name, 'display_order' => 0, 'is_active' => true]);
    }

    private function subcategory(Category $parent, string $name): Subcategory
    {
        return Subcategory::create([
            'category_id'    => $parent->id,
            'name'           => $name,
            'display_order'  => 0,
            'is_active'      => true,
        ]);
    }

    private function menuItem(Category $category, Subcategory $sub, string $name): MenuItem
    {
        return MenuItem::create([
            'category_id'    => $category->id,
            'subcategory_id' => $sub->id,
            'branch_id'      => 1,
            'name'           => $name,
            'price'          => 50,
            'is_available'   => true,
            'display_order'  => 0,
        ]);
    }

    // ══════════ rename ══════════

    public function test_renaming_updates_the_name_and_leaves_menu_items_attached(): void
    {
        $parent = $this->category('Rename Parent');
        $sub = $this->subcategory($parent, 'Old Name');
        $item = $this->menuItem($parent, $sub, 'Rename Test Item');

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-subcategory/' . $sub->id, [
                'category_id' => $parent->id,
                'name'        => 'New Name',
            ]);

        $response->assertRedirect(route('admin.add-category'));
        $response->assertSessionHasNoErrors();

        $sub->refresh();
        $this->assertSame('New Name', $sub->name);
        $this->assertSame($parent->id, $sub->category_id);

        $this->assertSame(
            $sub->id,
            $item->fresh()->subcategory_id,
            'the item must stay attached to the same subcategory after a rename'
        );
    }

    // ══════════ moving to a different parent ══════════

    /**
     * THE CORE SCENARIO. Assert the item->subcategory link explicitly before
     * and after, and assert the item's OWN category_id followed the move —
     * that second half is the fix for the inconsistency identified above.
     */
    public function test_moving_to_a_different_parent_updates_it_and_cascades_to_attached_items(): void
    {
        $oldParent = $this->category('Old Parent');
        $newParent = $this->category('New Parent');
        $sub = $this->subcategory($oldParent, 'Movable Sub');
        $item = $this->menuItem($oldParent, $sub, 'Movable Item');

        $this->assertSame($sub->id, $item->subcategory_id, 'setup: item must start attached');
        $this->assertSame($oldParent->id, $item->category_id, 'setup: item must start in the old category');

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-subcategory/' . $sub->id, [
                'category_id' => $newParent->id,
                'name'        => $sub->name,
            ])
            ->assertSessionHasNoErrors();

        $sub->refresh();
        $item->refresh();

        $this->assertSame($newParent->id, $sub->category_id, "the subcategory's parent must have moved");

        $this->assertSame(
            $sub->id,
            $item->subcategory_id,
            'the item must still be attached to the SAME subcategory after the move — not orphaned, not reassigned'
        );
        $this->assertSame(
            $newParent->id,
            $item->category_id,
            "the item's own category_id must follow its subcategory to the new parent, "
            . 'or the customer menu (which filters on category_id directly) would keep '
            . 'showing it under the old category'
        );
    }

    public function test_moving_with_multiple_items_cascades_to_all_of_them(): void
    {
        $oldParent = $this->category('Old Parent Multi');
        $newParent = $this->category('New Parent Multi');
        $sub = $this->subcategory($oldParent, 'Multi Sub');
        $a = $this->menuItem($oldParent, $sub, 'Multi A');
        $b = $this->menuItem($oldParent, $sub, 'Multi B');

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-subcategory/' . $sub->id, [
                'category_id' => $newParent->id,
                'name'        => $sub->name,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($newParent->id, $a->fresh()->category_id);
        $this->assertSame($newParent->id, $b->fresh()->category_id);
        $this->assertSame($sub->id, $a->fresh()->subcategory_id);
        $this->assertSame($sub->id, $b->fresh()->subcategory_id);
    }

    /**
     * The inverse control: when the parent does NOT change (a plain rename),
     * items' category_id must be left completely alone rather than
     * needlessly rewritten.
     */
    public function test_a_plain_rename_does_not_touch_item_category_ids(): void
    {
        $parent = $this->category('Stable Parent');
        $sub = $this->subcategory($parent, 'Stable Sub');
        $item = $this->menuItem($parent, $sub, 'Stable Item');

        $updatedAtBefore = $item->updated_at;

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-subcategory/' . $sub->id, [
                'category_id' => $parent->id,
                'name'        => 'Renamed Stable Sub',
            ])
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame($parent->id, $item->category_id);
        $this->assertTrue(
            $updatedAtBefore->eq($item->updated_at),
            'an item must not be written to at all when its subcategory\'s parent has not changed'
        );
    }

    // ══════════ invalid input ══════════

    public function test_an_empty_name_is_rejected_and_the_row_unchanged(): void
    {
        $parent = $this->category('Empty Name Parent');
        $sub = $this->subcategory($parent, 'Original Name');

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-subcategory/' . $sub->id, [
                'category_id' => $parent->id,
                'name'        => '',
            ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame('Original Name', $sub->fresh()->name);
    }

    public function test_a_missing_parent_category_is_rejected_and_the_row_unchanged(): void
    {
        $parent = $this->category('Missing Parent Test');
        $sub = $this->subcategory($parent, 'Sub Name');

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-subcategory/' . $sub->id, [
                'category_id' => '',
                'name'        => 'Sub Name',
            ]);

        $response->assertSessionHasErrors('category_id');
        $this->assertSame($parent->id, $sub->fresh()->category_id);
    }

    public function test_a_nonexistent_parent_category_is_rejected_and_the_row_unchanged(): void
    {
        $parent = $this->category('Nonexistent Parent Test');
        $sub = $this->subcategory($parent, 'Sub Name');

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-subcategory/' . $sub->id, [
                'category_id' => 999999999,
                'name'        => 'Sub Name',
            ]);

        $response->assertSessionHasErrors('category_id');
        $this->assertSame($parent->id, $sub->fresh()->category_id);
    }

    // ══════════ uniqueness, scoped to the parent category ══════════

    public function test_a_duplicate_name_within_the_same_parent_is_rejected_cleanly(): void
    {
        $parent = $this->category('Dup Scope Parent');
        $this->subcategory($parent, 'Taken Name');
        $sub = $this->subcategory($parent, 'Original Name');

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-subcategory/' . $sub->id, [
                'category_id' => $parent->id,
                'name'        => 'Taken Name',
            ]);

        $response->assertSessionHasErrors('name');
        $this->assertStringContainsString(
            'already exists',
            session('errors')->first('name')
        );
        $this->assertSame('Original Name', $sub->fresh()->name, 'a rejected rename must not have applied');
    }

    /**
     * The SAME name is fine in a DIFFERENT category — this is what "scoped to
     * the parent" means and is what distinguishes this from a global
     * uniqueness rule.
     */
    public function test_the_same_name_is_allowed_in_a_different_parent_category(): void
    {
        $parentA = $this->category('Scope A');
        $parentB = $this->category('Scope B');
        $this->subcategory($parentA, 'Shared Name');
        $sub = $this->subcategory($parentB, 'Different For Now');

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-subcategory/' . $sub->id, [
                'category_id' => $parentB->id,
                'name'        => 'Shared Name',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Shared Name', $sub->fresh()->name);
    }

    /**
     * A subcategory renamed to ITS OWN current name must not be rejected as
     * a duplicate of itself — the uniqueness check excludes the row being
     * edited, the same way the inventory item_code fix does.
     */
    public function test_keeping_the_current_name_unchanged_is_not_rejected_as_a_duplicate_of_itself(): void
    {
        $parent = $this->category('Self Duplicate Parent');
        $sub = $this->subcategory($parent, 'Same Name');

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-subcategory/' . $sub->id, [
                'category_id' => $parent->id,
                'name'        => 'Same Name',
            ])
            ->assertSessionHasNoErrors();
    }

    // ══════════ untouched: create and delete ══════════

    public function test_the_create_flow_still_works_unchanged(): void
    {
        $parent = $this->category('Create Flow Parent');

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/add-subcategory', [
                'category_id' => $parent->id,
                'name'        => 'Create Flow Sub',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(
            Subcategory::where('category_id', $parent->id)->where('name', 'Create Flow Sub')->exists()
        );
    }
}
