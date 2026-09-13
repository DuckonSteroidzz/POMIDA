<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Branch;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SUPERVISOR PROMOTION SCOPE — vouchers and ads belong to a branch (Sept 2026).
 *
 * WHAT THIS IS THE RECORD OF
 * --------------------------
 * Phase 2 (RolePermissionMatrixTest) implemented "Create/Edit/Activate
 * Vouchers" and "Manage Advertisements" as plain Y | Y | N and said, in
 * routes/web.php, that no own-branch narrowing was possible: neither table had
 * a branch_id, so a promotion was global BY CONSTRUCTION. That was a true
 * statement about the SCHEMA and a false one about the intent — a branch
 * manager authoring or withdrawing a company-wide promotion was never wanted.
 *
 * So both rows are now LIMITED for a supervisor, on the same footing as
 * "Delete Menu Items":
 *
 *      Owner       -> any voucher/ad. May author a GLOBAL one (branch_id NULL)
 *                     or scope one to a branch. Nothing about their existing
 *                     reach is narrowed.
 *      Supervisor  -> their OWN branch only. New ones are auto-scoped to their
 *                     branch with no field to forge; editing, activating,
 *                     deactivating (and, for ads, deleting) a GLOBAL one or
 *                     ANOTHER BRANCH's is refused.
 *      Staff       -> unchanged. Vouchers stay read-only at the counter, ads
 *                     stay off-limits entirely. Phase 2 owns those rows.
 *
 * WHAT IS NOT TESTED HERE
 * -----------------------
 *  - The role gate itself (who reaches the endpoint at all). Phase 2 owns it.
 *  - "Delete Vouchers = Y | N | N", which this pass deliberately did NOT touch.
 *    Phase 2's test_a_denied_manager_voucher_delete_leaves_the_voucher is the
 *    regression check for it and still passes; one assertion is repeated here
 *    only because "unchanged" is itself a claim this pass has to make good on.
 *  - Customer-facing redemption/checkout. Out of scope for this pass by
 *    instruction, and untouched by it.
 *
 * DATA HYGIENE
 * ------------
 * Everything is prefixed SUPSCOPE and runs inside DatabaseTransactions, so
 * nothing survives the run and nothing needs a high-water mark: no row created
 * here is ever committed. Crucially, NO PRE-EXISTING ROW IS SELECTED FOR
 * MUTATION — every voucher, ad, branch and account acted on is one this file
 * minted. The owner's real live vouchers (and inventory id 500) are untouched
 * by construction, which matters more than usual in a pass that changes the
 * vouchers schema. The one test that reads live rows,
 * test_pre_existing_promotions_are_global_after_the_migration, only READS.
 */
class SupervisorPromotionScopeTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'SUPSCOPE';

    private const HOME_BRANCH = 1;

    // ══════════════════════ fixtures ══════════════════════

    private function accountAt(string $role, ?int $branchId): User
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
        return $this->accountAt('admin', null);
    }

    private function manager(?int $branchId = self::HOME_BRANCH): User
    {
        return $this->accountAt('supervisor', $branchId);
    }

    private function otherBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'SPX' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function voucherIn(?int $branchId): Voucher
    {
        return Voucher::create([
            'branch_id'      => $branchId,
            'code'           => self::PREFIX . strtoupper(substr(uniqid(), -6)),
            'description'    => self::PREFIX . ' fixture voucher',
            'discount_type'  => 'fixed',
            'discount_value' => 10,
            'max_uses'       => 5,
            'is_active'      => true,
        ]);
    }

    private function adIn(?int $branchId): Ad
    {
        return Ad::create([
            'branch_id'   => $branchId,
            'title'       => self::PREFIX . ' Campaign ' . uniqid(),
            'description' => self::PREFIX . ' fixture ad',
            'placement'   => 'game',
            'is_active'   => true,
        ]);
    }

    /** A valid voucher payload, so a refusal can never be mistaken for a 422. */
    private function voucherPayload(array $overrides = []): array
    {
        return array_merge([
            'code'           => self::PREFIX . 'EDIT' . strtoupper(substr(uniqid(), -4)),
            'description'    => self::PREFIX . ' edited',
            'discount_type'  => 'fixed',
            'discount_value' => 25,
            'max_uses'       => 9,
            'minimum_order'  => 0,
        ], $overrides);
    }

    private function adPayload(array $overrides = []): array
    {
        return array_merge([
            'title'       => self::PREFIX . ' Edited ' . uniqid(),
            'description' => self::PREFIX . ' edited',
            'placement'   => 'menu',
        ], $overrides);
    }

    /**
     * The targets a supervisor must never be able to write to, as a provider.
     * 'global' is the one that is easy to get wrong: NULL compares unequal to
     * an integer only by luck, and the deny-by-default sentinel 0 that
     * lockedBranchId() hands a branchless supervisor would compare EQUAL to
     * `(int) null` if the NULL case were left implicit.
     */
    public static function forbiddenScopes(): array
    {
        return [
            'a global (all-branches) promotion' => ['global'],
            'another branch\'s promotion'       => ['other'],
        ];
    }

    private function forbiddenBranchId(string $kind): ?int
    {
        return $kind === 'global' ? null : $this->otherBranch()->id;
    }

    // ═════════════ 1. CREATION IS AUTO-SCOPED ═════════════

    /** A supervisor's new voucher lands in their own branch, with no field. */
    public function test_a_supervisor_voucher_is_auto_scoped_to_their_branch(): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $code    = self::PREFIX . 'NEW' . strtoupper(substr(uniqid(), -5));

        $this->actingAs($manager, 'admin')
            ->post(route('admin.vouchers.store'), $this->voucherPayload(['code' => $code]))
            ->assertRedirect(route('admin.vouchers'));

        $voucher = Voucher::where('code', $code)->first();

        $this->assertNotNull($voucher, 'A supervisor could not create a voucher at all.');
        $this->assertSame(
            self::HOME_BRANCH,
            (int) $voucher->branch_id,
            'A supervisor-created voucher was not scoped to their own branch.'
        );
    }

    /** Same for an ad. */
    public function test_a_supervisor_ad_is_auto_scoped_to_their_branch(): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $title   = self::PREFIX . ' Fresh ' . uniqid();

        $this->actingAs($manager, 'admin')
            ->post(route('admin.ads.store'), $this->adPayload(['title' => $title]))
            ->assertRedirect(route('admin.ads'));

        $ad = Ad::where('title', $title)->first();

        $this->assertNotNull($ad, 'A supervisor could not create an ad at all.');
        $this->assertSame(
            self::HOME_BRANCH,
            (int) $ad->branch_id,
            'A supervisor-created ad was not scoped to their own branch.'
        );
    }

    /**
     * A CRAFTED branch_id in the request body cannot move a supervisor's new
     * promotion anywhere — not to another branch, and not to global.
     *
     * This is the half UI absence can never cover: the form has no branch field
     * for them, so the only way to send one is by hand, which is exactly what
     * this does.
     */
    public function test_a_supervisor_cannot_forge_the_branch_of_a_new_promotion(): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $far     = $this->otherBranch();

        $code = self::PREFIX . 'FORGE' . strtoupper(substr(uniqid(), -4));
        $this->actingAs($manager, 'admin')
            ->post(route('admin.vouchers.store'), $this->voucherPayload([
                'code'      => $code,
                'branch_id' => $far->id,
            ]));

        $this->assertSame(
            self::HOME_BRANCH,
            (int) Voucher::where('code', $code)->value('branch_id'),
            'A crafted branch_id moved a supervisor-created voucher into another branch.'
        );

        $title = self::PREFIX . ' Forged ' . uniqid();
        $this->actingAs($manager, 'admin')
            ->post(route('admin.ads.store'), $this->adPayload([
                'title'     => $title,
                'branch_id' => '',
            ]));

        $this->assertSame(
            self::HOME_BRANCH,
            (int) Ad::where('title', $title)->value('branch_id'),
            'An empty branch_id let a supervisor create a company-wide ad.'
        );
    }

    // ═════════════ 2. THE LIMIT — vouchers ═════════════

    /**
     * Edit is refused, and — the assertion that actually matters — the voucher
     * is unchanged afterwards. "Did it refuse" and "did it happen anyway" are
     * different questions; only the second catches a gate that redirects after
     * the write.
     *
     * @dataProvider forbiddenScopes
     */
    public function test_a_supervisor_cannot_edit_a_voucher_outside_their_branch(string $kind): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $voucher = $this->voucherIn($this->forbiddenBranchId($kind));
        $before  = $voucher->only(['code', 'description', 'discount_value', 'max_uses', 'branch_id']);

        $this->actingAs($manager, 'admin')
            ->put(route('admin.vouchers.update', $voucher->id), $this->voucherPayload())
            ->assertRedirect(route('admin.vouchers'));

        $this->assertSame(
            $before,
            Voucher::find($voucher->id)->only(array_keys($before)),
            "A supervisor edited a $kind voucher, which the LIMITED rule forbids."
        );
    }

    /**
     * Activate/Deactivate, same rule. A company-wide promotion must not be
     * switchable off by one branch's manager.
     *
     * @dataProvider forbiddenScopes
     */
    public function test_a_supervisor_cannot_toggle_a_voucher_outside_their_branch(string $kind): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $voucher = $this->voucherIn($this->forbiddenBranchId($kind));

        $this->actingAs($manager, 'admin')
            ->put(route('admin.vouchers.toggle', $voucher->id))
            ->assertRedirect(route('admin.vouchers'));

        $this->assertTrue(
            (bool) Voucher::find($voucher->id)->is_active,
            "A supervisor deactivated a $kind voucher, which the LIMITED rule forbids."
        );
    }

    /** The permitted case, so the rule is a narrowing and not a wall. */
    public function test_a_supervisor_can_edit_and_toggle_their_own_branch_voucher(): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $voucher = $this->voucherIn(self::HOME_BRANCH);

        $newCode = self::PREFIX . 'MINE' . strtoupper(substr(uniqid(), -4));

        $this->actingAs($manager, 'admin')
            ->put(route('admin.vouchers.update', $voucher->id), $this->voucherPayload(['code' => $newCode]))
            ->assertRedirect(route('admin.vouchers'));

        $updated = Voucher::find($voucher->id);

        $this->assertSame($newCode, $updated->code, 'A supervisor could not edit their own branch voucher.');
        $this->assertSame(
            self::HOME_BRANCH,
            (int) $updated->branch_id,
            'Editing an own-branch voucher moved it out of the branch.'
        );

        /*
          * Read the state back rather than assuming it: updateVoucher() sets
          * is_active from `$request->has('is_active')`, so the edit above —
          * which posts no such field, exactly as the modal does with the box
          * unticked — has already switched the voucher off. The toggle is
          * asserted as an INVERSION of whatever is actually stored, which is
          * what the endpoint promises and is immune to that interaction.
          */
        $before = (bool) Voucher::find($voucher->id)->is_active;

        $this->actingAs($manager, 'admin')
            ->put(route('admin.vouchers.toggle', $voucher->id))
            ->assertRedirect(route('admin.vouchers'));

        $this->assertSame(
            ! $before,
            (bool) Voucher::find($voucher->id)->is_active,
            'A supervisor could not toggle their own branch voucher.'
        );
    }

    /**
     * A supervisor cannot use the EDIT form to promote their own voucher to
     * global — the escalation this whole rule exists to prevent, reached from
     * the one record they are legitimately allowed to touch.
     */
    public function test_a_supervisor_cannot_promote_their_voucher_to_global(): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $voucher = $this->voucherIn(self::HOME_BRANCH);

        $this->actingAs($manager, 'admin')
            ->put(route('admin.vouchers.update', $voucher->id), $this->voucherPayload(['branch_id' => '']));

        $this->assertSame(
            self::HOME_BRANCH,
            (int) Voucher::find($voucher->id)->branch_id,
            'A supervisor promoted their own voucher to a company-wide one.'
        );
    }

    // ═════════════ 3. THE LIMIT — ads ═════════════

    /** @dataProvider forbiddenScopes */
    public function test_a_supervisor_cannot_edit_an_ad_outside_their_branch(string $kind): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $ad      = $this->adIn($this->forbiddenBranchId($kind));
        $before  = $ad->only(['title', 'description', 'placement', 'branch_id']);

        $this->actingAs($manager, 'admin')
            ->put(route('admin.ads.update', $ad->id), $this->adPayload())
            ->assertRedirect(route('admin.ads'));

        $this->assertSame(
            $before,
            Ad::find($ad->id)->only(array_keys($before)),
            "A supervisor edited a $kind ad, which the LIMITED rule forbids."
        );
    }

    /** @dataProvider forbiddenScopes */
    public function test_a_supervisor_cannot_toggle_an_ad_outside_their_branch(string $kind): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $ad      = $this->adIn($this->forbiddenBranchId($kind));

        $this->actingAs($manager, 'admin')
            ->put(route('admin.ads.toggle', $ad->id))
            ->assertRedirect(route('admin.ads'));

        $this->assertTrue(
            (bool) Ad::find($ad->id)->is_active,
            "A supervisor deactivated a $kind ad, which the LIMITED rule forbids."
        );
    }

    /**
     * Deleting an ad is part of "Manage Advertisements" rather than a separate
     * owner-only row, so a supervisor keeps it — bounded by the same rule.
     *
     * @dataProvider forbiddenScopes
     */
    public function test_a_supervisor_cannot_delete_an_ad_outside_their_branch(string $kind): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $ad      = $this->adIn($this->forbiddenBranchId($kind));

        $this->actingAs($manager, 'admin')
            ->delete(route('admin.ads.delete', $ad->id))
            ->assertRedirect(route('admin.ads'));

        $this->assertNotNull(
            Ad::find($ad->id),
            "A supervisor deleted a $kind ad, which the LIMITED rule forbids."
        );
    }

    public function test_a_supervisor_can_manage_their_own_branch_ad(): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $ad      = $this->adIn(self::HOME_BRANCH);
        $title   = self::PREFIX . ' Renamed ' . uniqid();

        $this->actingAs($manager, 'admin')
            ->put(route('admin.ads.update', $ad->id), $this->adPayload(['title' => $title]))
            ->assertRedirect(route('admin.ads'));

        $this->assertSame($title, Ad::find($ad->id)->title, 'A supervisor could not edit their own branch ad.');

        $this->actingAs($manager, 'admin')
            ->delete(route('admin.ads.delete', $ad->id))
            ->assertRedirect(route('admin.ads'));

        $this->assertNull(Ad::find($ad->id), 'A supervisor could not delete their own branch ad.');
    }

    // ═════════════ 4. THE OWNER IS NOT NARROWED ═════════════

    /** Global stays the default when the owner picks no branch. */
    public function test_the_owner_still_creates_global_promotions_by_default(): void
    {
        $owner = $this->owner();

        $code = self::PREFIX . 'GLOB' . strtoupper(substr(uniqid(), -4));
        $this->actingAs($owner, 'admin')
            ->post(route('admin.vouchers.store'), $this->voucherPayload(['code' => $code]))
            ->assertRedirect(route('admin.vouchers'));

        $this->assertNull(
            Voucher::where('code', $code)->value('branch_id'),
            'An owner-created voucher was not global by default.'
        );

        $title = self::PREFIX . ' Global ' . uniqid();
        $this->actingAs($owner, 'admin')
            ->post(route('admin.ads.store'), $this->adPayload(['title' => $title]))
            ->assertRedirect(route('admin.ads'));

        $this->assertNull(
            Ad::where('title', $title)->value('branch_id'),
            'An owner-created ad was not global by default.'
        );
    }

    /** And they may scope one to a branch when they choose to. */
    public function test_the_owner_can_scope_a_promotion_to_one_branch(): void
    {
        $owner = $this->owner();
        $far   = $this->otherBranch();

        $code = self::PREFIX . 'SCOPED' . strtoupper(substr(uniqid(), -4));
        $this->actingAs($owner, 'admin')
            ->post(route('admin.vouchers.store'), $this->voucherPayload([
                'code'      => $code,
                'branch_id' => $far->id,
            ]))
            ->assertRedirect(route('admin.vouchers'));

        $this->assertSame(
            (int) $far->id,
            (int) Voucher::where('code', $code)->value('branch_id'),
            'The owner could not scope a voucher to one branch.'
        );

        $title = self::PREFIX . ' Scoped ' . uniqid();
        $this->actingAs($owner, 'admin')
            ->post(route('admin.ads.store'), $this->adPayload([
                'title'     => $title,
                'branch_id' => $far->id,
            ]))
            ->assertRedirect(route('admin.ads'));

        $this->assertSame(
            (int) $far->id,
            (int) Ad::where('title', $title)->value('branch_id'),
            'The owner could not scope an ad to one branch.'
        );
    }

    /** Every record stays reachable for the owner, global ones included. */
    public function test_the_owner_can_still_edit_and_toggle_a_global_promotion(): void
    {
        $owner   = $this->owner();
        $voucher = $this->voucherIn(null);
        $ad      = $this->adIn(null);

        $newCode = self::PREFIX . 'OWN' . strtoupper(substr(uniqid(), -4));

        $this->actingAs($owner, 'admin')
            ->put(route('admin.vouchers.update', $voucher->id), $this->voucherPayload(['code' => $newCode]));

        $this->assertSame($newCode, Voucher::find($voucher->id)->code, 'The owner could not edit a global voucher.');

        // Inversion, not an absolute — see the note in the supervisor's
        // equivalent test for why the preceding edit already moved is_active.
        $before = (bool) Voucher::find($voucher->id)->is_active;

        $this->actingAs($owner, 'admin')->put(route('admin.vouchers.toggle', $voucher->id));

        $this->assertSame(
            ! $before,
            (bool) Voucher::find($voucher->id)->is_active,
            'The owner could not toggle a global voucher.'
        );

        $this->actingAs($owner, 'admin')->put(route('admin.ads.toggle', $ad->id));
        $this->assertFalse((bool) Ad::find($ad->id)->is_active, 'The owner could not toggle a global ad.');

        $this->actingAs($owner, 'admin')->delete(route('admin.ads.delete', $ad->id));
        $this->assertNull(Ad::find($ad->id), 'The owner could not delete a global ad.');
    }

    // ═════════════ 5. THE LISTING ═════════════

    /**
     * A supervisor sees their own branch's promotions AND the global ones —
     * the globals read-only, so they stay aware of company-wide campaigns
     * without being able to touch one — and never another branch's.
     *
     * Read-only is asserted as "the row is on the page, but its edit/toggle
     * form action is not", which is the same shape Phase 2 uses for a control a
     * role may not use.
     */
    public function test_a_supervisor_sees_own_branch_and_global_promotions_only(): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $far     = $this->otherBranch();

        $mine   = $this->voucherIn(self::HOME_BRANCH);
        $global = $this->voucherIn(null);
        $theirs = $this->voucherIn($far->id);

        $html = $this->actingAs($manager, 'admin')
            ->get(route('admin.vouchers'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($mine->code, $html, 'A supervisor cannot see their own branch voucher.');
        $this->assertStringContainsString($global->code, $html, 'A supervisor cannot see a company-wide voucher.');
        $this->assertStringNotContainsString($theirs->code, $html, "A supervisor can see another branch's voucher.");

        $this->assertStringContainsString(
            route('admin.vouchers.update', $mine->id),
            $html,
            'A supervisor has no Edit control on their own branch voucher.'
        );
        $this->assertStringNotContainsString(
            route('admin.vouchers.toggle', $global->id),
            $html,
            'A supervisor was offered the on/off toggle for a company-wide voucher.'
        );
    }

    /** The same for ads, where the supervisor also loses the Delete button. */
    public function test_a_supervisor_sees_own_branch_and_global_ads_only(): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $far     = $this->otherBranch();

        $mine   = $this->adIn(self::HOME_BRANCH);
        $global = $this->adIn(null);
        $theirs = $this->adIn($far->id);

        $html = $this->actingAs($manager, 'admin')
            ->get(route('admin.ads'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($mine->title, $html, 'A supervisor cannot see their own branch ad.');
        $this->assertStringContainsString($global->title, $html, 'A supervisor cannot see a company-wide ad.');
        $this->assertStringNotContainsString($theirs->title, $html, "A supervisor can see another branch's ad.");

        $this->assertStringNotContainsString(
            route('admin.ads.delete', $global->id),
            $html,
            'A supervisor was offered Delete on a company-wide ad.'
        );
        $this->assertStringContainsString(
            route('admin.ads.delete', $mine->id),
            $html,
            'A supervisor has no Delete control on their own branch ad.'
        );
    }

    /** The owner's listing is not narrowed by any of this. */
    public function test_the_owner_sees_every_branch_promotion(): void
    {
        $far = $this->otherBranch();

        $global = $this->voucherIn(null);
        $mine   = $this->voucherIn(self::HOME_BRANCH);
        $theirs = $this->voucherIn($far->id);

        $html = $this->actingAs($this->owner(), 'admin')
            ->get(route('admin.vouchers'))
            ->assertOk()
            ->getContent();

        foreach ([$global, $mine, $theirs] as $voucher) {
            $this->assertStringContainsString(
                $voucher->code,
                $html,
                'The owner cannot see a voucher they are entitled to manage.'
            );
        }
    }

    // ═════════════ 6. DELETE VOUCHERS IS UNCHANGED ═════════════

    /**
     * "Delete Vouchers" stays Y | N | N. Phase 2 owns this row and this pass
     * did not touch it — asserted here against the ONE voucher a supervisor may
     * otherwise freely edit and toggle, which is the case a careless "they
     * manage their own branch's vouchers now" refactor would have opened.
     */
    public function test_delete_vouchers_remains_owner_only_even_in_their_own_branch(): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $voucher = $this->voucherIn(self::HOME_BRANCH);

        $this->actingAs($manager, 'admin')
            ->delete(route('admin.vouchers.delete', $voucher->id))
            ->assertRedirect(route('admin.home'));

        $this->assertSame(
            "You don't have permission to access that.",
            session('error'),
            'A supervisor voucher delete was refused, but not by the role gate.'
        );

        $this->assertNotNull(
            Voucher::find($voucher->id),
            'A supervisor deleted a voucher in their own branch; that row is Y | N | N.'
        );
    }

    // ═════════════ 7. MIGRATION SAFETY ═════════════

    /**
     * The schema change itself, checked against the LIVE data rather than a
     * fixture: every promotion that existed before this pass must still be
     * global, because that is exactly how it behaves today and the migration
     * promised not to change it.
     *
     * READ-ONLY. This is the one test that looks at pre-existing rows and it
     * mutates nothing — the whole point of the assertion is that those rows
     * were left alone.
     */
    public function test_pre_existing_promotions_are_global_after_the_migration(): void
    {
        foreach (['vouchers', 'ads'] as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'branch_id'),
                "$table.branch_id is missing; the branch-scope migration did not run."
            );
        }

        // Anything minted before this pass began. Nothing in this run can be
        // caught by it: DatabaseTransactions rolls every fixture back, and the
        // ids below all predate the migration.
        $cutoff = '2026-09-09 15:00:00';

        foreach (['vouchers', 'ads'] as $table) {
            $scoped = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->whereNotNull('branch_id')
                ->count();

            $this->assertSame(
                0,
                $scoped,
                "The migration left $scoped pre-existing $table row(s) scoped to a branch; "
                . 'every one of them must still be global.'
            );
        }
    }

    /**
     * NULL genuinely means "no branch" at the database level too — a scoped
     * promotion cannot name a branch that does not exist, and the column really
     * is nullable rather than defaulting to a branch.
     */
    public function test_the_branch_column_is_nullable_and_constrained(): void
    {
        $voucher = $this->voucherIn(null);

        $this->assertNull(
            DB::table('vouchers')->where('id', $voucher->id)->value('branch_id'),
            'A voucher created with no branch did not store NULL.'
        );

        $this->expectException(\Illuminate\Database\QueryException::class);

        // 2147483647 is not a branch. The foreign key must refuse it rather
        // than leaving an ad pointing at nothing.
        $this->adIn(2147483647);
    }
}
