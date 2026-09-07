<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Trashing a menu option must archive it and keep its menu-item assignments,
 * and restoring must bring them back (2026-09-02).
 *
 * WHAT WAS ALREADY WORKING — most of it
 * -------------------------------------
 * Archivable + NotArchivedScope, MenuOption using them, the archived_at
 * column, the /admin/archived page reachable from the Menu Options header
 * chip, and an admin-only restore action all already existed. Proven before
 * changing anything, not assumed:
 *
 *   - archiving never touches menu_item_options (it only stamps archived_at),
 *     so assignments survive archive AND restore for free: an option on two
 *     items went 2 -> 2 -> 2 across archive and restore, with no manual
 *     re-assignment anywhere.
 *   - an archived option drops off the customer surface: $item->options went
 *     1 -> 0 and the option vanished from the item-details page.
 *   - a past order still renders it while archived — the receipt returned 200
 *     and printed the option name, because OrderItem::options() carries a
 *     permanent withoutGlobalScope() for exactly this reason.
 *
 * THE ONE REAL GAP
 * ----------------
 * removeMenuOption() decided ONLY on order history. An option assigned to
 * menu items but never actually ordered was HARD DELETED, and because
 * menu_item_options.menu_option_id is ON DELETE CASCADE (verified against the
 * live schema) its assignments were destroyed with it: proven at pivot count
 * 2 -> 0, row gone. So the same trash click either archived safely or
 * destroyed permanently depending only on whether a customer had ever picked
 * that add-on — and in the destroyed case there was nothing left to restore.
 *
 * Fixed by counting menu-item assignments as a reference too, so an option is
 * archived when EITHER exists and hard-deleted only when nothing points at it
 * at all.
 */
class MenuOptionArchivePreservesLinksTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function option(string $name): MenuOption
    {
        return MenuOption::create([
            'name' => $name,
            'additional_price' => 5,
            'is_active' => true,
            'display_order' => 0,
        ]);
    }

    private function item(string $name): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => 1,
            'name'          => $name,
            'price'         => 50,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    /** The raw pivot rows, so assignments are asserted directly and not through a scope. */
    private function links(MenuOption $option): array
    {
        return DB::table('menu_item_options')
            ->where('menu_option_id', $option->id)
            ->orderBy('menu_item_id')
            ->pluck('menu_item_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Give an option a real past order line, the other kind of reference. */
    private function sell(MenuOption $option, MenuItem $item): Order
    {
        $order = Order::create([
            'order_number'   => 'MOA-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'completed',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal'       => 55,
            'total'          => 55,
        ]);

        $orderItemId = DB::table('order_items')->insertGetId([
            'order_id'     => $order->id,
            'menu_item_id' => $item->id,
            'item_name'    => $item->name,
            'item_price'   => 50,
            'quantity'     => 1,
            'subtotal'     => 55,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('order_item_options')->insert([
            'order_item_id'    => $orderItemId,
            'menu_option_id'   => $option->id,
            'option_name'      => $option->name,
            'additional_price' => $option->additional_price,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $order;
    }

    // ══════════ archive preserves assignments ══════════

    /**
     * THE CORE REQUIREMENT. Assignments asserted explicitly before and after,
     * on the raw pivot table.
     */
    public function test_archiving_an_assigned_option_preserves_both_pivot_rows(): void
    {
        $option = $this->option('Archive Keeps Links');
        $a = $this->item('AKL Item A');
        $b = $this->item('AKL Item B');
        $option->menuItems()->attach([$a->id, $b->id]);

        $before = $this->links($option);
        $this->assertSame([$a->id, $b->id], $before, 'setup: both assignments must exist');

        $this->actingAs($this->admin(), 'admin')
            ->delete('/admin/menu-options/' . $option->id)
            ->assertRedirect(route('admin.menu-options'));

        $archived = MenuOption::withArchived()->find($option->id);

        $this->assertNotNull($archived, 'the option must survive as an archived row, not be destroyed');
        $this->assertTrue($archived->isArchived(), 'the option must be archived');

        $this->assertSame(
            $before,
            $this->links($option),
            'archiving must not touch menu_item_options — the assignments are the '
            . "admin's configuration work, not disposable children"
        );
    }

    public function test_restoring_brings_back_exactly_the_same_two_assignments(): void
    {
        $option = $this->option('Restore Keeps Links');
        $a = $this->item('RKL Item A');
        $b = $this->item('RKL Item B');
        $option->menuItems()->attach([$a->id, $b->id]);

        $before = $this->links($option);

        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-options/' . $option->id);

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/archived/menu-option/' . $option->id . '/restore')
            ->assertRedirect(route('admin.archived'));

        $restored = MenuOption::withArchived()->find($option->id);

        $this->assertFalse($restored->isArchived(), 'the option must be back on the normal list');

        $this->assertSame(
            $before,
            $this->links($option),
            'restoring must bring back exactly the same assignments, with no re-assigning by hand'
        );

        // And it resolves through the relationship, not just the raw table.
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $restored->menuItems->pluck('id')->all()
        );
    }

    // ══════════ customers must not see an archived option ══════════

    /**
     * Asserted against the customer-facing read itself — the item's options
     * relation, which is what the item-details page renders from — not the
     * admin view.
     */
    public function test_an_archived_option_is_not_offered_to_customers(): void
    {
        $option = $this->option('Hidden From Customers');
        $item = $this->item('HFC Item');
        $item->options()->attach($option->id);

        $this->assertSame(1, $item->fresh()->options()->count(), 'setup: customers should see it first');

        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-options/' . $option->id);

        $this->assertSame(
            0,
            $item->fresh()->options()->count(),
            'an archived add-on must not be offerable on the item any more'
        );

        $html = $this->get('/customer/item-details/' . $item->id)->getContent();
        $this->assertStringNotContainsString('Hidden From Customers', $html);
    }

    // ══════════ history must survive ══════════

    /**
     * A customer's old receipt must never lose what they actually ordered.
     * Asserted for the order's real owner, so the ownership guard does not
     * mask the result.
     */
    public function test_a_past_order_still_shows_the_option_name_and_price_while_archived(): void
    {
        $customer = User::where('role', 'customer')->orderBy('id')->firstOrFail();

        $option = $this->option('History Survives');
        $item = $this->item('HS Item');
        $item->options()->attach($option->id);

        $order = $this->sell($option, $item);
        $order->update(['user_id' => $customer->id]);

        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-options/' . $option->id);

        $this->assertTrue(
            MenuOption::withArchived()->find($option->id)->isArchived(),
            'setup: the option should now be archived'
        );

        $response = $this->actingAs($customer, 'customer')->get('/customer/receipt/' . $order->id);

        $response->assertOk();
        $response->assertSee('History Survives');

        // The snapshot columns the receipt ultimately answers for.
        $line = OrderItem::where('order_id', $order->id)->firstOrFail();
        $recorded = $line->options()->first();

        $this->assertSame('History Survives', $recorded->pivot->option_name);
        $this->assertSame('5.00', (string) $recorded->pivot->additional_price);
    }

    // ══════════ the unassigned case — pinning existing behaviour ══════════

    /**
     * An option nothing points at — no assignments, no order history — is
     * still a real delete. Pinned so the lifecycle's hard-delete branch
     * cannot drift silently now that assignments count as a reference.
     */
    public function test_an_option_with_no_assignments_and_no_orders_is_still_hard_deleted(): void
    {
        $option = $this->option('Truly Unused');

        $this->actingAs($this->admin(), 'admin')
            ->delete('/admin/menu-options/' . $option->id);

        $this->assertNull(
            MenuOption::withArchived()->find($option->id),
            'an add-on nothing references at all should still be permanently deleted'
        );
    }

    /**
     * The gap this pass closed: assigned but never ordered used to be hard
     * deleted, taking its assignments with it via ON DELETE CASCADE.
     */
    public function test_an_assigned_but_never_ordered_option_is_archived_not_destroyed(): void
    {
        $option = $this->option('Assigned Never Ordered');
        $item = $this->item('ANO Item');
        $option->menuItems()->attach($item->id);

        $this->assertSame(
            0,
            DB::table('order_item_options')->where('menu_option_id', $option->id)->count(),
            'setup: this option must have no order history, which is what used to doom it'
        );

        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-options/' . $option->id);

        $survivor = MenuOption::withArchived()->find($option->id);

        $this->assertNotNull(
            $survivor,
            'an assigned add-on must be archived, not destroyed, even with no order history'
        );
        $this->assertTrue($survivor->isArchived());
        $this->assertSame([$item->id], $this->links($option));
    }

    // ══════════ isolation ══════════

    public function test_archiving_one_option_does_not_touch_another_options_assignments(): void
    {
        $archived = $this->option('Isolation Target');
        $untouched = $this->option('Isolation Bystander');
        $shared = $this->item('Isolation Item');

        $archived->menuItems()->attach($shared->id);
        $untouched->menuItems()->attach($shared->id);

        $bystanderBefore = $this->links($untouched);

        $this->actingAs($this->admin(), 'admin')->delete('/admin/menu-options/' . $archived->id);

        $this->assertSame(
            $bystanderBefore,
            $this->links($untouched),
            "another option's assignments must be completely unaffected"
        );
        $this->assertFalse(
            MenuOption::withArchived()->find($untouched->id)->isArchived(),
            'the bystander option must not have been archived'
        );
    }
}
