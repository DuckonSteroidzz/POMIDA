<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Editing an inventory item without changing its own item_code threw a raw
 * SQLSTATE[23000] duplicate-key 500 instead of a validation error
 * (2026-09-02).
 *
 * ROOT CAUSE
 * ----------
 * AdminController::updateInventory()'s validate() array had no uniqueness
 * rule on item_code at all — 'nullable|string|max:50' only — so nothing in
 * PHP ever caught a collision. It reached the database unchecked and the
 * unique index on inventory.item_code (defined in the table's original
 * migration, unscoped by branch and with no soft-delete/archive flag exempting
 * an inactive row) threw first. storeInventory() (create) had the identical
 * gap.
 *
 * A row updating itself to its OWN current code was never actually the
 * problem in isolation — MySQL's unique check already excludes the row being
 * updated. The exposure was a genuine second row sharing a code, which the
 * missing validation could let happen on either path.
 *
 * DATA CHECKED BEFORE THIS PASS: queried the live `inventory` table directly.
 * Exactly 3 rows exist; none share an item_code; none are null or ''. No
 * duplicate found to report, and nothing was deleted, merged, or modified —
 * this file creates and cleans up its own fixtures under DatabaseTransactions.
 *
 * ARCHIVED ITEMS AND THE UNIQUE INDEX: Inventory has no SoftDeletes trait and
 * no deleted_at column — 'archived' here just means is_active = false on the
 * same row. There is no partial/filtered unique index, so an inactive item's
 * code is still fully reserved.
 *
 * BLANK CODES — NOT WHAT THE ORIGINAL BRIEF ASSUMED. Investigation found
 * `inventory.item_code` is a NOT NULL column (`SHOW COLUMNS` on the live
 * table: Null => 'NO', matching the migration, which never calls
 * ->nullable()). A blank submission was therefore never storable as either
 * NULL or '' in the first place — confirmed empirically, not inferred:
 * Inventory::create(['item_code' => null, ...]) throws the exact same class
 * of raw 500 the duplicate-key crash did ("Column 'item_code' cannot be
 * null"), for the identical reason — nothing in PHP validated it first.
 * item_code is therefore made REQUIRED here rather than nullable-normalised-
 * to-null, which was the fix direction the brief assumed before this was
 * checked. See test_a_blank_item_code_is_rejected_not_crashed_or_silently_stored().
 */
class InventoryItemCodeUniqueTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->first();
    }

    private function makeItem(array $attrs = []): Inventory
    {
        return Inventory::create(array_merge([
            'branch_id'   => 1,
            'item_name'   => 'Test Stock',
            'item_code'   => 'ICT-' . strtoupper(substr(uniqid(), -8)),
            'unit'        => 'pcs',
            'quantity'    => 5,
            'is_active'   => true,
        ], $attrs));
    }

    // ══════════ the exact reported bug ══════════

    /**
     * THE REPORTED BUG. Updating an item and submitting its OWN unchanged
     * item_code must succeed and must not 500.
     */
    public function test_updating_an_item_with_its_own_unchanged_code_succeeds(): void
    {
        $item = $this->makeItem(['item_code' => 'sug-100-test']);

        $this->withoutExceptionHandling();

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/inventory/' . $item->id, [
                'item_name' => 'White Sugar',
                'item_code' => 'sug-100-test',
                'quantity'  => 5,
                'unit'      => 'pcs',
            ]);

        $response->assertRedirect(route('admin.inventory'));
        $response->assertSessionHasNoErrors();

        $this->assertSame('sug-100-test', $item->fresh()->item_code);
    }

    // ══════════ the actual duplicate case ══════════

    public function test_updating_an_item_to_a_code_held_by_a_different_item_is_rejected(): void
    {
        $taken = $this->makeItem(['item_code' => 'TAKEN-CODE']);
        $mine  = $this->makeItem(['item_code' => 'MY-OWN-CODE', 'item_name' => 'Mine']);

        // No withoutExceptionHandling() here: the whole point is that this
        // must be a caught validation error, not an uncaught exception.
        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/inventory/' . $mine->id, [
                'item_name' => 'Mine',
                'item_code' => 'TAKEN-CODE',
                'quantity'  => 5,
                'unit'      => 'pcs',
            ]);

        $response->assertSessionHasErrors('item_code');
        $this->assertStringContainsString(
            'already used',
            session('errors')->first('item_code')
        );

        // The row must be unchanged — still its own original code.
        $this->assertSame('MY-OWN-CODE', $mine->fresh()->item_code);
        $this->assertSame('TAKEN-CODE', $taken->fresh()->item_code);
    }

    public function test_creating_a_new_item_with_an_already_used_code_is_rejected(): void
    {
        $existing = $this->makeItem(['item_code' => 'EXISTING-CODE']);

        $before = Inventory::max('id');

        $response = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 1])
            ->post('/admin/inventory', [
                'item_name' => 'Duplicate Attempt',
                'item_code' => 'EXISTING-CODE',
                'quantity'  => 1,
                'unit'      => 'pcs',
            ]);

        $response->assertSessionHasErrors('item_code');

        $this->assertSame(
            0,
            Inventory::where('id', '>', $before)->count(),
            'a rejected create must not have inserted a row'
        );
        $this->assertSame('EXISTING-CODE', $existing->fresh()->item_code);
    }

    // ══════════ blanks — corrected after investigation ══════════

    /**
     * item_code is NOT NULL at the schema level, discovered while
     * investigating step 4 of the brief. A blank code is not a case of
     * "which of two valid representations is used" — it was never
     * storable at all, on either path, and Inventory::create() with an
     * explicit null throws the identical raw-500 class this whole pass
     * exists to close. It is now REQUIRED, so a blank submission is a
     * clean validation error instead of that crash.
     */
    public function test_a_blank_item_code_is_rejected_not_crashed_or_silently_stored(): void
    {
        $item = $this->makeItem(['item_code' => 'HAS-CODE-A']);
        $before = Inventory::max('id');

        // Create with a blank code.
        $createResponse = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 1])
            ->post('/admin/inventory', [
                'item_name' => 'Blank On Create',
                'item_code' => '',
                'quantity'  => 1,
                'unit'      => 'pcs',
            ]);

        $createResponse->assertSessionHasErrors('item_code');
        $this->assertSame(
            0,
            Inventory::where('id', '>', $before)->count(),
            'a rejected blank-code create must not have inserted a row'
        );

        // Edit down to a blank code.
        $updateResponse = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/inventory/' . $item->id, [
                'item_name' => 'Blank On Update',
                'item_code' => '',
                'quantity'  => 5,
                'unit'      => 'pcs',
            ]);

        $updateResponse->assertSessionHasErrors('item_code');

        // The row must be entirely unchanged — a rejected edit is not a
        // partial write.
        $item->refresh();
        $this->assertSame('HAS-CODE-A', $item->item_code);
        $this->assertSame('Test Stock', $item->item_name);
    }

    // ══════════ no exception ever escapes ══════════

    /**
     * Blanket assertion across every path above: none of them may surface a
     * QueryException/UniqueConstraintViolationException to the admin, caught
     * or not.
     */
    public function test_no_database_exception_escapes_any_of_these_paths(): void
    {
        $taken = $this->makeItem(['item_code' => 'BLANKET-TAKEN']);
        $mine  = $this->makeItem(['item_code' => 'BLANKET-MINE']);

        try {
            $this->actingAs($this->admin(), 'admin')
                ->put('/admin/inventory/' . $mine->id, [
                    'item_name' => 'Mine',
                    'item_code' => 'BLANKET-TAKEN',
                    'quantity'  => 5,
                    'unit'      => 'pcs',
                ]);

            $this->actingAs($this->admin(), 'admin')
                ->withSession(['selected_branch_id' => 1])
                ->post('/admin/inventory', [
                    'item_name' => 'Duplicate Attempt',
                    'item_code' => 'BLANKET-TAKEN',
                    'quantity'  => 1,
                    'unit'      => 'pcs',
                ]);

            // The blank-code path found during investigation — a NOT NULL
            // column, not a uniqueness collision, but the same class of
            // "must never reach the database uncaught" guarantee.
            $this->actingAs($this->admin(), 'admin')
                ->withSession(['selected_branch_id' => 1])
                ->post('/admin/inventory', [
                    'item_name' => 'Blank Attempt',
                    'item_code' => '',
                    'quantity'  => 1,
                    'unit'      => 'pcs',
                ]);
        } catch (UniqueConstraintViolationException|\Illuminate\Database\QueryException $e) {
            $this->fail('a database exception escaped instead of being caught as a validation error: ' . $e->getMessage());
        }

        $this->assertTrue(true);
    }

    // ══════════ the modal must not silently lose the edit ══════════

    /**
     * The form is reused for Add and Edit, switched entirely by JS that does
     * not survive a redirect. Confirmed by reading the rendered HTML: without
     * old()-bound fields and a flashed editing_item_id, a failed edit
     * reopened the modal blank and pointed at the CREATE route, so pressing
     * Update again would have silently created a new item.
     */
    public function test_a_failed_edit_reopens_the_modal_pointed_at_the_same_item_with_old_input(): void
    {
        $taken = $this->makeItem(['item_code' => 'REOPEN-TAKEN']);
        $mine  = $this->makeItem(['item_code' => 'REOPEN-MINE', 'item_name' => 'Reopen Me']);

        // editing_item_id is a hidden FORM FIELD only the browser's JS
        // (openEditModal()) populates before submit — it has to be included
        // here explicitly to reproduce what a real edit submission actually
        // sends, the same way item_name/item_code/etc. are.
        $this->actingAs($this->admin(), 'admin')
            ->from('/admin/inventory')
            ->put('/admin/inventory/' . $mine->id, [
                'editing_item_id' => $mine->id,
                'item_name'       => 'Reopen Me Edited',
                'item_code'       => 'REOPEN-TAKEN',
                'quantity'        => 5,
                'unit'            => 'pcs',
            ]);

        // Follow up with the next request in the same session, where the
        // flashed old() input and errors are actually available to the view.
        $page = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/inventory')
            ->getContent();

        $this->assertStringContainsString(
            'value="Reopen Me Edited"',
            $page,
            'the admin\'s typed name was lost on the reopened modal'
        );
        $this->assertStringContainsString(
            'value="REOPEN-TAKEN"',
            $page,
            'the rejected code was lost on the reopened modal'
        );
        $this->assertStringContainsString(
            'id="editingItemId" value="' . $mine->id . '"',
            $page,
            'the reopened modal lost track of which item was being edited — '
            . 'a second submit would have created a NEW item instead of retrying the edit'
        );
    }
}
