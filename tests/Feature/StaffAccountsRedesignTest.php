<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Staff Accounts (admin/users), Oct 2026 redesign — branch fix/staff-accounts-v2.
 *
 *  - Two cards: Create Portal Account (left) and All Portal Accounts (right),
 *    with a count badge, a search box, a six-column table whose actions live in
 *    a ⋮ menu, and a "Showing X–Y of N matching accounts" footer with a pager.
 *  - The "Viewing: <branch>" bar is FUNCTIONAL here: the owner's remembered
 *    choice (App\Support\ViewingBranch) narrows the rows, the count badge, the
 *    "Showing" line, the search and the pager together, and pre-selects the
 *    create form's Assigned Branch. A branch-locked supervisor is never
 *    widened by a crafted ?branch=.
 *  - Routes, validation, field names and each role's permissions are unchanged
 *    (their own suites — RolePermissionMatrixTest, StaffPasswordControlTest,
 *    SupervisorRoleFoundationTest — still pin them); this suite pins the page.
 *
 * Every row carries the SAR2 prefix and runs inside DatabaseTransactions
 * against pomida_db_testing. The testing DB already holds dozens of portal
 * accounts, so assertions are made inside a fresh branch (exact counts) or by a
 * SAR2-prefixed search.
 */
class StaffAccountsRedesignTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'SAR2';

    private const FRAGMENT = ['X-Staff-Fragment' => '1'];

    // ══════════════════ fixtures ══════════════════

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function branch(bool $active = true): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Branch ' . uniqid(),
            'code'      => 'SR2' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => $active,
        ]);
    }

    private function account(string $role, ?int $branchId, ?string $name = null, bool $active = true): User
    {
        return User::create([
            'name'      => $name ?? (self::PREFIX . ' ' . ucfirst($role) . ' ' . uniqid()),
            'email'     => strtolower(self::PREFIX) . '-' . $role . '-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => $role,
            'branch_id' => $branchId,
            'is_active' => $active,
        ]);
    }

    private function staffAt(Branch $branch, ?string $name = null, bool $active = true): User
    {
        return $this->account('staff', $branch->id, $name, $active);
    }

    private function supervisorAt(Branch $branch, ?string $name = null): User
    {
        return $this->account('supervisor', $branch->id, $name);
    }

    private function page(User $actor, string $query = '', array $headers = [])
    {
        // Headers go on THIS request only: withHeaders() would stay on the test
        // client and turn every later request into a fragment request too.
        return $this->actingAs($actor, 'admin')->get('/admin/users' . $query, $headers);
    }

    private function html(User $actor, string $query = ''): string
    {
        return $this->page($actor, $query)->assertOk()->getContent();
    }

    /** Whitespace-collapsed, so wrapped Blade markup can be matched as prose. */
    private function squash(string $html): string
    {
        return preg_replace('/\s+/', ' ', $html);
    }

    /** @return int[] the account ids drawn as table rows */
    private function rowIds(string $html): array
    {
        preg_match_all('/data-staff-row="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    private function scopeTotal(string $html): int
    {
        return preg_match('/data-scope-total="(\d+)"/', $html, $m) ? (int) $m[1] : -1;
    }

    private function matchingTotal(string $html): int
    {
        return preg_match('/data-total="(\d+)"/', $html, $m) ? (int) $m[1] : -1;
    }

    /** The count badge in the card head. */
    private function badge(string $html): ?int
    {
        return preg_match('/id="suCount"[^>]*>\s*(\d+)\s*</', $html, $m) ? (int) $m[1] : null;
    }

    /** The create form's markup, or ''. */
    private function createForm(string $html): string
    {
        return preg_match('/<form[^>]*id="suCreateForm".*?<\/form>/s', $html, $m) ? $m[0] : '';
    }

    /** The row's ⋮ menu markup, or ''. */
    private function menuFor(string $html, User $u): string
    {
        return preg_match('/<div class="su-menu" id="suMenu' . $u->id . '".*?<\/div>/s', $html, $m) ? $m[0] : '';
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. The page itself
    // ══════════════════════════════════════════════════════════════════

    public function test_the_create_card_matches_the_design(): void
    {
        $html = $this->html($this->admin());
        $flat = $this->squash($html);

        $this->assertStringContainsString('<p class="page-title">Staff Accounts</p>', $html);
        $this->assertStringContainsString('Create Portal Account', $html);
        $this->assertStringContainsString(
            'Only admins can create portal accounts as <strong>staff</strong> or <strong>supervisor</strong>'
                . ' — never another admin. Both roles are locked to the branch you assign below.',
            $flat
        );

        $form = $this->createForm($html);
        $this->assertNotSame('', $form, 'the create form is missing');

        // Wired to the existing create route, unchanged field names.
        $this->assertStringContainsString('action="' . route('admin.users.store') . '"', $form);
        foreach (['name', 'email', 'role', 'branch_id', 'password', 'password_confirmation'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $form, "field {$field}");
        }

        foreach (['Full Name', 'Email', 'Role', 'Assigned Branch', 'Password', 'Confirm Password'] as $label) {
            $this->assertMatchesRegularExpression('/<label[^>]*>\s*' . preg_quote($label, '/') . '\s*<\/label>/', $form, "label {$label}");
        }

        $this->assertStringContainsString('placeholder="Enter full name"', $form);
        $this->assertStringContainsString('placeholder="name@example.com"', $form);
        $this->assertStringContainsString('<option value="">-- Select branch --</option>', $form);

        // Eye toggles on both password boxes; the 8–20 rule stays on the inputs.
        $this->assertSame(2, substr_count($form, 'class="js-pw-toggle"'));
        $this->assertMatchesRegularExpression('/name="password"[^>]*minlength="8"[^>]*maxlength="20"/s', $form);
        $this->assertMatchesRegularExpression('/name="password_confirmation"[^>]*minlength="8"[^>]*maxlength="20"/s', $form);

        $this->assertStringContainsString('Password must be 8–20 characters. Use a combination that is difficult to guess.', $form);
        $this->assertMatchesRegularExpression('/<button type="submit" class="su-submit">.*?Create Account/s', $form);
    }

    public function test_the_owner_may_pick_staff_or_supervisor_but_never_admin(): void
    {
        $form = $this->createForm($this->html($this->admin()));

        preg_match('/<select name="role".*?<\/select>/s', $form, $m);
        $this->assertStringContainsString('value="staff"', $m[0]);
        $this->assertStringContainsString('value="supervisor"', $m[0]);
        $this->assertStringNotContainsString('value="admin"', $m[0]);
    }

    public function test_the_list_card_has_a_count_badge_a_search_box_and_the_six_columns(): void
    {
        $branch = $this->branch();
        $this->staffAt($branch);

        $html = $this->html($this->admin(), '?branch=' . $branch->id);

        $this->assertStringContainsString('All Portal Accounts', $html);
        $this->assertSame(1, $this->badge($html));
        $this->assertStringContainsString('placeholder="Search staff accounts..."', $html);
        $this->assertMatchesRegularExpression('/<input type="search" name="q"/', $html);

        foreach (['Name', 'Email', 'Role', 'Branch', 'Status', 'Actions'] as $column) {
            $this->assertMatchesRegularExpression('/<th[^>]*>' . $column . '<\/th>/', $html, "column {$column}");
        }
    }

    public function test_a_row_shows_name_email_role_branch_status_and_a_kebab(): void
    {
        $branch = $this->branch();
        $sup = $this->supervisorAt($branch);
        $off = $this->staffAt($branch, self::PREFIX . ' Off Duty', false);

        $html = $this->html($this->admin(), '?branch=' . $branch->id);

        $this->assertMatchesRegularExpression('/class="su-name"[^>]*>' . preg_quote($sup->name, '/') . '</', $html);
        $this->assertStringContainsString($sup->email, $html);
        $this->assertMatchesRegularExpression('/<span class="su-badge is-supervisor">Supervisor<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span class="su-badge is-staff">Staff<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span class="su-badge is-active">Active<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span class="su-badge is-disabled">Disabled<\/span>/', $html);
        $this->assertStringContainsString($branch->name, $html);

        // A bordered ⋮ button per row, owning its menu.
        foreach ([$sup, $off] as $row) {
            $this->assertMatchesRegularExpression(
                '/<button type="button" class="su-kebab" data-su-menu-btn[^>]*aria-controls="suMenu' . $row->id . '"/',
                $html
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. The ⋮ menu: the actions that already existed, same permissions
    // ══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string}> */
    public static function managers(): array
    {
        return ['owner' => ['admin'], 'supervisor' => ['supervisor']];
    }

    /**
     * @dataProvider managers
     */
    public function test_the_menu_offers_edit_set_password_activate_and_delete_to_both_roles(string $role): void
    {
        $branch = $this->branch();
        $staff = $this->staffAt($branch);
        $actor = $role === 'admin' ? $this->admin() : $this->supervisorAt($branch);

        $html = $this->html($actor, '?branch=' . $branch->id);
        $menu = $this->menuFor($html, $staff);

        $this->assertNotSame('', $menu, 'the row has no ⋮ menu');
        // Closed until opened: `hidden` (display:none), so it cannot cover another row.
        $this->assertStringContainsString('role="menu" hidden', $menu);

        // Edit and Set Password open the SAME inline rows as before.
        $this->assertMatchesRegularExpression('/js-toggle-pw-row"\s+data-target="edit-row-' . $staff->id . '"/', $menu);
        $this->assertMatchesRegularExpression('/js-toggle-pw-row"\s+data-target="pw-row-' . $staff->id . '"/', $menu);
        $this->assertStringContainsString('id="edit-row-' . $staff->id . '"', $html);
        $this->assertStringContainsString('id="pw-row-' . $staff->id . '"', $html);

        // Deactivate and Delete post to the unchanged routes.
        $this->assertStringContainsString('action="' . route('admin.users.toggle', $staff->id) . '"', $menu);
        $this->assertStringContainsString('Deactivate', $menu);
        $this->assertStringContainsString('action="' . route('admin.users.destroy', $staff->id) . '"', $menu);
        $this->assertStringContainsString('name="_method" value="DELETE"', $menu);

        // Exactly four actions: nothing was added, nothing dropped.
        $this->assertSame(4, substr_count($menu, 'role="menuitem"'));
    }

    public function test_a_disabled_account_offers_reactivate_instead_of_deactivate(): void
    {
        $branch = $this->branch();
        $off = $this->staffAt($branch, null, false);

        $menu = $this->menuFor($this->html($this->admin(), '?branch=' . $branch->id), $off);

        $this->assertStringContainsString('Reactivate', $menu);
        $this->assertStringNotContainsString('Deactivate', $menu);
    }

    public function test_the_current_inline_edit_form_is_unchanged(): void
    {
        $branch = $this->branch();
        $staff = $this->staffAt($branch);

        $html = $this->html($this->admin(), '?branch=' . $branch->id);

        preg_match('/<tr id="edit-row-' . $staff->id . '".*?<\/tr>/s', $html, $m);
        $row = $m[0] ?? '';

        $this->assertStringContainsString(' hidden', $row, 'the edit row starts closed');
        $this->assertStringContainsString('action="' . route('admin.users.update', $staff->id) . '"', $row);
        foreach (['name', 'email', 'contact_number', 'role', 'branch_id'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $row, "edit field {$field}");
        }
        $this->assertStringContainsString('Save changes', $row);
    }

    public function test_confirmation_text_is_escaped_into_data_confirm_not_a_script_string(): void
    {
        $branch = $this->branch();
        $this->staffAt($branch, self::PREFIX . " O'Brien \"Q\"');alert(1);//");

        $html = $this->html($this->admin(), '?branch=' . $branch->id);

        $this->assertStringContainsString('onsubmit="return confirm(this.dataset.confirm)"', $html);
        // The name only ever appears HTML-escaped, never inside a confirm('…') literal.
        $this->assertStringNotContainsString("confirm('Permanently delete the account for", $html);
        $this->assertStringNotContainsString("O'Brien", $html, 'a raw quote from the name reached the markup');
        $this->assertStringContainsString('O&#039;Brien', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. The Viewing bar is functional here
    // ══════════════════════════════════════════════════════════════════

    public function test_the_owner_sees_the_viewing_bar_with_its_branch_picker(): void
    {
        $html = $this->html($this->admin());

        $this->assertStringContainsString('Viewing:', $html);
        $this->assertStringContainsString('data-pc-branch-select', $html);
        $this->assertStringContainsString('All Branches', $html);
        // The page acts on the bar itself instead of leaving it to reload.
        $this->assertStringContainsString('window.pcBranchSelectHandler', $html);
    }

    public function test_all_branches_lists_every_portal_account_and_a_branch_narrows_it(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $inA = $this->staffAt($a);
        $supA = $this->supervisorAt($a);
        $inB = $this->staffAt($b);

        $owner = $this->admin();

        // All Branches: both branches' accounts turn up (found by prefix).
        $all = $this->html($owner, '?branch=all&q=' . self::PREFIX);
        $this->assertEqualsCanonicalizing([$inA->id, $supA->id, $inB->id], array_values(array_intersect($this->rowIds($all), [$inA->id, $supA->id, $inB->id])));
        $this->assertSame(
            User::whereIn('role', User::ADMIN_MANAGEABLE_ROLES)->count(),
            $this->scopeTotal($all),
            'All Branches must be every manageable portal account'
        );

        // One branch: only its accounts, and the badge is that branch's total.
        $onlyA = $this->html($owner, '?branch=' . $a->id);
        $this->assertEqualsCanonicalizing([$inA->id, $supA->id], $this->rowIds($onlyA));
        $this->assertSame(2, $this->scopeTotal($onlyA));
        $this->assertSame(2, $this->badge($onlyA));
        $this->assertSame(2, $this->matchingTotal($onlyA));
        $this->assertStringContainsString('Showing 1–2 of 2 matching accounts', $onlyA);
        $this->assertStringNotContainsString($inB->email, $onlyA);

        $onlyB = $this->html($owner, '?branch=' . $b->id);
        $this->assertSame([$inB->id], $this->rowIds($onlyB));
        $this->assertSame(1, $this->badge($onlyB));
        $this->assertStringContainsString('Showing 1–1 of 1 matching account', $onlyB);
        $this->assertStringNotContainsString('matching accounts', $onlyB);
    }

    public function test_search_follows_the_viewing_branch(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $inA = $this->staffAt($a, self::PREFIX . ' Zelda Alpha');
        $inB = $this->staffAt($b, self::PREFIX . ' Zelda Bravo');

        $owner = $this->admin();

        // Both match "Zelda" across All Branches…
        $all = $this->html($owner, '?branch=all&q=' . urlencode(self::PREFIX . ' Zelda'));
        $this->assertEqualsCanonicalizing([$inA->id, $inB->id], $this->rowIds($all));

        // …but viewing one branch, only that branch's match remains.
        $inBranchA = $this->html($owner, '?branch=' . $a->id . '&q=Zelda');
        $this->assertSame([$inA->id], $this->rowIds($inBranchA));
        $this->assertStringNotContainsString($inB->email, $inBranchA);

        // The badge stays the branch's total while a search narrows the rows.
        $this->assertSame(1, $this->scopeTotal($inBranchA));

        // Searching for the other branch's account finds nothing here.
        $miss = $this->html($owner, '?branch=' . $a->id . '&q=Bravo');
        $this->assertSame([], $this->rowIds($miss));
        $this->assertSame(1, $this->scopeTotal($miss), 'the badge is the branch total, not the match count');
        $this->assertSame(0, $this->matchingTotal($miss));
        $this->assertStringContainsString('No accounts match', $miss);
        $this->assertStringNotContainsString('<span class="su-pager-info">', $miss, 'no "Showing X–Y" line when nothing matches');
    }

    public function test_search_matches_name_email_role_and_branch_name(): void
    {
        $a = $this->branch();
        $sup = $this->supervisorAt($a, self::PREFIX . ' Quincy');
        $staff = $this->staffAt($a, self::PREFIX . ' Rosalind');
        $owner = $this->admin();

        $this->assertSame([$staff->id], $this->rowIds($this->html($owner, '?branch=' . $a->id . '&q=Rosalind')), 'by name');
        $this->assertSame([$staff->id], $this->rowIds($this->html($owner, '?branch=' . $a->id . '&q=' . urlencode($staff->email))), 'by email');
        $this->assertSame([$sup->id], $this->rowIds($this->html($owner, '?branch=' . $a->id . '&q=supervisor')), 'by role');
        $this->assertEqualsCanonicalizing(
            [$sup->id, $staff->id],
            $this->rowIds($this->html($owner, '?branch=all&q=' . urlencode($a->name))),
            'by branch name'
        );
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $a = $this->branch();
        $pct = $this->staffAt($a, self::PREFIX . ' 100% Real');
        $this->staffAt($a, self::PREFIX . ' 100X Real');
        $under = $this->staffAt($a, self::PREFIX . ' a_b');
        $this->staffAt($a, self::PREFIX . ' aXb');
        $owner = $this->admin();

        $this->assertSame([$pct->id], $this->rowIds($this->html($owner, '?branch=' . $a->id . '&q=' . urlencode('100%'))));
        $this->assertSame([$under->id], $this->rowIds($this->html($owner, '?branch=' . $a->id . '&q=' . urlencode('a_b'))));
    }

    public function test_the_empty_states_name_what_is_empty(): void
    {
        $empty = $this->branch();
        $owner = $this->admin();

        $html = $this->html($owner, '?branch=' . $empty->id);
        $this->assertSame([], $this->rowIds($html));
        $this->assertSame(0, $this->badge($html));
        $this->assertStringContainsString('No staff or supervisor accounts are assigned to ' . $empty->name . ' yet.', $html);
        $this->assertStringNotContainsString('id="staffTable"', $html);
    }

    public function test_a_closed_branch_can_still_be_viewed(): void
    {
        $closed = $this->branch(active: false);
        $staff = $this->staffAt($closed);

        $html = $this->html($this->admin(), '?branch=' . $closed->id);

        $this->assertSame([$staff->id], $this->rowIds($html));
    }

    public function test_the_choice_is_remembered_in_the_shared_session_key_and_survives_a_plain_reload(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $inA = $this->staffAt($a);
        $inB = $this->staffAt($b);

        $this->actingAs($this->admin(), 'admin');
        $this->get('/admin/users?branch=' . $a->id)->assertOk();
        $this->assertSame($a->id, session('selected_branch_id'));

        $reload = $this->get('/admin/users')->assertOk()->content();
        $this->assertSame([$inA->id], $this->rowIds($reload));
        $this->assertStringNotContainsString($inB->email, $reload);

        $this->get('/admin/users?branch=all')->assertOk();
        $this->assertSame('all', session('selected_branch_id'));
    }

    public function test_a_choice_made_on_another_admin_page_applies_here(): void
    {
        $a = $this->branch();
        $inA = $this->staffAt($a);
        $inB = $this->staffAt($this->branch());

        $this->actingAs($this->admin(), 'admin');
        $this->get('/admin/branches/select/' . $a->id);

        $html = $this->get('/admin/users')->assertOk()->content();
        $this->assertSame([$inA->id], $this->rowIds($html));
        $this->assertStringNotContainsString($inB->email, $html);
    }

    public function test_a_nonexistent_or_junk_branch_is_ignored_and_the_remembered_choice_stands(): void
    {
        $a = $this->branch();
        $this->actingAs($this->admin(), 'admin');
        $this->get('/admin/users?branch=' . $a->id)->assertOk();

        foreach (['999999', 'abc', '1;DROP', '-1'] as $junk) {
            $this->get('/admin/users?branch=' . urlencode($junk))->assertOk();
            $this->assertSame($a->id, session('selected_branch_id'), "'{$junk}' must not change the choice");
        }

        $this->get('/admin/users?branch[]=1')->assertOk();
        $this->assertSame($a->id, session('selected_branch_id'));
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. Supervisors stay locked to their own branch
    // ══════════════════════════════════════════════════════════════════

    public function test_a_supervisor_sees_only_their_branchs_staff_and_cannot_widen_it_with_a_crafted_branch(): void
    {
        $mine = $this->branch();
        $other = $this->branch();
        $myStaff = $this->staffAt($mine);
        $peer = $this->supervisorAt($mine);
        $theirStaff = $this->staffAt($other);
        $viewer = $this->supervisorAt($mine);

        foreach (['', '?branch=' . $other->id, '?branch=all', '?branch=' . $mine->id] as $query) {
            $html = $this->html($viewer, $query);

            $this->assertSame([$myStaff->id], $this->rowIds($html), "{$query} must show exactly their own branch's staff");
            $this->assertStringNotContainsString($theirStaff->email, $html);
            $this->assertStringNotContainsString($peer->email, $html, 'a peer supervisor is never listed');
            $this->assertSame(1, $this->badge($html));
            $this->assertNull(session('selected_branch_id'), 'a locked account never writes the choice');
        }
    }

    public function test_a_supervisors_search_cannot_reach_another_branch(): void
    {
        $mine = $this->branch();
        $other = $this->branch();
        $theirStaff = $this->staffAt($other, self::PREFIX . ' Faraway');
        $viewer = $this->supervisorAt($mine);

        $html = $this->html($viewer, '?q=Faraway&branch=' . $other->id);

        $this->assertSame([], $this->rowIds($html));
        $this->assertStringNotContainsString($theirStaff->email, $html);
    }

    public function test_a_supervisor_has_no_branch_picker_and_the_bar_names_their_branch(): void
    {
        $mine = $this->branch();
        $this->branch(); // a second branch that must not be offered
        $html = $this->html($this->supervisorAt($mine));

        $this->assertStringContainsString('Viewing:', $html);
        $this->assertStringNotContainsString('data-pc-branch-select', $html);
        $this->assertDoesNotMatchRegularExpression('/<input type="hidden" name="branch"/', $html, 'a supervisor has no choice to carry');
        $this->assertMatchesRegularExpression('/<span class="value">\s*' . preg_quote($mine->name, '/') . '\s*<\/span>/', $html);
    }

    public function test_a_supervisors_create_form_offers_staff_and_their_own_branch_only_preselected(): void
    {
        $mine = $this->branch();
        $other = $this->branch();

        $form = $this->createForm($this->html($this->supervisorAt($mine)));

        preg_match('/<select name="role".*?<\/select>/s', $form, $r);
        $this->assertStringContainsString('value="staff"', $r[0]);
        $this->assertStringNotContainsString('value="supervisor"', $r[0]);

        $this->assertStringContainsString('<option value="' . $mine->id . '" selected>', $form);
        $this->assertStringNotContainsString('value="' . $other->id . '"', $form);
    }

    public function test_staff_cannot_open_the_page(): void
    {
        $branch = $this->branch();
        $status = $this->actingAs($this->staffAt($branch), 'admin')->get('/admin/users')->getStatusCode();

        $this->assertNotSame(200, $status, 'staff reached the Staff Accounts page');
    }

    // ══════════════════════════════════════════════════════════════════
    // 5. The create form follows the Viewing branch
    // ══════════════════════════════════════════════════════════════════

    public function test_assigned_branch_preselects_the_viewed_branch_and_stays_changeable(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $owner = $this->admin();

        $viewing = $this->createForm($this->html($owner, '?branch=' . $a->id));
        $this->assertStringContainsString('<option value="' . $a->id . '" selected>', $viewing);
        $this->assertStringNotContainsString('<option value="' . $b->id . '" selected>', $viewing);
        // Still a free choice: every open branch is offered.
        $this->assertStringContainsString('<option value="' . $b->id . '" ', $viewing);

        // All Branches: nothing pre-selected — the placeholder stands.
        $all = $this->createForm($this->html($owner, '?branch=all'));
        $this->assertDoesNotMatchRegularExpression('/<option value="\d+" selected>/', $all);
        $this->assertStringContainsString('<option value="">-- Select branch --</option>', $all);
    }

    public function test_a_closed_viewed_branch_is_still_preselectable(): void
    {
        $closed = $this->branch(active: false);

        $form = $this->createForm($this->html($this->admin(), '?branch=' . $closed->id));

        $this->assertStringContainsString('<option value="' . $closed->id . '" selected>', $form);
    }

    public function test_the_create_form_still_creates_an_account_through_the_existing_route(): void
    {
        $branch = $this->branch();
        $email = strtolower(self::PREFIX) . '-new-' . uniqid() . '@example.test';

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.users.store'), [
                'name'                  => self::PREFIX . ' Newly Hired',
                'email'                 => $email,
                'role'                  => 'supervisor',
                'branch_id'             => $branch->id,
                'password'              => 'Gg7&gggggg',
                'password_confirmation' => 'Gg7&gggggg',
                '_form'                 => 'create',
            ])
            ->assertRedirect(route('admin.users'));

        $created = User::where('email', $email)->firstOrFail();
        $this->assertSame('supervisor', $created->role);
        $this->assertSame($branch->id, (int) $created->branch_id);
    }

    public function test_server_errors_from_the_create_form_appear_under_their_fields(): void
    {
        $branch = $this->branch();
        $taken = $this->staffAt($branch);

        $this->actingAs($this->admin(), 'admin');
        $this->from('/admin/users')
            ->post(route('admin.users.store'), [
                'name'                  => self::PREFIX . ' Retry Me',
                'email'                 => $taken->email,            // already in use
                'role'                  => 'staff',
                'branch_id'             => $branch->id,
                'password'              => 'weak',                   // fails the policy
                'password_confirmation' => 'weak',
                '_form'                 => 'create',
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHasErrors(['email', 'password']);

        $html = $this->get('/admin/users')->assertOk()->content();
        $form = $this->createForm($html);

        // Each message sits under its own field, inside the create form…
        $this->assertMatchesRegularExpression('/name="email".*?<p class="su-err" id="su-err-email">[^<]+<\/p>/s', $form);
        $this->assertMatchesRegularExpression('/name="password_confirmation".*?<p class="su-err" id="su-err-password">[^<]+<\/p>/s', $form);
        $this->assertStringContainsString('aria-invalid="true"', $form);
        // …and the page-level banner is not used for them.
        $this->assertStringNotContainsString('class="su-banner"', $html);

        // What was typed survives, and the branch is the one submitted.
        $this->assertStringContainsString('value="' . self::PREFIX . ' Retry Me"', $form);
        $this->assertStringContainsString('<option value="' . $branch->id . '" selected>', $form);
        $this->assertStringNotContainsString('weak', $form, 'a password must never be echoed back');
    }

    public function test_errors_from_the_per_row_edit_form_still_surface_in_the_banner_not_under_create_fields(): void
    {
        $branch = $this->branch();
        $staff = $this->staffAt($branch);

        $this->actingAs($this->admin(), 'admin');
        $this->from('/admin/users')
            ->put(route('admin.users.update', $staff->id), [
                'name'      => 'Renamed',
                'email'     => 'not-an-email',
                'branch_id' => $branch->id,
                'role'      => 'staff',
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHasErrors('email');

        $html = $this->get('/admin/users')->assertOk()->content();

        $this->assertStringContainsString('class="su-banner"', $html);
        $this->assertStringNotContainsString('class="su-err"', $html);
        // And the edit's input is not poured into the create form.
        $this->assertStringNotContainsString('value="not-an-email"', $this->createForm($html));
    }

    // ══════════════════════════════════════════════════════════════════
    // 6. Footer and pager
    // ══════════════════════════════════════════════════════════════════

    public function test_the_footer_pages_ten_at_a_time_and_names_the_range(): void
    {
        $branch = $this->branch();
        $ids = [];
        foreach (range(1, 23) as $n) {
            $ids[] = $this->staffAt($branch, self::PREFIX . ' Person ' . str_pad((string) $n, 2, '0', STR_PAD_LEFT))->id;
        }
        $owner = $this->admin();

        $p1 = $this->html($owner, '?branch=' . $branch->id);
        $this->assertCount(10, $this->rowIds($p1));
        $this->assertStringContainsString('Showing 1–10 of 23 matching accounts', $p1);
        $this->assertSame(23, $this->badge($p1));

        $p2 = $this->html($owner, '?branch=' . $branch->id . '&page=2');
        $this->assertCount(10, $this->rowIds($p2));
        $this->assertStringContainsString('Showing 11–20 of 23 matching accounts', $p2);

        $p3 = $this->html($owner, '?branch=' . $branch->id . '&page=3');
        $this->assertCount(3, $this->rowIds($p3));
        $this->assertStringContainsString('Showing 21–23 of 23 matching accounts', $p3);

        // Pages are disjoint and together cover every account.
        $seen = array_merge($this->rowIds($p1), $this->rowIds($p2), $this->rowIds($p3));
        $this->assertCount(23, array_unique($seen));
        $this->assertEqualsCanonicalizing($ids, $seen);
    }

    public function test_a_page_past_the_end_lands_on_the_last_page(): void
    {
        $branch = $this->branch();
        foreach (range(1, 12) as $n) {
            $this->staffAt($branch);
        }

        $html = $this->html($this->admin(), '?branch=' . $branch->id . '&page=99');

        $this->assertCount(2, $this->rowIds($html));
        $this->assertStringContainsString('Showing 11–12 of 12 matching accounts', $html);
    }

    public function test_pager_links_are_numbered_and_carry_the_search_and_the_branch(): void
    {
        $branch = $this->branch();
        foreach (range(1, 25) as $n) {
            $this->staffAt($branch, self::PREFIX . ' Pager ' . $n);
        }

        $html = $this->html($this->admin(), '?branch=' . $branch->id . '&q=Pager');

        preg_match_all('/<a href="([^"]+)"\s+class="su-page-btn[^"]*"/', $html, $links);
        $this->assertNotEmpty($links[1]);

        $numbered = array_filter($links[1], fn ($href) => str_contains($href, 'page='));
        $this->assertNotEmpty($numbered);
        foreach ($numbered as $href) {
            $href = html_entity_decode($href);
            $this->assertStringContainsString('branch=' . $branch->id, $href, 'a pager link drops the Viewing branch');
            $this->assertStringContainsString('q=Pager', $href, 'a pager link drops the search');
        }

        $this->assertMatchesRegularExpression('/class="su-page-btn is-active"[^>]*aria-current="page"\s*>1</', $html);
        $this->assertMatchesRegularExpression('/aria-label="Previous page"/', $html);
        $this->assertMatchesRegularExpression('/aria-label="Next page"/', $html);
    }

    public function test_a_single_account_reads_account_not_accounts(): void
    {
        $branch = $this->branch();
        $this->staffAt($branch);

        $html = $this->html($this->admin(), '?branch=' . $branch->id);

        $this->assertStringContainsString('Showing 1–1 of 1 matching account', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 7. The background-refresh fragment
    // ══════════════════════════════════════════════════════════════════

    public function test_the_fragment_is_just_the_results_region_and_is_never_cached(): void
    {
        $branch = $this->branch();
        $staff = $this->staffAt($branch);

        $response = $this->page($this->admin(), '?branch=' . $branch->id, self::FRAGMENT)->assertOk();
        $html = ltrim($response->getContent());

        $this->assertStringStartsWith('<div id="suResults"', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('id="suCreateForm"', $html, 'the create form must never be replaced');
        $this->assertSame([$staff->id], $this->rowIds($html));
        $this->assertSame(1, $this->scopeTotal($html));
        $this->assertStringContainsString('X-Staff-Fragment', $response->headers->get('Vary'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        // Without the header the same address is the whole page.
        $full = $this->html($this->admin(), '?branch=' . $branch->id);
        $this->assertStringContainsString('<html', $full);
        $this->assertStringContainsString('id="suCreateForm"', $full);
    }

    public function test_the_fragment_obeys_the_same_scope_rules_as_the_page(): void
    {
        $mine = $this->branch();
        $other = $this->branch();
        $myStaff = $this->staffAt($mine);
        $this->staffAt($other);
        $viewer = $this->supervisorAt($mine);

        $html = $this->page($viewer, '?branch=' . $other->id, self::FRAGMENT)->assertOk()->getContent();

        $this->assertSame([$myStaff->id], $this->rowIds($html));
    }

    public function test_the_fragment_is_refused_to_staff_like_the_page(): void
    {
        $branch = $this->branch();

        $status = $this->page($this->staffAt($branch), '', self::FRAGMENT)->getStatusCode();

        $this->assertNotSame(200, $status);
    }

    public function test_the_page_ships_the_background_refresh_wiring(): void
    {
        $html = $this->html($this->admin());

        $this->assertStringContainsString("'X-Staff-Fragment': '1'", $html);
        $this->assertStringContainsString('id="suResults"', $html);
        // Esc and outside click close the ⋮ menu.
        $this->assertStringContainsString("e.key === 'Escape'", $html);
        $this->assertStringContainsString("closest('.su-menu')", $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 8. Phone layout and CSS contracts
    // ══════════════════════════════════════════════════════════════════

    public function test_the_phone_breakpoint_turns_the_table_into_cards_at_640px(): void
    {
        $html = $this->html($this->admin());

        $this->assertStringContainsString('@media (max-width: 640px)', $html);
        // The table's own [hidden] rows must stay hidden once rows are display:block.
        $this->assertStringContainsString('#staffTable tr[hidden] { display: none; }', $html);
        // Tracks that can shrink — a bare 1fr would scroll the page sideways.
        $this->assertStringContainsString('grid-template-columns: minmax(0, 4fr) minmax(0, 7fr);', $html);
        $this->assertStringContainsString('.su-cols { grid-template-columns: minmax(0, 1fr); }', $html);
        // A closed menu is display:none.
        $this->assertStringContainsString('.su-menu[hidden] { display: none; }', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 9. Efficiency
    // ══════════════════════════════════════════════════════════════════

    public function test_the_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $few = $this->branch();
        $many = $this->branch();
        foreach (range(1, 2) as $n) {
            $this->staffAt($few);
        }
        foreach (range(1, 10) as $n) {
            $this->staffAt($many);
        }
        $owner = $this->admin();

        $count = function (string $query) use ($owner): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->html($owner, $query);

            return $queries;
        };

        // Warm anything cached once per process, then compare like with like.
        $count('?branch=' . $few->id);
        $a = $count('?branch=' . $few->id);
        $b = $count('?branch=' . $many->id);

        $this->assertSame($a, $b, 'the page runs more queries as rows are added (an N+1)');
    }
}
