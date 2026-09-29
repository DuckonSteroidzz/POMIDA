<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Issue Code" must obey the same branch boundary as the page it sits on.
 *
 * WHAT WAS WRONG
 * --------------
 * POST /admin/vouchers/{id}/issue-code is the ONE voucher action open to Staff
 * as well as Supervisors (role:admin,staff,supervisor) — the green "Issue Code"
 * button on the Vouchers page, for handing a walk-in a bearer code.
 *
 * The Vouchers LISTING is branch-scoped: scopePromotionListing() narrows a
 * branch-locked viewer (User::BRANCH_LOCKED_ROLES = staff and supervisor) to
 * `branch_id IS NULL OR branch_id = theirs`, so they never SEE another branch's
 * promotion. Every other branch-owned promotion endpoint then re-checks that
 * server-side through promotionScopeRefusal() — updateVoucher, deleteVoucher and
 * all three ad routes do.
 *
 * issueVoucherCode() did not. It went straight to Voucher::findOrFail($id) and
 * minted. So the only thing keeping a Branch-1 staff member out of Branch-2's
 * promotions was that the button was not rendered for them — hidden-button
 * protection, which is no protection at all against a typed request.
 *
 * WHAT AN ATTACKER COULD ACTUALLY DO
 * ----------------------------------
 * A Branch-1 staff member (the lowest portal role) posts the numeric id of a
 * voucher belonging to Branch 2 — a promotion their own Vouchers page
 * deliberately hides from them — and gets back a valid, ready-to-spend bearer
 * claim code for it. Ids are sequential, so finding one is trial and error, and
 * the endpoint reports success with the code in the flash message.
 *
 * The harm, stated precisely: minting does NOT itself advance used_count —
 * mintForGuest() only inserts a user_vouchers row, and the counter moves at
 * REDEMPTION (VoucherClaims::redeem). What they get is a genuine, spendable
 * bearer code for a campaign another branch's manager owns and never authorised.
 * Redemption is branch-scoped, so it is spent at THAT branch — which is the
 * point: each one hands out a real discount and eats a share of that branch's
 * supply, charged to a promotion budget the issuer has no authority over.
 *
 * WHY promotionScopeRefusal() IS *NOT* THE FIX HERE
 * ------------------------------------------------
 * That helper refuses a branch-locked user for COMPANY-WIDE rows too
 * ("Company-wide vouchers can only be managed by the owner"), which is right for
 * editing a promotion and wrong for issuing a code off one: the listing
 * deliberately SHOWS globals to staff so they can quote them at the counter, and
 * a company-wide promo is exactly the kind a walk-in should be given. Reusing it
 * would have closed the hole by breaking the feature.
 *
 * The fix therefore asks the question the listing asks — "may this viewer see
 * this promotion?" — so own-branch and global both still work and only another
 * branch's is refused, as a 404 (the same convention AdminOrderAccess uses, so a
 * refusal leaks nothing about whether that id exists).
 */
class VoucherIssueCodeBranchScopeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'pomida_db_testing',
            DB::selectOne('select database() as d')->d,
            'Voucher scope tests must only ever run against pomida_db_testing.'
        );
    }

    private function branch(string $label): Branch
    {
        return Branch::create([
            'name'      => 'VIS ' . $label . ' ' . uniqid(),
            'code'      => 'VIS' . strtoupper(substr(uniqid(), -7)),
            'address'   => 'Voucher scope test address',
            'is_active' => true,
        ]);
    }

    private function actor(string $role, ?Branch $branch): User
    {
        return User::create([
            'name'              => 'VIS ' . $role . ' ' . uniqid(),
            'email'             => 'vis_' . $role . '_' . uniqid() . '@example.test',
            'password'          => 'Str0ng!Passw0rd',
            'role'              => $role,
            'branch_id'         => $branch?->id,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function voucher(?int $branchId): Voucher
    {
        return Voucher::create([
            'code'            => 'VIS' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'voucher issue-code scope test',
            'discount_type'   => 'fixed',
            'discount_value'  => 25,
            'branch_id'       => $branchId,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'expires_at'      => now()->addMonth(),
            'valid_from'      => null,
            'is_active'       => true,
            'points_required' => 0,
        ]);
    }

    private function claimCount(Voucher $voucher): int
    {
        return UserVoucher::where('voucher_id', $voucher->id)->count();
    }

    private function issue(User $actor, Voucher $voucher)
    {
        return $this->actingAs($actor, 'admin')
            ->from(route('admin.vouchers'))
            ->post(route('admin.vouchers.issue-code', ['id' => $voucher->id]));
    }

    // ══════════ The finding ══════════

    /**
     * THE HOLE. Before the fix this returned success and minted a claim.
     *
     * @dataProvider branchLockedRoles
     */
    public function test_a_branch_locked_user_cannot_issue_a_code_for_another_branchs_voucher(string $role): void
    {
        $mine = $this->branch('Mine');
        $theirs = $this->branch('Theirs');

        $actor = $this->actor($role, $mine);
        $foreign = $this->voucher($theirs->id);

        $response = $this->issue($actor, $foreign);

        $this->assertSame(
            0,
            $this->claimCount($foreign),
            "a {$role} at another branch minted a claim against branch " . $theirs->id . "'s voucher"
        );

        /*
         * Deliberately NOT asserting one particular status. Two refusal
         * conventions already live side by side in this app — AdminOrderAccess
         * answers an out-of-scope record id with 404 so the refusal cannot be
         * used to tell a real id from a missing one, while the promotion module's
         * promotionScopeRefusal() redirects with a flash sentence. Either is an
         * acceptable answer here; what must be true, whichever is chosen, is that
         * nothing was minted and no code came back.
         */
        $this->assertNull(session('issued_claim_code'), 'a claim code was flashed back to the caller');
        $this->assertNull(session('success'), 'the caller was told the issue succeeded');
        $this->assertNotSame(200, $response->getStatusCode(), 'the endpoint rendered a success page');
    }

    public static function branchLockedRoles(): array
    {
        return [
            'staff'      => ['staff'],
            'supervisor' => ['supervisor'],
        ];
    }

    // ══════════ The controls — the feature must keep working ══════════

    /**
     * Own-branch voucher: unchanged, must still mint.
     *
     * Without this the fix could be "refuse everybody" and the test above would
     * still pass.
     *
     * @dataProvider branchLockedRoles
     */
    public function test_a_branch_locked_user_can_still_issue_a_code_for_their_own_branchs_voucher(string $role): void
    {
        $mine = $this->branch('Mine');
        $actor = $this->actor($role, $mine);
        $own = $this->voucher($mine->id);

        $this->issue($actor, $own)->assertRedirect();

        $this->assertSame(1, $this->claimCount($own), "a {$role} could not issue a code for their own branch's voucher");
        $this->assertNotNull(session('issued_claim_code'), 'no code was flashed back, so the counter has nothing to read out');
    }

    /**
     * COMPANY-WIDE voucher: must still mint for a branch-locked user.
     *
     * This is the control that rules out the tempting-but-wrong fix of reusing
     * promotionScopeRefusal(), which refuses globals to anyone branch-locked.
     * The Vouchers listing shows globals to staff on purpose.
     *
     * @dataProvider branchLockedRoles
     */
    public function test_a_branch_locked_user_can_still_issue_a_code_for_a_company_wide_voucher(string $role): void
    {
        $mine = $this->branch('Mine');
        $actor = $this->actor($role, $mine);
        $global = $this->voucher(null);

        $this->issue($actor, $global)->assertRedirect();

        $this->assertSame(
            1,
            $this->claimCount($global),
            "a {$role} could not issue a code for a company-wide voucher — the listing shows them these on purpose"
        );
    }

    /** The owner is not branch-locked and reaches every branch, unchanged. */
    public function test_the_owner_can_still_issue_a_code_for_any_branchs_voucher(): void
    {
        $theirs = $this->branch('Theirs');
        $owner = $this->actor('admin', null);
        $foreign = $this->voucher($theirs->id);

        $this->issue($owner, $foreign)->assertRedirect();

        $this->assertSame(1, $this->claimCount($foreign), 'the owner was wrongly refused');
    }
}
