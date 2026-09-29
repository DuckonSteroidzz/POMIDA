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
 * Menu item lifecycle, Phase 1 (2026-09-24):
 *
 *   ACTIVE --Delete--> ARCHIVED --Restore--> ACTIVE
 *                          |
 *                          +--Permanent Delete (owner; not while on an open order)--> gone
 *                             (Phase 2: a sold item's past order lines are KEPT, link set NULL)
 *
 * WHAT WAS WRONG, proven by a probe before any change
 * ---------------------------------------------------
 *   1. DELETE /admin/menu-items/{id} hard-deleted any item nothing had ordered,
 *      cascading its add-on assignments and recipe rows with no warning.
 *   2. That endpoint resolves archived rows too, so an ARCHIVED item whose
 *      order lines were gone was permanently deleted by it — including by a
 *      same-branch supervisor. A hidden permanent-delete door with no UI.
 *   3. The Archived page and its "Archived (N)" count read every branch, so a
 *      Branch 1 supervisor or staff member saw Branch 2's and shared items.
 *   4. The image was unlinked BEFORE the row delete: a delete that then failed
 *      kept the row, lost the image, and said "Nothing was changed".
 *
 * WHAT IS PINNED HERE
 * -------------------
 * Delete always archives (and never touches the image); the normal endpoint
 * refuses an archived item; the archive is branch-scoped by the same rule as
 * the live Menu Items list; Permanent Delete is owner-only, archived-only, and
 * refused while the item is on an open (pending/preparing/serving) order —
 * finished orders no longer block it (Phase 2, see
 * MenuItemSoldPermanentDeleteTest for the sold-item rules); the database delete
 * happens (and is verified) before the image is removed; order history reads
 * the same before and after.
 *
 * Every row a test acts on is created here, inside DatabaseTransactions.
 * Image files are real files under public/uploads/menu-items, prefixed
 * MIPD_TEST_, and removed in tearDown whatever the outcome.
 */
class MenuItemPermanentDeleteTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MIPD';

    /** @var string[] absolute paths this test created, cleaned in tearDown */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    // ══════════════════════ fixtures ══════════════════════

    private function account(string $role, ?int $branchId): User
    {
        return User::create([
            'name'      => self::PREFIX . ' ' . ucfirst($role),
            'email'     => strtolower(self::PREFIX) . '-' . $role . '-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => $role,
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    private function owner(): User
    {
        return $this->account('admin', null);
    }

    private function supervisor(int $branchId = 1): User
    {
        return $this->account('supervisor', $branchId);
    }

    private function staff(int $branchId = 1): User
    {
        return $this->account('staff', $branchId);
    }

    private function item(string $name, ?int $branchId = 1, ?string $image = null): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => self::PREFIX . ' ' . $name,
            'price'         => 95,
            'is_available'  => true,
            'display_order' => 0,
            'image'         => $image,
        ]);
    }

    /**
     * Two add-on assignments — one of them to an ARCHIVED add-on, because the
     * CASCADE removes that pivot row too and the warning must count it — and
     * two recipe lines.
     */
    private function giveLinks(MenuItem $item): void
    {
        $live = MenuOption::create([
            'name' => self::PREFIX . ' live add-on ' . uniqid(), 'additional_price' => 10,
            'is_active' => true, 'display_order' => 0,
        ]);
        $archived = MenuOption::create([
            'name' => self::PREFIX . ' archived add-on ' . uniqid(), 'additional_price' => 12,
            'is_active' => true, 'display_order' => 0,
        ]);

        $item->options()->attach([$live->id, $archived->id]);
        $archived->archive();

        $inventory = DB::table('inventory')->where('branch_id', 1)->orderBy('id')->limit(2)->pluck('id');
        $this->assertCount(2, $inventory, 'setup: needs two Branch 1 inventory rows');

        foreach ($inventory as $inventoryId) {
            DB::table('menu_item_ingredients')->insert([
                'menu_item_id' => $item->id, 'inventory_id' => $inventoryId, 'quantity_used' => 1.5,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** [sorted pivot row ids, sorted recipe row ids] — the exact rows, not just counts. */
    private function linkIds(int $itemId): array
    {
        return [
            DB::table('menu_item_options')->where('menu_item_id', $itemId)->orderBy('id')->pluck('id')->all(),
            DB::table('menu_item_ingredients')->where('menu_item_id', $itemId)->orderBy('id')->pluck('id')->all(),
        ];
    }

    /** A real order line for $item, on an order in $status. */
    private function sell(MenuItem $item, string $status): Order
    {
        $order = Order::create([
            'order_number'    => self::PREFIX . '-' . strtoupper(substr(uniqid(), -8)),
            'branch_id'       => $item->branch_id ?? 1,
            'type'            => 'pick_up',
            'status'          => $status,
            'subtotal'        => 95,
            'discount_amount' => 0,
            'total'           => 95,
            'payment_method'  => 'cash',
            'payment_status'  => 'paid',
        ]);

        if ($status === 'completed') {
            DB::table('orders')->where('id', $order->id)->update(['completed_at' => now()]);
        }

        OrderItem::create([
            'order_id'     => $order->id,
            'menu_item_id' => $item->id,
            'item_name'    => $item->name,
            'item_price'   => 95,
            'quantity'     => 1,
            'subtotal'     => 95,
        ]);

        return $order;
    }

    /** A real file in the menu upload folder; returns the stored relative path. */
    private function imageFile(): string
    {
        $dir = public_path('uploads/menu-items');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $relative = 'uploads/menu-items/MIPD_TEST_' . uniqid() . '.png';
        file_put_contents(public_path($relative), 'not really a png');
        $this->createdFiles[] = public_path($relative);

        return $relative;
    }

    private function forceDelete(User $actor, int $id)
    {
        return $this->actingAs($actor, 'admin')
            ->delete(route('admin.archived.menu-item.force-delete', $id));
    }

    private function rawRowCount(int $id): int
    {
        return DB::table('menu_items')->where('id', $id)->count();
    }

    // ══════════ A. normal Delete always archives ══════════

    public function test_normal_delete_archives_an_unsold_item_and_keeps_its_links(): void
    {
        $item = $this->item('Unsold');
        $this->giveLinks($item);
        $before = $this->linkIds($item->id);

        $this->actingAs($this->owner(), 'admin')
            ->delete(route('admin.menu-items.delete', $item->id))
            ->assertRedirect(route('admin.menu-items'))
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('was archived', session('success'));
        $this->assertSame(1, $this->rawRowCount($item->id), 'an unsold item must no longer be hard-deleted');
        $this->assertNull(MenuItem::find($item->id), 'it must leave the live list');
        $this->assertTrue(MenuItem::withArchived()->find($item->id)->isArchived());
        $this->assertSame($before, $this->linkIds($item->id), 'archiving must not touch add-on or recipe rows');
    }

    public function test_normal_delete_archives_a_sold_item_and_keeps_its_links(): void
    {
        $item = $this->item('Sold');
        $this->giveLinks($item);
        $this->sell($item, 'completed');
        $before = $this->linkIds($item->id);

        $this->actingAs($this->owner(), 'admin')
            ->delete(route('admin.menu-items.delete', $item->id))
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('archived, not deleted', session('success'));
        $this->assertTrue(MenuItem::withArchived()->find($item->id)->isArchived());
        $this->assertSame($before, $this->linkIds($item->id));
    }

    public function test_normal_delete_never_touches_the_image_file(): void
    {
        $image = $this->imageFile();
        $item = $this->item('Pictured', 1, $image);

        $this->actingAs($this->owner(), 'admin')
            ->delete(route('admin.menu-items.delete', $item->id));

        $this->assertTrue(MenuItem::withArchived()->find($item->id)->isArchived());
        $this->assertFileExists(public_path($image), 'an archive-only delete must not remove the image');
        $this->assertSame($image, MenuItem::withArchived()->find($item->id)->image);
    }

    /** A manager's LIMITED delete now archives too — it can never destroy. */
    public function test_a_same_branch_supervisor_delete_archives_rather_than_destroys(): void
    {
        $item = $this->item('Supervisor unsold', 1);
        $this->giveLinks($item);
        $before = $this->linkIds($item->id);

        $this->actingAs($this->supervisor(1), 'admin')
            ->delete(route('admin.menu-items.delete', $item->id))
            ->assertRedirect(route('admin.menu-items'));

        $this->assertSame(1, $this->rawRowCount($item->id));
        $this->assertTrue(MenuItem::withArchived()->find($item->id)->isArchived());
        $this->assertSame($before, $this->linkIds($item->id));
    }

    /** The existing branch authorization is unchanged: nothing moves for a refused actor. */
    public function test_existing_delete_authorization_is_unchanged(): void
    {
        $foreign = $this->item('Branch 2 dish', 2);
        $shared = $this->item('Shared dish', null);
        $own = $this->item('Own dish', 1);

        $supervisor = $this->supervisor(1);

        $this->actingAs($supervisor, 'admin')->delete(route('admin.menu-items.delete', $foreign->id))
            ->assertRedirect(route('admin.menu-items'));
        $this->assertSame('You can only delete menu items belonging to your own branch.', session('error'));

        $this->actingAs($supervisor, 'admin')->delete(route('admin.menu-items.delete', $shared->id))
            ->assertRedirect(route('admin.menu-items'));
        $this->assertSame('Shared menu items can only be deleted by the owner.', session('error'));

        $this->actingAs($this->staff(1), 'admin')->delete(route('admin.menu-items.delete', $own->id))
            ->assertRedirect(route('admin.home'));

        foreach ([$foreign, $shared, $own] as $untouched) {
            $this->assertNotNull(MenuItem::find($untouched->id), 'a refused delete must leave the item live');
        }

        // And the owner reaches every one of them, shared included.
        $this->actingAs($this->owner(), 'admin')->delete(route('admin.menu-items.delete', $shared->id));
        $this->assertTrue(MenuItem::withArchived()->find($shared->id)->isArchived());
    }

    // ══════════ B. the hidden permanent-delete door is closed ══════════

    /**
     * THE HIDDEN DOOR. An archived item with no order lines, sent a plain
     * DELETE to the normal endpoint. This used to hard-delete it.
     */
    public function test_the_normal_endpoint_cannot_permanently_delete_an_archived_item(): void
    {
        $image = $this->imageFile();
        $item = $this->item('Archived hidden door', 1, $image);
        $this->giveLinks($item);
        $item->archive();
        $archivedAt = $item->fresh()->archived_at;
        $before = $this->linkIds($item->id);

        foreach ([$this->owner(), $this->supervisor(1)] as $actor) {
            $this->actingAs($actor, 'admin')
                ->delete(route('admin.menu-items.delete', $item->id))
                ->assertRedirect(route('admin.menu-items'));

            $this->assertStringContainsString('is already archived, so nothing was changed', session('error'));
            $this->assertNull(session('success'), $actor->role . ' must not be told anything succeeded');
        }

        $this->assertSame(1, $this->rawRowCount($item->id), 'the row must survive a direct DELETE');
        $this->assertSame($before, $this->linkIds($item->id), 'no option or recipe link may be lost');
        $this->assertEquals($archivedAt, MenuItem::withArchived()->find($item->id)->archived_at, 'the archive date must not move');
        $this->assertFileExists(public_path($image));
    }

    // ══════════ C. the Archived page is branch-scoped ══════════

    public function test_the_owner_sees_archived_items_from_every_branch(): void
    {
        foreach ([[1, 'Arch B1'], [2, 'Arch B2'], [null, 'Arch Shared']] as [$branch, $name]) {
            $this->item($name, $branch)->archive();
        }

        $this->actingAs($this->owner(), 'admin')->get(route('admin.archived'))
            ->assertOk()
            ->assertSee(self::PREFIX . ' Arch B1')
            ->assertSee(self::PREFIX . ' Arch B2')
            ->assertSee(self::PREFIX . ' Arch Shared');
    }

    /**
     * A branch-locked viewer gets their own branch only. Shared (NULL-branch)
     * items are left out, exactly as on their live Menu Items list — which
     * this test also checks, so the two rules are proven to agree rather than
     * assumed to.
     */
    public function test_branch_locked_viewers_see_only_their_own_branch_archive(): void
    {
        $this->item('Scope B1', 1)->archive();
        $this->item('Scope B2', 2)->archive();
        $this->item('Scope Shared', null)->archive();

        $liveShared = $this->item('Scope Live Shared', null);

        foreach (['supervisor' => $this->supervisor(1), 'staff' => $this->staff(1)] as $role => $viewer) {
            $html = $this->actingAs($viewer, 'admin')->get(route('admin.archived'))->assertOk()->getContent();

            $this->assertStringContainsString(self::PREFIX . ' Scope B1', $html, "$role must see their own branch");
            $this->assertStringNotContainsString(self::PREFIX . ' Scope B2', $html, "$role must not see another branch");
            $this->assertStringNotContainsString(self::PREFIX . ' Scope Shared', $html, "$role must not see shared archived items");

            $live = $this->actingAs($viewer, 'admin')->get(route('admin.menu-items'))->assertOk()->getContent();
            $this->assertStringNotContainsString(
                $liveShared->name,
                $live,
                "setup: the live list is the rule being matched — $role must not see shared items there either"
            );
        }
    }

    /** The "Archived (N)" link counts by the same rule as the page. */
    public function test_the_archived_count_is_branch_scoped(): void
    {
        $readCount = function (User $viewer): int {
            $html = $this->actingAs($viewer, 'admin')->get(route('admin.menu-items'))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/Archived \((\d+)\)/', $html);
            preg_match('/Archived \((\d+)\)/', $html, $m);

            return (int) $m[1];
        };

        $owner = $this->owner();
        $supervisor = $this->supervisor(1);
        $staff = $this->staff(1);

        $start = ['owner' => $readCount($owner), 'supervisor' => $readCount($supervisor), 'staff' => $readCount($staff)];

        $this->item('Count B2', 2)->archive();
        $this->item('Count Shared', null)->archive();

        $this->assertSame($start['owner'] + 2, $readCount($owner), 'the owner counts every branch');
        $this->assertSame($start['supervisor'], $readCount($supervisor), 'another branch / shared must not count for a supervisor');
        $this->assertSame($start['staff'], $readCount($staff), 'another branch / shared must not count for staff');

        $this->item('Count B1', 1)->archive();

        $this->assertSame($start['owner'] + 3, $readCount($owner));
        $this->assertSame($start['supervisor'] + 1, $readCount($supervisor), 'their own branch must count');
        $this->assertSame($start['staff'] + 1, $readCount($staff));
    }

    // ══════════ D. Permanent Delete ══════════

    public function test_the_owner_can_permanently_delete_an_archived_never_ordered_item(): void
    {
        $item = $this->item('Force me');
        $this->giveLinks($item);
        $item->archive();

        $this->assertSame(2, DB::table('menu_item_options')->where('menu_item_id', $item->id)->count());
        $this->assertSame(2, DB::table('menu_item_ingredients')->where('menu_item_id', $item->id)->count());

        $this->forceDelete($this->owner(), $item->id)
            ->assertRedirect(route('admin.archived'))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->rawRowCount($item->id), 'the raw row must be gone');
        $this->assertSame(0, DB::table('menu_item_options')->where('menu_item_id', $item->id)->count(), 'add-on assignments cascade');
        $this->assertSame(0, DB::table('menu_item_ingredients')->where('menu_item_id', $item->id)->count(), 'recipe rows cascade');

        // SHORTENED 2026-09-24: the success toast no longer spells out the
        // add-on/recipe counts — those are shown BEFORE the click, in the
        // confirm() dialog and the Archived page's own warning text (both
        // still built from the withCount() query, unaffected by this change).
        $this->assertSame(
            '"' . self::PREFIX . ' Force me" was permanently deleted.',
            session('success')
        );
    }

    /**
     * Dedicated regression test for the short unsold-item message shape
     * (2026-09-24): no "Menu item" prefix, no counts, no second sentence —
     * an unsold item was never ordered, so there is nothing to say was kept.
     */
    public function test_the_unsold_item_success_message_is_the_short_form(): void
    {
        $item = $this->item('Short Message Unsold');
        $item->archive();

        $this->forceDelete($this->owner(), $item->id)->assertSessionHasNoErrors();

        $this->assertSame(
            '"' . self::PREFIX . ' Short Message Unsold" was permanently deleted.',
            session('success')
        );
    }

    /**
     * Database first, file second — proven by looking at the file at the
     * exact moment the row is deleted, not inferred from the end state.
     */
    public function test_the_image_is_removed_only_after_the_database_delete_succeeded(): void
    {
        $image = $this->imageFile();
        $item = $this->item('Pictured force', 1, $image);
        $item->archive();

        $fileExistedAtRowDelete = null;
        MenuItem::deleted(function (MenuItem $deleted) use ($item, $image, &$fileExistedAtRowDelete) {
            if ($deleted->id === $item->id) {
                $fileExistedAtRowDelete = file_exists(public_path($image));
            }
        });

        $this->forceDelete($this->owner(), $item->id)->assertSessionHasNoErrors();

        $this->assertTrue($fileExistedAtRowDelete, 'the image must still exist when the row is deleted');
        $this->assertSame(0, $this->rawRowCount($item->id));
        $this->assertFileDoesNotExist(public_path($image), 'the image must be gone once the row is');
    }

    /** A failed database delete leaves everything — the image included — exactly where it was. */
    public function test_a_failed_database_delete_keeps_the_row_the_links_and_the_image(): void
    {
        $image = $this->imageFile();
        $item = $this->item('Fails', 1, $image);
        $this->giveLinks($item);
        $item->archive();
        $before = $this->linkIds($item->id);

        MenuItem::deleting(function () {
            throw new \RuntimeException('simulated database failure');
        });

        $response = $this->forceDelete($this->owner(), $item->id);

        $response->assertRedirect(route('admin.archived'));
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertNull(session('success'), 'a failed delete must never be reported as a success');
        $this->assertStringContainsString('Nothing was changed', session('errors')->first('error'));

        $this->assertSame(1, $this->rawRowCount($item->id));
        $this->assertTrue(MenuItem::withArchived()->find($item->id)->isArchived(), 'still archived, not restored');
        $this->assertSame($before, $this->linkIds($item->id));
        $this->assertFileExists(public_path($image), 'the image must survive a failed delete');
    }

    /**
     * The row is gone but the file could not be removed: say so, keep the
     * delete, and do not pretend the image went.
     */
    public function test_an_image_that_cannot_be_removed_is_reported_and_the_delete_stands(): void
    {
        // A directory where the image file should be: file_exists() is true
        // and unlink() fails, deterministically, on every platform.
        $relative = 'uploads/menu-items/MIPD_TEST_DIR_' . uniqid() . '.png';
        mkdir(public_path($relative), 0777, true);
        $this->createdFiles[] = public_path($relative);

        $item = $this->item('Stuck image', 1, $relative);
        $item->archive();

        $this->forceDelete($this->owner(), $item->id)->assertRedirect(route('admin.archived'));

        $this->assertSame(0, $this->rawRowCount($item->id), 'the database delete must stand');
        $this->assertStringContainsString('was permanently deleted', session('success'));
        $this->assertStringContainsString(
            'could not be removed from the server',
            session('errors')->first('error'),
            'the cleanup failure must be reported, not hidden'
        );
        $this->assertStringContainsString($relative, session('errors')->first('error'));
        $this->assertTrue(is_dir(public_path($relative)));
    }

    /** Never pull an image out from under another item that uses the same file. */
    public function test_an_image_still_used_by_another_item_is_kept(): void
    {
        $image = $this->imageFile();
        $item = $this->item('Shares a picture', 1, $image);
        $this->item('Keeps the picture', 1, $image);
        $item->archive();

        $this->forceDelete($this->owner(), $item->id)->assertSessionHasNoErrors();

        $this->assertSame(0, $this->rawRowCount($item->id));
        $this->assertFileExists(public_path($image));
    }

    /** The three statuses COMMITTED_ORDER_STATUSES calls open. */
    public static function openOrderStatuses(): array
    {
        return [
            'pending order'   => ['pending'],
            'preparing order' => ['preparing'],
            'serving order'   => ['serving'],
        ];
    }

    public static function finalOrderStatuses(): array
    {
        return [
            'completed order' => ['completed'],
            'cancelled order' => ['cancelled'],
        ];
    }

    /**
     * FLIPPED 2026-09-24 (Phase 2). This used to refuse ANY order line. Now
     * only an OPEN order blocks: its stock has not been deducted yet, and
     * deduction reads the live recipe this delete would take away. With a
     * sentence, not SQL, and never a 500 — and nothing changes.
     *
     * @dataProvider openOrderStatuses
     */
    public function test_permanent_delete_is_refused_while_the_item_is_on_an_open_order(string $status): void
    {
        $image = $this->imageFile();
        $item = $this->item('Sold ' . $status, 1, $image);
        $this->giveLinks($item);
        $order = $this->sell($item, $status);
        $item->archive();
        $before = $this->linkIds($item->id);
        $lineBefore = (array) DB::table('order_items')->where('order_id', $order->id)->first();

        $response = $this->forceDelete($this->owner(), $item->id);

        $response->assertRedirect(route('admin.archived'));
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertNull(session('success'));

        $message = session('errors')->first('error');
        $this->assertStringContainsString('cannot be permanently deleted yet', $message);
        $this->assertStringContainsString('1 of its order lines is still on an open order', $message);
        $this->assertStringContainsString('Complete or cancel that order first', $message);
        foreach (['SQLSTATE', 'foreign key', 'constraint', 'order_items', 'pomida_db', 'delete from'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $message);
        }

        $this->assertSame(1, $this->rawRowCount($item->id));
        $this->assertTrue(MenuItem::withArchived()->find($item->id)->isArchived());
        $this->assertSame($before, $this->linkIds($item->id));
        $this->assertFileExists(public_path($image));
        $this->assertEquals($lineBefore, (array) DB::table('order_items')->where('order_id', $order->id)->first(), 'the line is untouched');
    }

    /**
     * FLIPPED 2026-09-24 (Phase 2): completed and cancelled lines no longer
     * block. The item goes; its line stays with the link cut and its own
     * name/price snapshot intact.
     *
     * @dataProvider finalOrderStatuses
     */
    public function test_a_sold_item_with_only_finished_orders_is_permanently_deleted(string $status): void
    {
        $image = $this->imageFile();
        $item = $this->item('Sold ' . $status, 1, $image);
        $this->giveLinks($item);
        $order = $this->sell($item, $status);
        $item->archive();

        $this->forceDelete($this->owner(), $item->id)
            ->assertRedirect(route('admin.archived'))
            ->assertSessionHasNoErrors();

        // SHORTENED 2026-09-24: no per-line count in the success toast any more.
        $this->assertStringContainsString('was permanently deleted', session('success'));
        $this->assertStringContainsString('Past receipts and sales records were kept', session('success'));
        $this->assertSame(0, $this->rawRowCount($item->id));
        $this->assertSame([[], []], $this->linkIds($item->id), 'assignments and recipe rows cascade');
        $this->assertFileDoesNotExist(public_path($image));

        $line = DB::table('order_items')->where('order_id', $order->id)->first();
        $this->assertNotNull($line, 'the order line must survive');
        $this->assertNull($line->menu_item_id, 'only its link is cut');
        $this->assertSame($item->name, $line->item_name);
        $this->assertSame('95.00', (string) $line->item_price);
    }

    /**
     * FLIPPED 2026-09-24 (Phase 2): the schema backstop is now SET NULL, not
     * RESTRICT. A raw delete of a sold item keeps its lines and cuts their
     * link — which is exactly why permanentlyDeleteMenuItem()'s open-order
     * check is the only thing standing between an open order and a lost
     * deduction.
     */
    public function test_the_database_keeps_a_sold_items_lines_and_cuts_their_link(): void
    {
        $item = $this->item('FK backstop');
        $order = $this->sell($item, 'cancelled');

        DB::transaction(fn () => DB::table('menu_items')->where('id', $item->id)->delete());

        $this->assertSame(0, $this->rawRowCount($item->id));
        $line = DB::table('order_items')->where('order_id', $order->id)->first();
        $this->assertNotNull($line);
        $this->assertNull($line->menu_item_id);
        $this->assertSame($item->name, $line->item_name);
    }

    /** A LIVE item is not in the archive, so this endpoint cannot reach it. */
    public function test_permanent_delete_refuses_a_live_item(): void
    {
        $item = $this->item('Still live');

        $this->forceDelete($this->owner(), $item->id)->assertRedirect(route('admin.archived'));

        $this->assertStringContainsString('not in the archive', session('errors')->first('error'));
        $this->assertNotNull(MenuItem::find($item->id), 'a live item must be untouched');
    }

    public function test_a_supervisor_cannot_permanently_delete(): void
    {
        $item = $this->item('Supervisor force', 1);
        $this->giveLinks($item);
        $item->archive();
        $before = $this->linkIds($item->id);

        $this->forceDelete($this->supervisor(1), $item->id)
            ->assertRedirect(route('admin.home'));
        $this->assertSame("You don't have permission to access that.", session('error'));

        $this->assertSame(1, $this->rawRowCount($item->id));
        $this->assertSame($before, $this->linkIds($item->id));
    }

    public function test_staff_cannot_permanently_delete(): void
    {
        $item = $this->item('Staff force', 1);
        $item->archive();

        $this->forceDelete($this->staff(1), $item->id)
            ->assertRedirect(route('admin.home'));
        $this->assertSame("You don't have permission to access that.", session('error'));

        $this->assertSame(1, $this->rawRowCount($item->id));
    }

    /**
     * CSRF is really enforced on this route. ValidateCsrfToken skips itself
     * when APP_ENV=testing, so — as in CsrfProtectionTest — the env is moved
     * off 'testing' first, and the valid-token control proves the 419 is the
     * token check and not the route being unusable.
     */
    public function test_permanent_delete_requires_a_csrf_token(): void
    {
        $item = $this->item('CSRF force');
        $item->archive();
        $owner = $this->owner();
        $url = route('admin.archived.menu-item.force-delete', $item->id);

        $this->app['env'] = 'production';
        $this->assertFalse($this->app->runningUnitTests(), 'CSRF is still being skipped — this test would prove nothing');

        $refused = $this->actingAs($owner, 'admin')->delete($url);

        $this->assertSame(419, $refused->getStatusCode(), 'a DELETE with no CSRF token must be refused');
        $this->assertSame(1, $this->rawRowCount($item->id));

        $token = 'mipd-valid-token';
        $this->actingAs($owner, 'admin')
            ->withSession(['_token' => $token])
            ->delete($url, ['_token' => $token])
            ->assertRedirect(route('admin.archived'));

        $this->assertSame(0, $this->rawRowCount($item->id), 'control: with a valid token the owner\'s delete goes through');
    }

    // ══════════ E. restore keeps the same links ══════════

    public function test_restore_brings_back_the_exact_same_links(): void
    {
        $item = $this->item('Round trip');
        $this->giveLinks($item);
        $before = $this->linkIds($item->id);

        $owner = $this->owner();
        $this->actingAs($owner, 'admin')->delete(route('admin.menu-items.delete', $item->id));
        $this->assertTrue(MenuItem::withArchived()->find($item->id)->isArchived());

        $this->actingAs($owner, 'admin')
            ->put(route('admin.archived.restore', ['menu-item', $item->id]))
            ->assertRedirect(route('admin.archived'))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(MenuItem::find($item->id), 'restored to the live list');
        $this->assertSame($before, $this->linkIds($item->id), 'the same pivot and recipe ROWS, not recreated ones');
    }

    /** Names are not unique in the schema, so a replacement can coexist with the archived original. */
    public function test_a_same_name_item_can_coexist_with_its_archived_original(): void
    {
        $original = $this->item('Twin');
        $original->archive();

        $replacement = $this->item('Twin');

        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame(2, MenuItem::withArchived()->where('name', self::PREFIX . ' Twin')->count());
        $this->assertSame([$replacement->id], MenuItem::where('name', self::PREFIX . ' Twin')->pluck('id')->all());

        // Restoring the original afterwards is not blocked by its twin.
        $this->actingAs($this->owner(), 'admin')
            ->put(route('admin.archived.restore', ['menu-item', $original->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, MenuItem::where('name', self::PREFIX . ' Twin')->count());
    }

    // ══════════ F. order history stays readable ══════════

    public function test_order_history_reads_the_same_while_archived_and_after_restore(): void
    {
        $item = $this->item('History dish');
        $order = $this->sell($item, 'completed');
        $owner = $this->owner();

        // The customer's own receipt too, read as the order's real owner so
        // the ownership guard does not mask the result.
        $customer = User::where('role', 'customer')->orderBy('id')->firstOrFail();
        $order->update(['user_id' => $customer->id]);

        $read = function (bool $deleted = false) use ($owner, $customer, $order, $item) {
            $this->actingAs($owner, 'admin')->get(route('admin.receipt', $order->id))
                ->assertOk()->assertSee($item->name);

            $this->actingAs($customer, 'customer')->get('/customer/receipt/' . $order->id)
                ->assertOk()->assertSee($item->name);

            $this->actingAs($owner, 'admin')->get(route('admin.completed-orders'))
                ->assertOk()->assertSee($order->order_number);

            $this->actingAs($owner, 'admin')->get(route('admin.summary'))->assertOk();

            $line = OrderItem::where('order_id', $order->id)->firstOrFail();
            $this->assertSame($item->name, $line->item_name, 'the name snapshot is untouched');
            $this->assertSame('95.00', (string) $line->item_price, 'the price snapshot is untouched');

            if ($deleted) {
                $this->assertNull($line->menu_item_id, 'after a permanent delete only the link is cut');
                $this->assertNull($line->menuItem);
            } else {
                $this->assertSame($item->id, $line->menu_item_id, 'the order line keeps its menu_item_id');
                $this->assertNotNull($line->menuItem, 'the relation still resolves an archived item');
            }
        };

        $read();

        $this->actingAs($owner, 'admin')->delete(route('admin.menu-items.delete', $item->id));
        $this->assertTrue(MenuItem::withArchived()->find($item->id)->isArchived());
        $read();

        $this->actingAs($owner, 'admin')->put(route('admin.archived.restore', ['menu-item', $item->id]));
        $this->assertNotNull(MenuItem::find($item->id));
        $read();

        // Phase 2: archive again, then permanently delete. History still reads.
        $this->actingAs($owner, 'admin')->delete(route('admin.menu-items.delete', $item->id));
        $this->forceDelete($owner, $item->id)->assertSessionHasNoErrors();
        $this->assertSame(0, $this->rawRowCount($item->id));
        $read(deleted: true);
    }

    // ══════════ G. the Archived page UI ══════════

    public function test_the_permanent_delete_control_is_a_csrf_protected_delete_form_with_a_confirm(): void
    {
        $item = $this->item('UI force');
        $this->giveLinks($item);
        $item->archive();

        $html = $this->actingAs($this->owner(), 'admin')->get(route('admin.archived'))->assertOk()->getContent();

        $action = route('admin.archived.menu-item.force-delete', $item->id);
        $pattern = '#<form action="' . preg_quote($action, '#') . '"\s+method="POST"\s+onsubmit="([^"]*)"[^>]*>(.*?)</form>#s';

        $this->assertMatchesRegularExpression($pattern, $html, 'the owner must get a Permanent Delete form for a never-ordered item');
        preg_match($pattern, $html, $form);

        $confirm = html_entity_decode($form[1], ENT_QUOTES);
        $this->assertStringStartsWith('return confirm(', $confirm);
        $this->assertStringContainsString('Permanently delete', $confirm);
        $this->assertStringContainsString('This cannot be undone.', $confirm);
        $this->assertStringContainsString('2 add-on assignments', $confirm, 'counts include the archived add-on');
        $this->assertStringContainsString('2 recipe ingredients', $confirm);

        $this->assertStringContainsString('name="_method" value="DELETE"', $form[2]);
        $this->assertMatchesRegularExpression('/name="_token" value="[^"]+"/', $form[2]);
        $this->assertStringContainsString('Permanently Delete', $form[2]);

        // The same counts are on the page before any click.
        $this->assertStringContainsString('also removes 2 add-on assignments', $html);
        $this->assertStringContainsString('and 2 recipe ingredients', $html);
    }

    /**
     * FLIPPED 2026-09-24 (Phase 2). A sold item with only finished orders now
     * gets the control, and the card says its history is kept — not that it
     * "can be restored but not permanently deleted".
     */
    public function test_a_sold_item_gets_a_permanent_delete_control_that_says_history_is_kept(): void
    {
        $sold = $this->item('UI sold');
        $this->sell($sold, 'completed');
        $sold->archive();

        $html = $this->actingAs($this->owner(), 'admin')->get(route('admin.archived'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.archived.menu-item.force-delete', $sold->id), $html);
        $this->assertStringContainsString(
            'On 1 past order line — those receipts and sales figures are kept if you permanently delete it.',
            html_entity_decode($html, ENT_QUOTES)
        );
        $this->assertStringNotContainsString('can be restored but not permanently deleted', $html);
        $this->assertStringContainsString(route('admin.archived.restore', ['menu-item', $sold->id]), $html, 'it can still be restored');
    }

    /** An item on an open order: explanation, no control. */
    public function test_an_item_on_an_open_order_gets_no_permanent_delete_control(): void
    {
        $open = $this->item('UI open');
        $this->sell($open, 'completed');
        $this->sell($open, 'preparing');
        $open->archive();

        $html = $this->actingAs($this->owner(), 'admin')->get(route('admin.archived'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('admin.archived.menu-item.force-delete', $open->id), $html);
        $this->assertStringContainsString(
            'On an open order — permanent delete is available once it is completed or cancelled.',
            html_entity_decode($html, ENT_QUOTES)
        );
        $this->assertStringContainsString(route('admin.archived.restore', ['menu-item', $open->id]), $html);
    }

    public function test_the_permanent_delete_control_is_absent_for_non_owners(): void
    {
        $item = $this->item('UI hidden', 1);
        $item->archive();

        foreach (['supervisor' => $this->supervisor(1), 'staff' => $this->staff(1)] as $role => $viewer) {
            $html = $this->actingAs($viewer, 'admin')->get(route('admin.archived'))->assertOk()->getContent();

            $this->assertStringContainsString($item->name, $html, "setup: $role must see the item itself");
            $this->assertStringNotContainsString('/force"', $html, "$role must not get a Permanent Delete form");
            $this->assertStringNotContainsString('Permanently Delete', $html);
            $this->assertStringNotContainsString('Permanently deleting this', $html, "$role must not get the owner's warning");
        }
    }

    /** The page's cost must not grow with the number of archived items. */
    public function test_the_archived_page_has_no_n_plus_one(): void
    {
        $owner = $this->owner();

        $measure = function () use ($owner): int {
            $queries = 0;
            $listening = true;
            DB::listen(function () use (&$queries, &$listening) {
                if ($listening) {
                    $queries++;
                }
            });

            try {
                $this->actingAs($owner, 'admin')->get(route('admin.archived'))->assertOk();
            } finally {
                $listening = false;
            }

            return $queries;
        };

        $first = $this->item('NPlus 0');
        $this->giveLinks($first);
        $first->archive();
        $this->actingAs($owner, 'admin')->get(route('admin.archived'))->assertOk(); // warm-up

        $withOne = $measure();

        for ($i = 1; $i <= 5; $i++) {
            $more = $this->item('NPlus ' . $i, $i % 2 === 0 ? 1 : 2);
            $this->giveLinks($more);
            $more->archive();
        }

        $withSix = $measure();

        $this->assertSame($withOne, $withSix, 'each extra archived item must not add queries');
    }
}
