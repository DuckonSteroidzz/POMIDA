<?php

namespace Tests\Feature;

use App\Exceptions\MenuItemSizeUnavailableException;
use App\Models\MenuItemSize;
use App\Models\MenuItemSizeIngredient;
use App\Services\MenuItemSizes;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\MenuItemSizeFixtures;
use Tests\TestCase;

/**
 * Menu Item Sizes, Phase 1 — schema, admin size management, lifecycle and the
 * "starting from" price.
 *
 * A sized item has exactly two sizes, Regular and Large. The database enforces
 * the names/positions (exact-byte name + CHECK on (name, display_order)) and
 * one of each per item (UNIQUE), the write path creates both together, and
 * menu_items.price becomes the derived lowest-LIVE-size price that is never
 * read back as a size's price.
 *
 * Every fixture is created here (see MenuItemSizeFixtures) inside
 * DatabaseTransactions against pomida_db_testing.
 */
class MenuItemSizesSchemaAndAdminTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    private function insertRawSize(int $menuItemId, string $name, int $displayOrder, float $price = 50): void
    {
        DB::table('menu_item_sizes')->insert([
            'menu_item_id' => $menuItemId, 'name' => $name, 'price' => $price,
            'display_order' => $displayOrder, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertInsertRefused(callable $insert, string $why): void
    {
        try {
            $insert();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The database accepted a row it must refuse: ' . $why);
    }

    // ══════════ 1-3. The two fixed definitions ══════════

    public function test_enabling_sizes_creates_exactly_regular_and_large_in_fixed_order(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);

        $this->enableSizes($item, 100, 150);

        $rows = DB::table('menu_item_sizes')->where('menu_item_id', $item->id)->orderBy('display_order')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['Regular', 'Large'], $rows->pluck('name')->all());
        $this->assertSame([1, 2], $rows->pluck('display_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['100.00', '150.00'], $rows->pluck('price')->map(fn ($v) => (string) $v)->all());
        $this->assertSame([1, 1], $rows->pluck('is_active')->map(fn ($v) => (int) $v)->all(), 'both sizes start active');
        $this->assertSame([MenuItemSize::REGULAR => 1, MenuItemSize::LARGE => 2], MenuItemSize::DEFINITIONS);
    }

    public function test_the_database_refuses_a_custom_or_miscased_or_misplaced_size(): void
    {
        $item = $this->sizeItem($this->sizeBranch('A')->id);

        foreach (['Medium' => 1, 'Small' => 1, 'regular' => 1, 'LARGE' => 2, 'Regular ' => 1, ' Large' => 2, '' => 1] as $name => $order) {
            $this->assertInsertRefused(fn () => $this->insertRawSize($item->id, $name, $order), 'size name ' . json_encode($name));
        }

        // Right names, wrong positions — ordering cannot be edited into a ranking.
        $this->assertInsertRefused(fn () => $this->insertRawSize($item->id, 'Regular', 2), 'Regular in position 2');
        $this->assertInsertRefused(fn () => $this->insertRawSize($item->id, 'Large', 1), 'Large in position 1');
        $this->assertInsertRefused(fn () => $this->insertRawSize($item->id, 'Large', 3), 'Large in position 3');

        $this->assertSame(0, DB::table('menu_item_sizes')->where('menu_item_id', $item->id)->count());

        // Control: the two real definitions ARE accepted, so the refusals above
        // are the constraint, not a broken insert.
        $this->insertRawSize($item->id, 'Regular', 1);
        $this->insertRawSize($item->id, 'Large', 2);
        $this->assertSame(2, DB::table('menu_item_sizes')->where('menu_item_id', $item->id)->count());
    }

    public function test_a_third_size_cannot_be_created_on_a_sized_item(): void
    {
        $item = $this->sizeItem($this->sizeBranch('A')->id);
        $this->enableSizes($item);

        // The only names the CHECK allows are both taken — every third row fails.
        $this->assertInsertRefused(fn () => $this->insertRawSize($item->id, 'Medium', 1), 'a custom third size');
        $this->assertInsertRefused(fn () => $this->insertRawSize($item->id, 'Regular', 1), 'a third row reusing Regular');
        $this->assertInsertRefused(fn () => $this->insertRawSize($item->id, 'Large', 2), 'a third row reusing Large');

        // And no endpoint takes a name at all: posting one to set-up is ignored
        // and set-up itself is refused on an already sized item.
        $this->actingAs($this->sizeOwner(), 'admin')
            ->post(route('admin.menu-items.sizes.enable', $item->id), [
                'name' => 'Medium', 'regular_price' => 10, 'large_price' => 20,
            ])
            ->assertRedirect(route('admin.menu-items'))
            ->assertSessionHas('error');

        $this->assertSame(2, DB::table('menu_item_sizes')->where('menu_item_id', $item->id)->count());
        $this->assertSame(0, DB::table('menu_item_sizes')->where('menu_item_id', $item->id)->where('name', 'Medium')->count());
    }

    public function test_a_duplicate_regular_or_large_is_refused_by_the_database_and_the_service(): void
    {
        $item = $this->sizeItem($this->sizeBranch('A')->id);
        $this->insertRawSize($item->id, 'Regular', 1);

        $this->assertInsertRefused(fn () => $this->insertRawSize($item->id, 'Regular', 1, 60), 'a second Regular');

        $this->insertRawSize($item->id, 'Large', 2);
        $this->assertInsertRefused(fn () => $this->insertRawSize($item->id, 'Large', 2, 70), 'a second Large');

        // The service refuses a second set-up, archived rows included.
        $sized = $this->sizeItem($this->sizeBranch('B')->id);
        [$regular] = $this->enableSizes($sized);
        $regular->archive();

        try {
            app(MenuItemSizes::class)->enable($sized, 1, 2);
            $this->fail('A second set-up must be refused while size rows exist (even archived ones).');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already has Regular and Large sizes', $e->getMessage());
        }

        $this->assertSame(2, MenuItemSize::withArchived()->where('menu_item_id', $sized->id)->count());
    }

    // ══════════ 4-5. Price validation and the active switch ══════════

    public function test_size_price_is_validated_server_side_on_update(): void
    {
        $item = $this->sizeItem($this->sizeBranch('A')->id);
        [$regular] = $this->enableSizes($item, 100, 150);
        $owner = $this->sizeOwner();
        $url = route('admin.menu-items.sizes.update', [$item->id, $regular->id]);

        foreach ([0, -5, '0.00', 'abc', '', null, '100000000', ['1']] as $bad) {
            $this->actingAs($owner, 'admin')
                ->put($url, ['price' => $bad, 'is_active' => 1])
                ->assertRedirect(route('admin.menu-items'))
                ->assertSessionHas('error')
                ->assertSessionHas('menu_item_editing', $item->id);

            $this->assertSame('100.00', (string) $this->rawSize($regular->id)->price, 'refused price ' . json_encode($bad) . ' must change nothing');
        }

        $this->actingAs($owner, 'admin')
            ->put($url, ['price' => '125.50', 'is_active' => 1])
            ->assertRedirect(route('admin.menu-items'))
            ->assertSessionHas('success');

        $this->assertSame('125.50', (string) $this->rawSize($regular->id)->price);
    }

    public function test_size_prices_are_validated_server_side_on_set_up(): void
    {
        $item = $this->sizeItem($this->sizeBranch('A')->id);
        $owner = $this->sizeOwner();

        foreach ([
            ['regular_price' => 0, 'large_price' => 150],
            ['regular_price' => 100, 'large_price' => -1],
            ['regular_price' => 'x', 'large_price' => 150],
            ['large_price' => 150],
            ['regular_price' => 100],
        ] as $payload) {
            $this->actingAs($owner, 'admin')
                ->post(route('admin.menu-items.sizes.enable', $item->id), $payload)
                ->assertRedirect(route('admin.menu-items'))
                ->assertSessionHas('error');
        }

        $this->assertSame(0, DB::table('menu_item_sizes')->where('menu_item_id', $item->id)->count(), 'no refused set-up may leave a half-sized item');
        $this->assertSame('90.00', $this->rawItemPrice($item->id), 'an unsized item keeps its own price');
    }

    public function test_the_active_switch_turns_a_size_off_and_on(): void
    {
        $item = $this->sizeItem($this->sizeBranch('A')->id);
        [, $large] = $this->enableSizes($item);
        $owner = $this->sizeOwner();
        $url = route('admin.menu-items.sizes.update', [$item->id, $large->id]);

        $this->actingAs($owner, 'admin')->put($url, ['price' => 150, 'is_active' => 0])->assertSessionHas('success');
        $this->assertSame(0, (int) $this->rawSize($large->id)->is_active);

        $this->actingAs($owner, 'admin')->put($url, ['price' => 150, 'is_active' => 1])->assertSessionHas('success');
        $this->assertSame(1, (int) $this->rawSize($large->id)->is_active);

        // The flag is required — a request that omits it changes nothing.
        $this->actingAs($owner, 'admin')->put($url, ['price' => 999])->assertSessionHas('error');
        $this->assertSame('150.00', (string) $this->rawSize($large->id)->price);
    }

    // ══════════ 6-8. Archive / restore ══════════

    public function test_archiving_a_size_keeps_its_row_and_recipe_and_hides_it_from_live_sizes(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular, $large] = $this->enableSizes($item);
        $line = $this->sizeRecipeLine($large, $this->sizeInventory($branch->id), 20);

        $this->actingAs($this->sizeOwner(), 'admin')
            ->delete(route('admin.menu-items.sizes.archive', [$item->id, $large->id]))
            ->assertRedirect(route('admin.menu-items'))
            ->assertSessionHas('success');

        $this->assertNotNull($this->rawSize($large->id)->archived_at);
        $this->assertSame([$regular->id], $item->fresh()->sizes->pluck('id')->all(), 'live sizes hide the archived one');
        $this->assertSame([$regular->id, $large->id], $item->fresh()->allSizes->pluck('id')->all());
        $this->assertTrue(MenuItemSizeIngredient::whereKey($line->id)->exists(), 'archiving must not delete the recipe line');
    }

    public function test_restoring_a_size_returns_the_same_row_with_the_same_recipe_links(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [, $large] = $this->enableSizes($item);
        $lineIds = collect([
            $this->sizeRecipeLine($large, $this->sizeInventory($branch->id), 20),
            $this->sizeRecipeLine($large, $this->sizeInventory($branch->id), 5.5),
        ])->pluck('id')->sort()->values()->all();

        $owner = $this->sizeOwner();
        $this->actingAs($owner, 'admin')->delete(route('admin.menu-items.sizes.archive', [$item->id, $large->id]));
        $this->assertNotNull($this->rawSize($large->id)->archived_at);

        $this->actingAs($owner, 'admin')
            ->post(route('admin.menu-items.sizes.restore', [$item->id, $large->id]))
            ->assertRedirect(route('admin.menu-items'))
            ->assertSessionHas('success');

        $this->assertNull($this->rawSize($large->id)->archived_at);
        $this->assertSame(2, DB::table('menu_item_sizes')->where('menu_item_id', $item->id)->count(), 'restore recreates nothing');
        $this->assertSame(
            $lineIds,
            DB::table('menu_item_size_ingredients')->where('menu_item_size_id', $large->id)->orderBy('id')->pluck('id')->all(),
            'the exact same recipe rows come back — none recreated'
        );
    }

    public function test_an_archived_size_is_read_only_until_restored(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [, $large] = $this->enableSizes($item);
        $line = $this->sizeRecipeLine($large, $this->sizeInventory($branch->id), 20);
        $large->archive();
        $owner = $this->sizeOwner();

        $this->actingAs($owner, 'admin')->put(route('admin.menu-items.sizes.update', [$item->id, $large->id]), ['price' => 1, 'is_active' => 1])->assertNotFound();
        $this->actingAs($owner, 'admin')->delete(route('admin.menu-items.sizes.archive', [$item->id, $large->id]))->assertNotFound();
        $this->actingAs($owner, 'admin')->postJson(route('admin.menu-items.sizes.ingredients.add', [$item->id, $large->id]), [
            'inventory_id' => $this->sizeInventory($branch->id)->id, 'quantity_used' => 1,
        ])->assertNotFound();
        $this->actingAs($owner, 'admin')->deleteJson(route('admin.menu-items.sizes.ingredients.delete', [$item->id, $large->id, $line->id]))->assertNotFound();

        $this->assertSame('150.00', (string) $this->rawSize($large->id)->price);
        $this->assertTrue(MenuItemSizeIngredient::whereKey($line->id)->exists());

        // Restore of a size that is NOT archived is a 404 too — nothing to undo.
        [$regular] = [$item->fresh()->sizes->first()];
        $this->actingAs($owner, 'admin')->post(route('admin.menu-items.sizes.restore', [$item->id, $regular->id]))->assertNotFound();
    }

    public function test_only_the_owner_can_restore_an_archived_size(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [, $large] = $this->enableSizes($item);
        $large->archive();

        // Same-branch supervisor: may archive (manager tier), may not restore.
        $this->actingAs($this->sizeSupervisor($branch->id), 'admin')
            ->post(route('admin.menu-items.sizes.restore', [$item->id, $large->id]))
            ->assertRedirect(route('admin.home'))
            ->assertSessionHas('error', "You don't have permission to access that.");

        $this->assertNotNull($this->rawSize($large->id)->archived_at);
    }

    // ══════════ 9-10. Cascade from the parent item ══════════

    public function test_deleting_the_parent_menu_item_cascades_its_sizes_and_their_recipes(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular, $large] = $this->enableSizes($item);
        $this->sizeRecipeLine($regular, $this->sizeInventory($branch->id), 10);
        $this->sizeRecipeLine($large, $this->sizeInventory($branch->id), 20);
        $large->archive(); // an archived size goes too

        $sizeIds = [$regular->id, $large->id];
        $this->assertSame(2, DB::table('menu_item_size_ingredients')->whereIn('menu_item_size_id', $sizeIds)->count());

        DB::table('menu_items')->where('id', $item->id)->delete();

        $this->assertSame(0, DB::table('menu_item_sizes')->whereIn('id', $sizeIds)->count(), 'size rows must cascade');
        $this->assertSame(0, DB::table('menu_item_size_ingredients')->whereIn('menu_item_size_id', $sizeIds)->count(), 'size recipe rows must cascade');
    }

    public function test_the_owners_permanent_delete_removes_sizes_and_size_recipes_with_the_item(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular, $large] = $this->enableSizes($item);
        $this->sizeRecipeLine($regular, $this->sizeInventory($branch->id), 10);
        $owner = $this->sizeOwner();

        $this->actingAs($owner, 'admin')->delete(route('admin.menu-items.delete', $item->id));
        $this->actingAs($owner, 'admin')
            ->delete(route('admin.archived.menu-item.force-delete', $item->id))
            ->assertRedirect(route('admin.archived'));

        $this->assertFalse(DB::table('menu_items')->where('id', $item->id)->exists());
        $this->assertSame(0, DB::table('menu_item_sizes')->whereIn('id', [$regular->id, $large->id])->count());
        $this->assertSame(0, DB::table('menu_item_size_ingredients')->where('menu_item_size_id', $regular->id)->count());
    }

    public function test_permanently_deleting_an_inventory_row_removes_its_size_recipe_lines_and_the_size_becomes_no_recipe(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular] = $this->enableSizes($item);
        $flour = $this->sizeInventory($branch->id);
        $line = $this->sizeRecipeLine($regular, $flour, 10);

        DB::table('inventory')->where('id', $flour->id)->delete();

        $this->assertFalse(MenuItemSizeIngredient::whereKey($line->id)->exists(), 'no orphan recipe line may survive its inventory row');
        $this->assertTrue($item->fresh()->isMissingRecipe($regular->fresh()), 'losing its last line makes the size "No Recipe Set" — never an empty valid recipe');
    }

    // ══════════ 11-12. menu_items.price = starting-from price ══════════

    public function test_starting_price_is_the_lowest_active_size_price_whichever_size_it_is(): void
    {
        $branch = $this->sizeBranch('A');

        $a = $this->sizeItem($branch->id, 'Test Coffee', 90);
        $this->enableSizes($a, 100, 150);
        $this->assertSame('100.00', $this->rawItemPrice($a->id), 'Regular ₱100 / Large ₱150 => ₱100');

        $b = $this->sizeItem($branch->id, 'Test Coffee', 90);
        $this->enableSizes($b, 150, 100);
        $this->assertSame('100.00', $this->rawItemPrice($b->id), 'Regular ₱150 / Large ₱100 => ₱100');
    }

    public function test_starting_price_follows_price_active_and_archive_changes(): void
    {
        $item = $this->sizeItem($this->sizeBranch('A')->id);
        [$regular, $large] = $this->enableSizes($item, 100, 150);
        $owner = $this->sizeOwner();
        $update = fn ($size, $price, $active) => $this->actingAs($owner, 'admin')
            ->put(route('admin.menu-items.sizes.update', [$item->id, $size->id]), ['price' => $price, 'is_active' => $active])
            ->assertSessionHas('success');

        $update($regular, 120, 1);
        $this->assertSame('120.00', $this->rawItemPrice($item->id), 'price change recalculates');

        $update($large, 110, 1);
        $this->assertSame('110.00', $this->rawItemPrice($item->id), 'Large is now the lowest');

        $update($large, 110, 0);
        $this->assertSame('120.00', $this->rawItemPrice($item->id), 'an inactive size is never the starting price');

        $update($large, 110, 1);
        $this->assertSame('110.00', $this->rawItemPrice($item->id), 're-activating recalculates');

        $this->actingAs($owner, 'admin')->delete(route('admin.menu-items.sizes.archive', [$item->id, $large->id]));
        $this->assertSame('120.00', $this->rawItemPrice($item->id), 'an archived size is never the starting price');

        $this->actingAs($owner, 'admin')->post(route('admin.menu-items.sizes.restore', [$item->id, $large->id]));
        $this->assertSame('110.00', $this->rawItemPrice($item->id), 'restoring recalculates');
    }

    public function test_with_no_live_size_the_last_starting_price_is_kept_and_no_size_is_orderable(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular, $large] = $this->enableSizes($item, 100, 150);
        $inv = $this->sizeInventory($branch->id);
        $this->sizeRecipeLine($regular, $inv, 1);
        $this->sizeRecipeLine($large, $inv, 2);
        $this->baseRecipeLine($item, $inv, 1);

        $regular->is_active = false;
        $regular->save();
        $this->assertSame('150.00', $this->rawItemPrice($item->id), 'Regular off: Large is the only live size');

        $large->archive();

        $item = $item->fresh();
        $this->assertSame('150.00', $this->rawItemPrice($item->id), 'NOT NULL column: the last starting price is retained, not cleared or invented');
        $this->assertTrue($item->hasSizes(), 'still a sized item — it does not revert to unsized');
        foreach ([$regular, $large] as $size) {
            $this->assertFalse($item->isOrderable(1, $size->fresh()));
            $this->assertFalse($item->hasIngredientStock(1, null, $size->id));
        }
    }

    public function test_menu_items_price_is_never_used_as_a_size_price(): void
    {
        $item = $this->sizeItem($this->sizeBranch('A')->id);
        [$regular, $large] = $this->enableSizes($item, 100, 150);

        // Force the parent's column to a figure no size has.
        DB::table('menu_items')->where('id', $item->id)->update(['price' => 999]);
        $item = $item->fresh();

        $this->assertSame('100.00', $item->priceForSize($regular->id));
        $this->assertSame('150.00', $item->priceForSize($large));

        // A size that cannot be sold has NO price — it is refused, never
        // answered with the parent's figure.
        $large->is_active = false;
        $large->save();
        try {
            $item->fresh()->priceForSize($large->fresh());
            $this->fail('An inactive size must not be priced at all.');
        } catch (MenuItemSizeUnavailableException $e) {
            $this->assertSame(MenuItemSizeUnavailableException::INACTIVE, $e->reason());
        }
    }

    public function test_the_item_edit_form_cannot_type_over_a_sized_items_derived_price(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular, $large] = $this->enableSizes($item, 100, 150);
        $owner = $this->sizeOwner();

        $this->actingAs($owner, 'admin')
            ->withSession(['selected_branch_id' => $branch->id])
            ->put(route('admin.menu-items.update', $item->id), [
                'category_id' => $item->category_id, 'name' => 'Test Coffee renamed', 'price' => 5,
            ])
            ->assertRedirect(route('admin.menu-items'));

        $this->assertSame('100.00', $this->rawItemPrice($item->id), 'posted price ignored; re-derived from the sizes');
        $this->assertSame('Test Coffee renamed', $item->fresh()->name, 'the rest of the edit still saved');
        $this->assertSame('100.00', (string) $this->rawSize($regular->id)->price);
        $this->assertSame('150.00', (string) $this->rawSize($large->id)->price);

        // Regression control: an UNSIZED item still takes its typed price.
        $plain = $this->sizeItem($branch->id, 'Plain Tea', 60);
        $this->actingAs($owner, 'admin')
            ->withSession(['selected_branch_id' => $branch->id])
            ->put(route('admin.menu-items.update', $plain->id), [
                'category_id' => $plain->category_id, 'name' => 'Plain Tea', 'price' => 75,
            ]);
        $this->assertSame('75.00', $this->rawItemPrice($plain->id));
    }

    public function test_an_unsized_items_price_is_never_touched_by_the_size_sync(): void
    {
        $item = $this->sizeItem($this->sizeBranch('A')->id, 'Plain', 42.5);

        $item->syncStartingPriceFromSizes();

        $this->assertSame('42.50', $this->rawItemPrice($item->id));
        $this->assertFalse($item->hasSizes());
    }
}
