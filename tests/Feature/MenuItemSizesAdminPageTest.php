<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\MenuItemSizeFixtures;
use Tests\TestCase;

/**
 * Menu Item Sizes, Phase 1 — the size editor on the admin Menu Items page.
 *
 * What the page must show: fixed "Regular"/"Large" labels (never a name box),
 * price + active + archive per live size, Restore only for the owner, a
 * "No Recipe Set" badge for a recipe-less size, each size's recipe in the
 * shared recipe partial posting to the SIZE endpoints — and exactly one copy
 * of each of those, so no hidden duplicate can hold stale state.
 *
 * Query cost: sizes, their recipes and those recipes' inventory are three
 * batched eager loads; the page's query count must not grow with the number
 * of sized items.
 */
class MenuItemSizesAdminPageTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    private function page(User $viewer, int $branchId): string
    {
        return $this->actingAs($viewer, 'admin')
            ->withSession(['selected_branch_id' => $branchId])
            ->get(route('admin.menu-items'))
            ->assertOk()
            ->getContent();
    }

    public function test_a_sized_item_renders_fixed_regular_and_large_controls_and_its_size_recipes(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular, $large] = $this->enableSizes($item, 100, 150);
        $this->sizeRecipeLine($regular, $this->sizeInventory($branch->id, 100, 2, 'Beans'), 18);
        $this->sizeRecipeLine($regular, $this->sizeInventory($branch->id, 100, 1, 'Milk'), 150);

        $html = $this->page($this->sizeOwner(), $branch->id);

        // List: starting-from figure plus each size's own price.
        $this->assertStringContainsString('<span class="menu-price-from">from</span> ₱100.00', $html);
        $this->assertStringContainsString('Regular ₱100.00', $html);
        $this->assertStringContainsString('Large ₱150.00', $html);
        $this->assertMatchesRegularExpression('/data-id="' . $item->id . '"[^>]*data-has-sizes="1"/s', $html);

        // Editor: fixed labels, price + active posting to THIS size's form.
        foreach ([$regular, $large] as $size) {
            $this->assertStringContainsString('<strong class="size-name">' . $size->name . '</strong>', $html);
            $this->assertMatchesRegularExpression('/id="sizePrice-' . $size->id . '" name="price" form="sizeUpdate-' . $size->id . '"/', $html);
            $this->assertMatchesRegularExpression('/id="sizeActive-' . $size->id . '" name="is_active" value="1" form="sizeUpdate-' . $size->id . '" checked/', $html);
            $this->assertMatchesRegularExpression(
                '#<form id="sizeUpdate-' . $size->id . '" method="POST" action="' . preg_quote(route('admin.menu-items.sizes.update', [$item->id, $size->id]), '#') . '"><input type="hidden" name="_token" value="[^"]+"[^>]*>\s*<input type="hidden" name="_method" value="PUT">#',
                $html
            );
            $this->assertStringContainsString('data-url="' . route('admin.menu-items.sizes.ingredients.add', [$item->id, $size->id]) . '"', $html);
        }

        // No size control anywhere takes a name.
        $this->assertDoesNotMatchRegularExpression('/<(?:input|select|textarea)[^>]*form="size[^"]*"[^>]*name="name"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<(?:input|select|textarea)[^>]*name="name"[^>]*form="size/', $html);

        // Recipe status badges: Regular has 2 lines, Large has none.
        $this->assertMatchesRegularExpression('/id="size-recipe-chip-' . $regular->id . '">\s*2 ingredients/', $html);
        $this->assertMatchesRegularExpression('/size-chip-warn" id="size-recipe-chip-' . $large->id . '">\s*No Recipe Set/', $html);

        // Regular's saved rows delete through the SIZE route, with quantity from `quantity`.
        $this->assertStringContainsString(route('admin.menu-items.sizes.ingredients.delete', [$item->id, $regular->id, '']), $html);
        $this->assertMatchesRegularExpression('/data-qty="150\.000"/', $html);
    }

    public function test_every_size_control_block_is_rendered_exactly_once(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular, $large] = $this->enableSizes($item);
        $plain = $this->sizeItem($branch->id, 'Plain');

        $html = $this->page($this->sizeOwner(), $branch->id);

        foreach ([
            'id="sizesSection"',
            'id="sizes-' . $item->id . '"',
            'id="sizes-' . $plain->id . '"',
            'id="sizeEnable-' . $plain->id . '"',
            'id="sizeUpdate-' . $regular->id . '"',
            'id="sizeArchive-' . $large->id . '"',
            'id="recipe-size-' . $regular->id . '"',
            'id="recipe-tbody-size-' . $large->id . '"',
            'id="size-recipe-chip-' . $large->id . '"',
            'id="sizePrice-' . $large->id . '"',
        ] as $needle) {
            $this->assertSame(1, substr_count($html, $needle), "{$needle} must appear exactly once");
        }

        // A sized item gets no set-up form; an unsized one gets no per-size forms.
        $this->assertSame(0, substr_count($html, 'id="sizeEnable-' . $item->id . '"'));
        $this->assertMatchesRegularExpression('/name="regular_price" form="sizeEnable-' . $plain->id . '"/', $html);
        $this->assertMatchesRegularExpression('/name="large_price" form="sizeEnable-' . $plain->id . '"/', $html);
        $this->assertMatchesRegularExpression('/data-id="' . $plain->id . '"[^>]*data-has-sizes="0"/s', $html);

        // The size forms sit outside #itemForm (nested forms are invalid HTML).
        $itemFormEnd = strpos($html, '</form>', strpos($html, 'id="itemForm"'));
        $this->assertGreaterThan($itemFormEnd, strpos($html, '<form id="sizeUpdate-' . $regular->id . '"'));
    }

    public function test_restore_is_offered_to_the_owner_only(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [, $large] = $this->enableSizes($item);
        $large->archive();

        $ownerHtml = $this->page($this->sizeOwner(), $branch->id);
        $this->assertStringContainsString('<form id="sizeRestore-' . $large->id . '"', $ownerHtml);
        $this->assertStringContainsString('form="sizeRestore-' . $large->id . '"', $ownerHtml);
        $this->assertStringNotContainsString('id="sizeUpdate-' . $large->id . '"', $ownerHtml, 'an archived size has no edit controls');

        $supervisorHtml = $this->page($this->sizeSupervisor($branch->id), $branch->id);
        $this->assertStringNotContainsString('sizeRestore-' . $large->id, $supervisorHtml);
        $this->assertStringNotContainsString(route('admin.menu-items.sizes.restore', [$item->id, $large->id]), $supervisorHtml);
        $this->assertStringContainsString('Only the owner can restore an archived size.', $supervisorHtml);
    }

    public function test_staff_see_the_size_prices_but_no_size_controls(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular] = $this->enableSizes($item, 100, 150);

        $html = $this->page($this->sizeStaff($branch->id), $branch->id);

        $this->assertStringContainsString('Regular ₱100.00', $html);
        $this->assertStringNotContainsString('id="sizesSection"', $html);
        $this->assertStringNotContainsString('sizeUpdate-' . $regular->id, $html);
        $this->assertStringNotContainsString('/sizes', $html, 'no size endpoint URL is shipped to staff');
    }

    // ══════════ 33-35. Query cost ══════════

    private function pageQueries(User $viewer, int $branchId): int
    {
        $n = 0;
        $listening = false;
        DB::listen(function () use (&$n, &$listening) {
            if ($listening) {
                $n++;
            }
        });

        $listening = true;
        try {
            $this->actingAs($viewer, 'admin')
                ->withSession(['selected_branch_id' => $branchId])
                ->get(route('admin.menu-items'))
                ->assertOk();
        } finally {
            $listening = false;
        }

        return $n;
    }

    private function addSizedItems(int $branchId, int $count, array $inventory): void
    {
        for ($i = 0; $i < $count; $i++) {
            $item = $this->sizeItem($branchId, 'Sized ' . $i);
            $this->baseRecipeLine($item, $inventory[0], 1);
            foreach ($this->enableSizes($item) as $size) {
                foreach ($inventory as $inv) {
                    $this->sizeRecipeLine($size, $inv, 2);
                }
            }
        }
    }

    public function test_the_menu_items_page_query_count_does_not_grow_with_sized_items(): void
    {
        $branch = $this->sizeBranch('A');
        $inventory = [$this->sizeInventory($branch->id), $this->sizeInventory($branch->id)];
        $owner = $this->sizeOwner();

        for ($i = 0; $i < 10; $i++) {
            $this->baseRecipeLine($this->sizeItem($branch->id, 'Plain ' . $i), $inventory[0], 1);
        }
        $plainOnly = $this->pageQueries($owner, $branch->id);

        $this->addSizedItems($branch->id, 10, $inventory);
        $withTenSized = $this->pageQueries($owner, $branch->id);

        $this->addSizedItems($branch->id, 20, $inventory);
        $withThirtySized = $this->pageQueries($owner, $branch->id);

        $this->assertSame($withTenSized, $withThirtySized, "query count grew with sized items: {$withTenSized} at 10, {$withThirtySized} at 30 — an N+1 in the size editor");
        // Measured 17 -> 19: the size recipes and their inventory are two
        // batched loads that only run once any size exists (the sizes query
        // itself runs for plain items too).
        $this->assertLessThanOrEqual($plainOnly + 2, $withTenSized, "sizes cost more than their two batched loads: {$plainOnly} plain, {$withTenSized} sized");
    }
}
