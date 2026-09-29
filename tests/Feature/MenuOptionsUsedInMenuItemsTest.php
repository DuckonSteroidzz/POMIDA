<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * admin/menu-options — "Used in: ..." on each option row (Sept 2026 follow-up
 * to 1e9df73).
 *
 * Each option's per-branch Mapped/Unmapped badges already said WHICH
 * branches an option was assigned to, but never WHICH menu items — finding
 * that out meant opening every menu item on the right one at a time and
 * checking its assigned-options list. showMenuOptions() now eager-loads
 * 'menuItems:id,name,branch_id' (name added; the relation itself already
 * existed for the badges) and the option row renders a collapsed "Used in N
 * item(s)" toggle that expands to a chip per assigned item.
 *
 * BRANCH SCOPE. This list is concrete, named menu-item rows — the same shape
 * of information as the Menu Items picker on the right, which 1e9df73 already
 * scoped to a locked supervisor's branch (and, deliberately, withholds a
 * NULL-branch/"All Branches" item from them too, since Save Options would
 * 404 on it). Left unscoped, "Used in" would have quietly reopened that same
 * "picker shows rows the viewer cannot act on" shape one line over. It is
 * filtered here the same way, independent of the (still deliberately
 * unscoped) per-branch badges.
 *
 * Every row this file creates carries the MOUI prefix and runs inside
 * DatabaseTransactions against pomida_db_testing, so nothing survives the run
 * and no pre-existing row is modified or deleted.
 */
class MenuOptionsUsedInMenuItemsTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MOUI';
    private const MAIN = 1;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function supervisorAt(?int $branchId): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Supervisor',
            'email'     => strtolower(self::PREFIX) . '-sup-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => 'supervisor',
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    private function farBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'MOU' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function itemNamed(string $name, ?int $branchId): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => $name,
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function optionNamed(string $name): MenuOption
    {
        return MenuOption::create([
            'name'             => $name,
            'additional_price' => 0,
            'is_active'        => true,
            'display_order'    => 0,
        ]);
    }

    private function page(User $actor): string
    {
        return $this->actingAs($actor, 'admin')
            ->get('/admin/menu-options')
            ->assertOk()
            ->content();
    }

    /**
     * How many items the column says this option is used in.
     *
     * THREE STATES, since the column became a disclosure. Nothing is a null;
     * one item is its own chip in the cell, with no count to read and no
     * button to press, so it is counted as 1 by the presence of that chip;
     * two or more are behind a "Used in N items" button, which states the
     * number itself.
     */
    private function usedInCount(string $html, MenuOption $option): ?int
    {
        $pattern = '/toggleUsedIn\(' . $option->id . '\)"[^>]*>.*?Used in (\d+) items/s';

        if (preg_match($pattern, $html, $m)) {
            return (int) $m[1];
        }

        return str_contains($this->usedInCellHtml($html, $option), 'pchy-usedin-solo') ? 1 : null;
    }

    /**
     * Whatever the Used In column is showing for this option — the popover's
     * chips when there are several, the single chip when there is one, the
     * empty line when there are none. Callers assert item names against this
     * without having to know which shape they are in.
     */
    private function usedInListHtml(string $html, MenuOption $option): string
    {
        $pattern = '/id="usedin-list-' . $option->id . '"[^>]*>(.*?)<\/div>/s';

        if (preg_match($pattern, $html, $m)) {
            return $m[1];
        }

        return $this->usedInCellHtml($html, $option);
    }

    /** The option row's Used In cell, bounded by its own </td>. */
    private function usedInCellHtml(string $html, MenuOption $option): string
    {
        $card = $this->optionCardHtml($html, $option);

        $start = strpos($card, '<td data-l="Used In"');

        if ($start === false) {
            return '';
        }

        $end = strpos($card, '</td>', $start);

        return substr($card, $start, $end - $start);
    }

    /** This option's whole card, from its own opt-{id} wrapper up to the next option's. */
    private function optionCardHtml(string $html, MenuOption $option): string
    {
        $marker = 'id="opt-' . $option->id . '"';
        $start = strpos($html, $marker);

        if ($start === false) {
            return '';
        }

        $next = null;
        if (preg_match('/id="opt-\d+"/', $html, $m, PREG_OFFSET_CAPTURE, $start + strlen($marker))) {
            $next = $m[0][1];
        }

        return substr($html, $start, ($next !== null ? $next : strlen($html)) - $start);
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. "Used in" reflects real menu_item_options assignments
    // ══════════════════════════════════════════════════════════════════

    public function test_used_in_lists_every_menu_item_the_option_is_assigned_to(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Extra Cheese ' . uniqid());
        $itemA = $this->itemNamed(self::PREFIX . ' Pizza ' . uniqid(), self::MAIN);
        $itemB = $this->itemNamed(self::PREFIX . ' Pasta ' . uniqid(), self::MAIN);

        $itemA->options()->attach($option->id);
        $itemB->options()->attach($option->id);

        $html = $this->page($this->admin());

        $this->assertSame(2, $this->usedInCount($html, $option));

        $list = $this->usedInListHtml($html, $option);
        $this->assertStringContainsString($itemA->name, $list);
        $this->assertStringContainsString($itemB->name, $list);
    }

    public function test_removing_the_assignment_from_one_item_drops_only_that_item(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Extra Ice ' . uniqid());
        $itemA = $this->itemNamed(self::PREFIX . ' Iced Tea ' . uniqid(), self::MAIN);
        $itemB = $this->itemNamed(self::PREFIX . ' Soda ' . uniqid(), self::MAIN);

        $itemA->options()->attach($option->id);
        $itemB->options()->attach($option->id);

        // Sanity: both present before the detach.
        $before = $this->usedInListHtml($this->page($this->admin()), $option);
        $this->assertStringContainsString($itemA->name, $before);
        $this->assertStringContainsString($itemB->name, $before);

        $itemA->options()->detach($option->id);

        $html = $this->page($this->admin());

        $this->assertSame(1, $this->usedInCount($html, $option));

        $list = $this->usedInListHtml($html, $option);
        $this->assertStringNotContainsString($itemA->name, $list, 'the detached item must drop off');
        $this->assertStringContainsString($itemB->name, $list, 'the still-assigned item must stay');

        // Down to one item, the column stops being a disclosure: the chip is
        // in the cell and there is no popover left holding the old pair.
        $this->assertStringContainsString('pchy-usedin-solo', $list);
        $this->assertStringNotContainsString('id="usedin-list-' . $option->id . '"', $html);
    }

    /**
     * THE DISCLOSURE ITSELF.
     *
     * Two or more items collapse behind a count button with a popover beside
     * it. The popover is .pchy-pop, which is display:none until opened and
     * position:fixed when it is, so a row's height is the same whether it
     * holds two items or twenty — the whole point of the change.
     */
    public function test_two_or_more_items_collapse_behind_a_count_button_with_a_popover(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Popover ' . uniqid());
        $itemA = $this->itemNamed(self::PREFIX . ' Burger ' . uniqid(), self::MAIN);
        $itemB = $this->itemNamed(self::PREFIX . ' Fries ' . uniqid(), self::MAIN);
        $itemC = $this->itemNamed(self::PREFIX . ' Shake ' . uniqid(), self::MAIN);

        $itemA->options()->attach($option->id);
        $itemB->options()->attach($option->id);
        $itemC->options()->attach($option->id);

        $html = $this->page($this->admin());
        $cell = $this->usedInCellHtml($html, $option);

        // The button states the count and is wired to the toggle.
        $this->assertStringContainsString('Used in 3 items', $cell);
        $this->assertStringContainsString('toggleUsedIn(' . $option->id . ')', $cell);
        $this->assertStringContainsString('aria-expanded="false"', $cell);
        $this->assertStringContainsString('aria-controls="usedin-list-' . $option->id . '"', $cell);

        // The panel is a popover, in the same cell, holding all three.
        $this->assertStringContainsString('class="pchy-pop pchy-usedin-list"', $cell);

        $list = $this->usedInListHtml($html, $option);
        foreach ([$itemA, $itemB, $itemC] as $item) {
            $this->assertStringContainsString($item->name, $list);
        }

        // Closed by default and taken out of flow when open, so it cannot
        // change the row's height either way.
        $this->assertMatchesRegularExpression(
            '/\.pchy-pop\{display:none;position:fixed/',
            $html,
            'the popover must be display:none and position:fixed, or it will push the table around'
        );
    }

    public function test_an_option_assigned_to_nothing_shows_the_empty_state(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Unused Add-on ' . uniqid());

        $html = $this->page($this->admin());

        $this->assertStringNotContainsString('id="usedin-list-' . $option->id . '"', $html);
        $this->assertStringContainsString(
            'Not assigned to any menu item yet.',
            $this->optionCardHtml($html, $option)
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. Branch scope — matches the Menu Items picker's own scoping (1e9df73)
    // ══════════════════════════════════════════════════════════════════

    public function test_a_locked_supervisor_only_sees_their_own_branchs_items_in_used_in(): void
    {
        $far = $this->farBranch();
        $supervisor = $this->supervisorAt($far->id);

        $option = $this->optionNamed(self::PREFIX . ' Add-on ' . uniqid());
        $mainItem = $this->itemNamed(self::PREFIX . ' Main Item ' . uniqid(), self::MAIN);
        $farItem = $this->itemNamed(self::PREFIX . ' Far Item ' . uniqid(), $far->id);
        $sharedItem = $this->itemNamed(self::PREFIX . ' Shared Item ' . uniqid(), null);

        $mainItem->options()->attach($option->id);
        $farItem->options()->attach($option->id);
        $sharedItem->options()->attach($option->id);

        // Admin sees all three.
        $adminHtml = $this->page($this->admin());
        $this->assertSame(3, $this->usedInCount($adminHtml, $option));

        // The far-branch supervisor sees only their own branch's item —
        // never Main's, and never the shared/"All Branches" one either,
        // mirroring resolveRecordInScope()'s own strict equality.
        $supHtml = $this->page($supervisor);
        $this->assertSame(1, $this->usedInCount($supHtml, $option));

        // Scoped down to one, the cell shows the chip rather than a
        // disclosure — and the two names it must never show are absent from
        // the whole row, not just from a panel that happens not to exist.
        $list = $this->usedInListHtml($supHtml, $option);
        $this->assertStringContainsString($farItem->name, $list);
        $this->assertStringNotContainsString($mainItem->name, $list);
        $this->assertStringNotContainsString($sharedItem->name, $list);

        $card = $this->optionCardHtml($supHtml, $option);
        $this->assertStringNotContainsString($mainItem->name, $card);
        $this->assertStringNotContainsString($sharedItem->name, $card);
    }

    /**
     * A supervisor with NO branch assigned locks to 0 (deny-by-default; see
     * AdminOrderAccess::lockedBranchId()), not null — and 0 is not a real
     * branch id, so this must resolve to "sees nothing", never to the
     * NULL-branch/"All Branches" item. A naive (int) $item->branch_id ===
     * (int) $lockedBranchId comparison would have let this one through,
     * since (int) null is also 0 in PHP; the view guards against that
     * explicitly (see the comment above $usedInItems in
     * menu-options.blade.php). This test exists to pin that specific case.
     */
    public function test_a_branchless_supervisor_sees_no_items_not_even_the_shared_one(): void
    {
        $supervisor = $this->supervisorAt(null);

        $option = $this->optionNamed(self::PREFIX . ' Add-on ' . uniqid());
        $sharedItem = $this->itemNamed(self::PREFIX . ' Shared Item ' . uniqid(), null);
        $sharedItem->options()->attach($option->id);

        $html = $this->page($supervisor);

        $this->assertStringNotContainsString('id="usedin-list-' . $option->id . '"', $html);
        $this->assertStringContainsString(
            'Not assigned to any menu item yet.',
            $this->optionCardHtml($html, $option)
        );
    }
}
