<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * admin/menu-items — branch-grouped table (Sept 2026 follow-up to 1e9df73).
 *
 * The page used to render every menu item in one flat table with no branch
 * grouping, so telling "what belongs to which branch" at a glance meant
 * reading the per-row Branch badge one row at a time. showMenuItems() now
 * orders non-null branches first (by id) and shared/"All Branches" items
 * last, and the view prints a section heading the moment the branch changes.
 * The read side was already branch-scoped for a locked staff/supervisor via
 * ResolvesBranchScope::getSelectedBranch() before this change (unlike the
 * pre-1e9df73 Menu Options picker), so the grouping tests here are paired
 * with a couple of regression checks that the ORDER BY change did not loosen
 * that scope.
 *
 * Every row this file creates carries the MIBG prefix and runs inside
 * DatabaseTransactions against pomida_db_testing, so nothing survives the run
 * and no pre-existing row is modified or deleted. Fixture items carry no
 * recipe on purpose — the Cost/Gross Profit columns degrade to "-" without
 * one (MenuItemCosting), which is exactly what lets this file skip creating
 * any Inventory rows.
 */
class MenuItemsPageBranchGroupingTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MIBG';
    private const MAIN = 1;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function supervisorAt(int $branchId): User
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

    private function newBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'MIB' . strtoupper(substr(uniqid(), -6)),
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

    private function page(User $actor, string $selectedBranch = 'all'): string
    {
        return $this->actingAs($actor, 'admin')
            ->withSession(['selected_branch_id' => $selectedBranch])
            ->get('/admin/menu-items')
            ->assertOk()
            ->content();
    }

    /** Everything from a group's own "<branch>" heading up to the NEXT heading (or end of page). */
    private function groupSegment(string $html, string $label): string
    {
        $marker = '<span class="value">' . $label . '</span>';
        $start = strpos($html, $marker);

        if ($start === false) {
            return '';
        }

        $next = strpos($html, 'menu-branch-group-row', $start + strlen($marker));

        return substr($html, $start, ($next !== false ? $next : strlen($html)) - $start);
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. Grouped by branch, in branch-id order, "All Branches" last
    // ══════════════════════════════════════════════════════════════════

    public function test_items_are_grouped_under_their_own_branch_heading(): void
    {
        $far = $this->newBranch();

        $mainItem = $this->itemNamed(self::PREFIX . ' Main Widget ' . uniqid(), self::MAIN);
        $farItem = $this->itemNamed(self::PREFIX . ' Far Widget ' . uniqid(), $far->id);

        $html = $this->page($this->admin());

        $this->assertStringContainsString('<span class="value">Main Branch</span>', $html);
        $this->assertStringContainsString('<span class="value">' . $far->name . '</span>', $html);

        $mainSegment = $this->groupSegment($html, 'Main Branch');
        $farSegment = $this->groupSegment($html, $far->name);

        $this->assertStringContainsString($mainItem->name, $mainSegment, 'the Main Branch item must sit in the Main Branch section');
        $this->assertStringNotContainsString($farItem->name, $mainSegment, 'a far-branch item must not leak into the Main Branch section');

        $this->assertStringContainsString($farItem->name, $farSegment, 'the far-branch item must sit in its own section');
        $this->assertStringNotContainsString($mainItem->name, $farSegment);

        // Main Branch (id 1) sorts before a freshly created (higher-id) branch.
        $this->assertLessThan(
            strpos($html, '<span class="value">' . $far->name . '</span>'),
            strpos($html, '<span class="value">Main Branch</span>'),
            'branch sections must be ordered by branch id'
        );
    }

    public function test_a_null_branch_item_gets_its_own_all_branches_section_rather_than_being_dropped(): void
    {
        $sharedItem = $this->itemNamed(self::PREFIX . ' Shared Widget ' . uniqid(), null);
        $mainItem = $this->itemNamed(self::PREFIX . ' Main Widget ' . uniqid(), self::MAIN);

        $html = $this->page($this->admin());

        $this->assertStringContainsString('<span class="value">All Branches</span>', $html);

        $sharedSegment = $this->groupSegment($html, 'All Branches');
        $this->assertStringContainsString($sharedItem->name, $sharedSegment, 'the null-branch item must not be dropped');
        $this->assertStringNotContainsString($mainItem->name, $sharedSegment);

        // "All Branches" sorts after every real branch, never before.
        $this->assertGreaterThan(
            strpos($html, '<span class="value">Main Branch</span>'),
            strpos($html, '<span class="value">All Branches</span>')
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. Regression: grouping must not loosen the existing branch-scope read
    // ══════════════════════════════════════════════════════════════════

    public function test_a_locked_supervisor_sees_only_their_own_branch_section(): void
    {
        $far = $this->newBranch();
        $supervisor = $this->supervisorAt($far->id);

        $mainItem = $this->itemNamed(self::PREFIX . ' Main Widget ' . uniqid(), self::MAIN);
        $farItem = $this->itemNamed(self::PREFIX . ' Far Widget ' . uniqid(), $far->id);
        $sharedItem = $this->itemNamed(self::PREFIX . ' Shared Widget ' . uniqid(), null);

        // A locked supervisor never has a picker for selected_branch_id, so
        // getSelectedBranch() ignores the session value and reads their
        // lockedBranchId() instead — no session override needed here.
        $html = $this->page($supervisor);

        $this->assertStringContainsString('<span class="value">' . $far->name . '</span>', $html);
        $this->assertStringNotContainsString('<span class="value">Main Branch</span>', $html);
        $this->assertStringNotContainsString('<span class="value">All Branches</span>', $html);

        $this->assertStringContainsString($farItem->name, $html);
        $this->assertStringNotContainsString($mainItem->name, $html);
        $this->assertStringNotContainsString($sharedItem->name, $html);
    }

    public function test_page_renders_with_no_crash_when_the_locked_branch_has_no_items(): void
    {
        $emptyBranch = $this->newBranch();
        $supervisor = $this->supervisorAt($emptyBranch->id);

        $html = $this->page($supervisor);

        $this->assertStringContainsString('No menu items yet', $html);
        // Not a plain substring check: "menu-branch-group-row" also appears
        // in this page's own <style> block regardless of data, so only the
        // rendered element itself is evidence a (non-existent) group heading
        // slipped through with zero items.
        $this->assertStringNotContainsString('<tr class="menu-branch-group-row"', $html);
    }
}
