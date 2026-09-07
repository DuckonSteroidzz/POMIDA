<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Branch scoping on the admin QR & Table Codes page (Task 3b/3c of this round).
 *
 * The staff-code issuer and table-clear endpoints already enforced own-branch
 * access server-side; this suite covers the two things that did not match
 * that yet:
 *   (b) the "Issue a Staff Code" branch dropdown offered every branch, most
 *       of which were guaranteed to 403 for a staff account.
 *   (c) qrTableCard() (the printable card generator) had no own-branch check
 *       at all — a staff account could generate a card for another store.
 *
 * DatabaseTransactions throughout: one test in an earlier draft of this file
 * flipped a real branch's is_active without it, which genuinely deactivated
 * Main Branch until caught and manually restored. Every test here that
 * touches branches.is_active must be transaction-wrapped.
 */
class QrGeneratorBranchScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function branchScopedStaff(): User
    {
        $staff = User::where('role', 'staff')->whereNotNull('branch_id')->first();

        if (!$staff) {
            $this->markTestSkipped('no branch-scoped staff account in this database');
        }

        return $staff;
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->firstOrFail();
    }

    /**
     * Just the two page-specific branch pickers (#branchSelect for the card
     * generator, #codeBranchSelect for the staff-code issuer), not the whole
     * page. The admin layout's own top-nav "Viewing: X" dashboard filter is a
     * third, unrelated <select> on this same page that deliberately DOES
     * include closed branches (labelled "(Closed)") so an admin can still
     * manage a closed branch's data — asserting against the whole page body
     * would collide with that dropdown's intentionally different behaviour.
     */
    private function pickerHtml(string $html): string
    {
        preg_match('/<select id="branchSelect".*?<\/select>/s', $html, $m1);
        preg_match('/<select id="codeBranchSelect".*?<\/select>/s', $html, $m2);

        return ($m1[0] ?? '') . ($m2[0] ?? '');
    }

    // ══════════ (b) Branch dropdown scoping ══════════

    public function test_staff_sees_only_their_own_branch_in_the_generator_page(): void
    {
        $staff = $this->branchScopedStaff();

        $res = $this->actingAs($staff, 'admin')->get('/admin/qr-generator');
        $res->assertOk();

        // Both #branchSelect (card generator) and #codeBranchSelect (staff
        // code issuer) are populated from the same server-side $branches list.
        $otherBranch = Branch::where('id', '!=', $staff->branch_id)->where('is_active', true)->firstOrFail();
        $pickers = $this->pickerHtml($res->getContent());

        $this->assertStringContainsString('value="' . $staff->branch_id . '"', $pickers);
        $this->assertStringNotContainsString('value="' . $otherBranch->id . '"', $pickers);
    }

    public function test_admin_sees_every_active_branch_in_the_generator_page(): void
    {
        $admin = $this->admin();
        $activeBranches = Branch::where('is_active', true)->get();

        $res = $this->actingAs($admin, 'admin')->get('/admin/qr-generator');
        $res->assertOk();
        $pickers = $this->pickerHtml($res->getContent());

        foreach ($activeBranches as $branch) {
            $this->assertStringContainsString('value="' . $branch->id . '"', $pickers);
        }
    }

    public function test_an_inactive_branch_is_never_offered_to_anyone(): void
    {
        $admin = $this->admin();
        $target = Branch::where('is_active', true)->firstOrFail();

        $target->is_active = false;
        $target->save();

        $res = $this->actingAs($admin, 'admin')->get('/admin/qr-generator');
        $res->assertOk();
        $pickers = $this->pickerHtml($res->getContent());

        $this->assertStringNotContainsString('value="' . $target->id . '"', $pickers);
    }

    // ══════════ (c) qrTableCard server-side scoping ══════════

    public function test_staff_can_generate_a_table_card_for_their_own_branch(): void
    {
        $staff = $this->branchScopedStaff();

        $this->actingAs($staff, 'admin')
            ->getJson('/admin/qr-generator/table-card?branch_id=' . $staff->branch_id . '&table_number=5')
            ->assertOk()
            ->assertJsonStructure(['branch_id', 'branch_name', 'branch_code', 'table_number', 'url']);
    }

    public function test_staff_cannot_generate_a_table_card_for_another_branch(): void
    {
        $staff = $this->branchScopedStaff();
        $otherBranch = Branch::where('id', '!=', $staff->branch_id)->where('is_active', true)->firstOrFail();

        $this->actingAs($staff, 'admin')
            ->getJson('/admin/qr-generator/table-card?branch_id=' . $otherBranch->id . '&table_number=5')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'You can only generate table cards for your own branch.']);
    }

    public function test_admin_can_generate_a_table_card_for_any_branch(): void
    {
        $admin = $this->admin();

        foreach (Branch::where('is_active', true)->get() as $branch) {
            $this->actingAs($admin, 'admin')
                ->getJson('/admin/qr-generator/table-card?branch_id=' . $branch->id . '&table_number=7')
                ->assertOk()
                ->assertJson(['branch_id' => $branch->id]);
        }
    }

    public function test_table_card_generation_requires_admin_session(): void
    {
        $this->getJson('/admin/qr-generator/table-card?branch_id=1&table_number=5')
            ->assertRedirect(route('admin.login'));
    }

    /** The URL the card encodes must be unaffected by the new own-branch guard. */
    public function test_the_generated_card_url_still_matches_what_the_scanner_parses(): void
    {
        $admin = $this->admin();
        $branch = Branch::where('is_active', true)->firstOrFail();

        $res = $this->actingAs($admin, 'admin')
            ->getJson('/admin/qr-generator/table-card?branch_id=' . $branch->id . '&table_number=9')
            ->assertOk();

        $this->assertStringContainsString('branch_id=' . $branch->id, $res->json('url'));
        $this->assertStringContainsString('table=9', $res->json('url'));
    }
}
