<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menu Options had no way to fix a typo or reprice an add-on except delete
 * and recreate it — which loses every menu-item assignment
 * (menu_item_options) the option had (2026-09-02).
 *
 * INVESTIGATION
 * -------------
 * Create path (storeMenuOption): 'name' => 'required|string|max:255',
 * 'price' => 'nullable|numeric|min:0'. The edit form and updateMenuOption()
 * mirror exactly these two rules on exactly these two fields — 'description'
 * is in storeMenuOption()'s validate() array but the Add Option FORM never
 * actually sends one, so the edit form matches what create really does, not
 * just what it theoretically allows.
 *
 * PAST ORDERS — SNAPSHOT, NOT LIVE. order_item_options has its own
 * option_name and additional_price columns, explicitly commented "// Snapshot"
 * in the table's migration, written once at order time
 * (OrderController::placeOrder() / AdminController's manual-order path) and
 * never touched again. Order totals and order_items.subtotal are computed
 * once at order time from those values and never recalculated from the live
 * MenuOption, so editing an option's PRICE cannot retroactively change any
 * money figure on a past order.
 *
 * ONE CAVEAT FOUND, NOT FIXED HERE: customer/receipt.blade.php's add-on badge
 * reads `$option->name` — the LIVE MenuOption relation — not
 * `$option->pivot->option_name`, the snapshot the schema was built to
 * protect. Confirmed empirically (tinker): $option->pivot->option_name and
 * $option->name currently agree because nothing has been renamed yet, but
 * they are reading different columns. So renaming an option in this pass DOES
 * currently change the add-on label shown on old receipts, even though the
 * price and every order total stay exactly correct. Flagged for the owner's
 * decision; out of scope to fix here.
 */
class MenuOptionEditTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->first();
    }

    private function freshOption(string $name = 'Test Option', float $price = 5): MenuOption
    {
        return MenuOption::create([
            'name' => $name,
            'additional_price' => $price,
            'is_active' => true,
            'display_order' => 0,
        ]);
    }

    private function freshItem(string $name = 'Option-edit test item'): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => 1,
            'name'          => $name,
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    /** Raw pivot rows for one option, sorted for a stable comparison. */
    private function pivotRowsFor(MenuOption $option): array
    {
        return DB::table('menu_item_options')
            ->where('menu_option_id', $option->id)
            ->orderBy('menu_item_id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    // ══════════ investigation, pinned ══════════

    public function test_past_orders_snapshot_the_option_name_and_price(): void
    {
        $migration = file_get_contents(
            database_path('migrations/2026_04_27_031134_create_order_item_options_table.php')
        );

        $this->assertStringContainsString('option_name', $migration);
        $this->assertStringContainsString('additional_price', $migration);
        $this->assertStringContainsString('Snapshot', $migration);
    }

    // ══════════ the edit itself ══════════

    public function test_editing_an_option_updates_its_name_and_price(): void
    {
        $option = $this->freshOption('unli gravy', 20);

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name'  => 'Unli Gravy (Large)',
                'price' => 35,
            ]);

        $response->assertRedirect(route('admin.menu-options'));
        $response->assertSessionHasNoErrors();

        $option->refresh();
        $this->assertSame('Unli Gravy (Large)', $option->name);
        $this->assertSame('35.00', (string) $option->additional_price);
    }

    public function test_editing_an_option_to_free_is_allowed(): void
    {
        // price is nullable|numeric|min:0 on create; the edit path must
        // accept the same range, including dropping to free.
        $option = $this->freshOption('extra sauce', 15);

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name'  => 'Extra Sauce',
                'price' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', (string) $option->fresh()->additional_price);
    }

    // ══════════ the main regression risk: assignments must survive ══════════

    public function test_editing_an_option_leaves_its_menu_item_assignments_untouched(): void
    {
        $option = $this->freshOption('cheese', 10);
        $itemA = $this->freshItem('Pizza A');
        $itemB = $this->freshItem('Pizza B');

        $option->menuItems()->attach([$itemA->id, $itemB->id]);

        $before = $this->pivotRowsFor($option);
        $this->assertCount(2, $before, 'setup should have created exactly two pivot rows');

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name'  => 'Cheese (Extra)',
                'price' => 25,
            ])
            ->assertSessionHasNoErrors();

        $after = $this->pivotRowsFor($option);

        $this->assertSame(
            $before,
            $after,
            'editing name/price must not touch menu_item_options at all — every column, including timestamps'
        );

        // And the relationship itself still resolves to the same two items.
        $this->assertEqualsCanonicalizing(
            [$itemA->id, $itemB->id],
            $option->fresh()->menuItems->pluck('id')->all()
        );
    }

    public function test_editing_an_unassigned_option_still_has_no_assignments_afterward(): void
    {
        // The mirror case: an option with ZERO assignments must not
        // accidentally gain one from the edit itself.
        $option = $this->freshOption('no assignments yet', 5);

        $this->assertSame([], $this->pivotRowsFor($option));

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $option->id, ['name' => 'Renamed', 'price' => 5])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->pivotRowsFor($option));
    }

    public function test_editing_one_options_assignments_does_not_touch_a_different_options(): void
    {
        $edited = $this->freshOption('edited option', 10);
        $other  = $this->freshOption('untouched option', 8);
        $item   = $this->freshItem('Shared-context item');

        $edited->menuItems()->attach($item->id);
        $other->menuItems()->attach($item->id);

        $otherBefore = $this->pivotRowsFor($other);

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $edited->id, ['name' => 'Edited Option', 'price' => 12])
            ->assertSessionHasNoErrors();

        $this->assertSame($otherBefore, $this->pivotRowsFor($other));
    }

    // ══════════ validation mirrors create ══════════

    public function test_an_empty_name_is_rejected_and_the_option_unchanged(): void
    {
        $option = $this->freshOption('Original Name', 10);

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name'  => '',
                'price' => 10,
            ]);

        $response->assertSessionHasErrors('name');

        $option->refresh();
        $this->assertSame('Original Name', $option->name);
        $this->assertSame('10.00', (string) $option->additional_price);
    }

    public function test_a_negative_price_is_rejected_and_the_option_unchanged(): void
    {
        $option = $this->freshOption('Original Name', 10);

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name'  => 'Attempted Rename',
                'price' => -5,
            ]);

        $response->assertSessionHasErrors('price');

        $option->refresh();
        $this->assertSame('Original Name', $option->name);
        $this->assertSame('10.00', (string) $option->additional_price);
    }

    public function test_a_negative_price_leaves_assignments_untouched_too(): void
    {
        // The rejected-edit path must be exercised with an assignment present,
        // not just the happy path — a validation failure must not be able to
        // reach the pivot at all.
        $option = $this->freshOption('Has assignment', 10);
        $item = $this->freshItem('Guard item');
        $option->menuItems()->attach($item->id);

        $before = $this->pivotRowsFor($option);

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $option->id, ['name' => 'x', 'price' => -1])
            ->assertSessionHasErrors('price');

        $this->assertSame($before, $this->pivotRowsFor($option));
    }
}
