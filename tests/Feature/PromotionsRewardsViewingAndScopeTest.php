<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StreakRewardController;
use App\Models\Ad;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\Voucher;
use App\Services\StreakRewardSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Promotions & Rewards, defects pass 2 (Oct 2026).
 *
 *  - The "Viewing:" branch bar now sits on the Vouchers and Streak Rewards tabs
 *    too, and really narrows them: one branch shows that branch's own vouchers
 *    plus the All-Branches ones, and the choice is the same one the bar keeps
 *    on every admin page (session selected_branch_id), so it survives moving
 *    between the tabs. The Vouchers list switches through the existing
 *    background fragment (X-Vouchers-Fragment).
 *  - "Free menu item" can be picked on All Branches; with no item sold at every
 *    branch (none can be created from Menu Items) saving it is refused with a
 *    plain message and links to the branch tabs.
 *  - Supervisors: only their own branch anywhere on the three tabs, server-side.
 *    Another branch's voucher/ad answers a typed request with 404 and a
 *    company-wide one with 403, and changes nothing; the code checker treats
 *    another branch's code as one that does not exist.
 *  - The Streak Rewards redesign: the Reward Code Checker shows no sample codes.
 */
class PromotionsRewardsViewingAndScopeTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $mine;
    private Branch $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::selectOne('select database() as d')->d);

        $this->mine = $this->branch('Mine');
        $this->theirs = $this->branch('Theirs');
    }

    private function branch(string $label): Branch
    {
        return Branch::create([
            'name'      => 'PRV ' . $label . ' ' . Str::lower(Str::random(6)),
            'code'      => 'PRV' . strtoupper(Str::random(6)),
            'address'   => 'Viewing test address',
            'is_active' => true,
        ]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        return User::create([
            'name'              => 'PRV ' . $role,
            'email'             => 'prv-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : $branch?->id,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function voucher(?Branch $branch, array $attrs = []): Voucher
    {
        return Voucher::create($attrs + [
            'code'            => 'PRV' . strtoupper(Str::random(8)),
            'description'     => 'viewing test',
            'discount_type'   => 'fixed',
            'discount_value'  => 20,
            'max_uses'        => 50,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
            'expires_at'      => null,
            'branch_id'       => $branch?->id,
        ]);
    }

    private function ad(?Branch $branch): Ad
    {
        return Ad::create([
            'title'         => 'PRV Ad ' . Str::random(8),
            'placement'     => 'menu',
            'is_active'     => true,
            'display_order' => 0,
            'branch_id'     => $branch?->id,
        ]);
    }

    private function listedIds(string $html): array
    {
        preg_match_all('/data-voucher-row="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    private function stat(string $html, string $key): int
    {
        $this->assertSame(1, preg_match('/data-stat="' . $key . '">([\d,]+)</', $html, $m), "stat card $key missing");

        return (int) str_replace(',', '', $m[1]);
    }

    // ══════════ D. the Viewing branch on Vouchers ══════════

    public function test_viewing_one_branch_lists_its_own_and_all_branches_vouchers_only(): void
    {
        $admin = $this->user('admin');
        $own = $this->voucher($this->mine);
        $global = $this->voucher(null);
        $other = $this->voucher($this->theirs);

        // All Branches: every voucher.
        $all = $this->listedIds($this->actingAs($admin, 'admin')->get('/admin/vouchers?branch=all&per_page=100&q=PRV')->assertOk()->getContent());
        $this->assertContains($own->id, $all);
        $this->assertContains($global->id, $all);
        $this->assertContains($other->id, $all);

        // One branch: its own plus the All-Branches ones, with their scope badges.
        $response = $this->actingAs($admin, 'admin')->get('/admin/vouchers?branch=' . $this->mine->id . '&per_page=100&q=PRV')->assertOk();
        $html = $response->getContent();
        $listed = $this->listedIds($html);
        $this->assertContains($own->id, $listed);
        $this->assertContains($global->id, $listed);
        $this->assertNotContains($other->id, $listed, 'another branch\'s voucher was listed while viewing this branch');
        $this->assertStringContainsString('vch-badge is-branch"><span aria-hidden="true">📍</span> ' . e($this->mine->name) . '<', $html);
        $this->assertStringContainsString('vch-badge is-global"><span aria-hidden="true">🌐</span> Global<', $html);

        // The bar says so, and remembers it.
        $this->assertMatchesRegularExpression('/<span class="value">\s*' . preg_quote(e($this->mine->name), '/') . '\s*</', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $this->mine->id . '"\s+selected>/', $html);
        $response->assertSessionHas('selected_branch_id', $this->mine->id);
        $this->assertStringContainsString('name="branch" value="' . $this->mine->id . '" data-vch-viewing', $html);
    }

    public function test_the_stat_cards_follow_the_viewing_branch_but_not_the_filters(): void
    {
        $admin = $this->user('admin');
        $count = fn (string $query) => $this->stat($this->actingAs($admin, 'admin')->get('/admin/vouchers' . $query)->assertOk()->getContent(), 'total');

        $beforeMine = $count('?branch=' . $this->mine->id);
        $beforeAll = $count('?branch=all');

        $this->voucher($this->mine);
        $this->voucher($this->theirs);
        $this->voucher($this->theirs);

        $this->assertSame(1, $count('?branch=' . $this->mine->id) - $beforeMine);
        $this->assertSame(3, $count('?branch=all') - $beforeAll);
        // A search narrows the list, never the cards.
        $this->assertSame($count('?branch=' . $this->mine->id), $count('?branch=' . $this->mine->id . '&q=PRV-NOTHING-MATCHES'));
    }

    public function test_the_background_fragment_switches_branch_and_carries_the_counts(): void
    {
        $admin = $this->user('admin');
        $own = $this->voucher($this->mine);
        $other = $this->voucher($this->theirs);

        $response = $this->actingAs($admin, 'admin')
            ->withHeader('X-Vouchers-Fragment', '1')
            ->get('/admin/vouchers?branch=' . $this->theirs->id . '&q=PRV&per_page=100')
            ->assertOk()
            ->assertHeader('Vary', 'X-Vouchers-Fragment');

        $html = $response->getContent();
        $this->assertStringStartsWith('<div id="vchResults"', ltrim($html), 'the fragment is not just the results region');
        $this->assertStringNotContainsString('<html', $html);
        $this->assertContains($other->id, $this->listedIds($html));
        $this->assertNotContains($own->id, $this->listedIds($html));
        foreach (['total', 'active', 'on-wheel', 'expired'] as $key) {
            $this->assertMatchesRegularExpression('/data-stat-' . $key . '="\d+"/', $html, "the fragment lost the $key count");
        }
        $response->assertSessionHas('selected_branch_id', $this->theirs->id);
    }

    public function test_viewing_a_branch_drops_a_scope_filter_for_a_different_branch(): void
    {
        $admin = $this->user('admin');
        $own = $this->voucher($this->mine);
        $global = $this->voucher(null);

        $html = $this->actingAs($admin, 'admin')
            ->get('/admin/vouchers?branch=' . $this->mine->id . '&scope=' . $this->theirs->id . '&q=PRV&per_page=100')
            ->assertOk()->getContent();

        $this->assertContains($own->id, $this->listedIds($html));
        $this->assertContains($global->id, $this->listedIds($html));
        // The other branch's scope option is still in the markup for the
        // script, but hidden and disabled.
        $this->assertMatchesRegularExpression('/<option value="' . $this->theirs->id . '" data-scope-branch\s+hidden disabled\s*>/', $html);
    }

    public function test_the_choice_carries_over_to_the_streak_rewards_tab_and_back(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'admin')->get('/admin/vouchers?branch=' . $this->mine->id)
            ->assertSessionHas('selected_branch_id', $this->mine->id);

        // Streak Rewards with no query value opens on the remembered branch,
        // and its tab, the bar and the card agree.
        $html = $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => $this->mine->id])
            ->get(route('admin.streak-rewards'))->assertOk()->getContent();
        $this->assertStringContainsString('data-scope="' . $this->mine->id . '"', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $this->mine->id . '"\s+selected>/', $html);
        $this->assertMatchesRegularExpression('/class="promo-tab sr-branch-tab is-active"\s+aria-current="page"\s*>\s*<i class="bi bi-geo-alt" aria-hidden="true"><\/i>\s*<span>' . preg_quote(e($this->mine->name), '/') . '<\/span>/', $html);

        // A tab picked on Streak Rewards becomes the Viewing branch.
        $this->actingAs($admin, 'admin')->get(route('admin.streak-rewards', ['streak_branch' => $this->theirs->id]))
            ->assertOk()->assertSessionHas('selected_branch_id', $this->theirs->id);
        $this->actingAs($admin, 'admin')->get(route('admin.streak-rewards', ['streak_branch' => 'default']))
            ->assertOk()->assertSessionHas('selected_branch_id', 'all');

        // ...and Vouchers opens on it.
        $own = $this->voucher($this->mine);
        $html = $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => $this->theirs->id])
            ->get('/admin/vouchers?q=PRV&per_page=100')->assertOk()->getContent();
        $this->assertNotContains($own->id, $this->listedIds($html));
    }

    public function test_a_branch_that_does_not_exist_keeps_the_remembered_choice(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => $this->mine->id])
            ->get('/admin/vouchers?branch=999999999')->assertOk()
            ->assertSessionHas('selected_branch_id', $this->mine->id);
        $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => $this->mine->id])
            ->get('/admin/vouchers?branch[]=1')->assertOk()
            ->assertSessionHas('selected_branch_id', $this->mine->id);
    }

    // ══════════ H. a supervisor sees only their branch ══════════

    public function test_a_supervisor_never_sees_another_branch_on_any_tab(): void
    {
        $supervisor = $this->user('supervisor', $this->mine);
        $theirVoucher = $this->voucher($this->theirs);
        $theirAd = $this->ad($this->theirs);

        foreach (['/admin/vouchers', '/admin/streak-rewards', '/admin/ads'] as $url) {
            // A crafted ?branch= / ?streak_branch= / ?scope= is ignored, and never remembered.
            $query = '?branch=' . $this->theirs->id . '&streak_branch=' . $this->theirs->id . '&scope=' . $this->theirs->id;
            $response = $this->actingAs($supervisor, 'admin')->get($url . $query)->assertOk();
            $html = $response->getContent();

            $this->assertStringNotContainsString($this->theirs->name, $html, "$url named another branch");
            $this->assertStringNotContainsString($theirVoucher->code, $html, "$url listed another branch's voucher");
            $this->assertStringNotContainsString($theirAd->title, $html, "$url listed another branch's ad");
            // The bar is a fixed label of their own branch: no select at all.
            $this->assertMatchesRegularExpression('/<span class="value">\s*' . preg_quote(e($this->mine->name), '/') . '\s*</', $html);
            $this->assertStringNotContainsString('data-pc-branch-select', $html);
            $response->assertSessionMissing('selected_branch_id');
        }

        // Streak Rewards: their branch only — no tabs, no overview, no "All Branches".
        $html = $this->actingAs($supervisor, 'admin')->get(route('admin.streak-rewards'))->getContent();
        $this->assertStringContainsString('data-scope="' . $this->mine->id . '"', $html);
        $this->assertStringNotContainsString('data-testid="streak-rewards-selector"', $html);
        $this->assertStringNotContainsString('data-testid="streak-rewards-overview"', $html);
        $this->assertStringNotContainsString('data-testid="streak-switch-card"', $html);
        $this->assertStringNotContainsString('All Branches', $this->between($html, 'data-testid="streak-rewards-card"', 'data-testid="reward-code-check-card"'));

        // Vouchers: the scope filter offers Global and their branch only; the
        // create form has no scope field.
        $html = $this->actingAs($supervisor, 'admin')->get('/admin/vouchers')->getContent();
        preg_match_all('/<option value="(\d+)" data-scope-branch/', $html, $m);
        $this->assertSame([(string) $this->mine->id], $m[1]);
        $this->assertStringNotContainsString('name="branch_id"', $this->between($html, 'data-testid="create-voucher-card"', 'data-testid="voucher-list-card"'));
        $this->assertStringNotContainsString('name="branch" value=', $html, 'a supervisor was given a Viewing branch to carry');

        // Ads: no scope field on the create form or the edit modal.
        $this->assertStringNotContainsString('name="branch_id"', $this->actingAs($supervisor, 'admin')->get('/admin/ads')->getContent());
    }

    public function test_crafted_writes_on_another_branchs_promotions_are_404_and_change_nothing(): void
    {
        $supervisor = $this->user('supervisor', $this->mine);
        $voucher = $this->voucher($this->theirs);
        $ad = $this->ad($this->theirs);
        $voucherBefore = $voucher->fresh()->toArray();
        $adBefore = $ad->fresh()->toArray();

        $as = fn () => $this->actingAs($supervisor, 'admin');

        $as()->put(route('admin.vouchers.update', $voucher->id), [
            'code' => 'PRVHACK' . Str::upper(Str::random(4)), 'discount_type' => 'fixed', 'discount_value' => 99, 'max_uses' => 1,
        ])->assertNotFound();
        $as()->put(route('admin.vouchers.toggle', $voucher->id))->assertNotFound();
        $as()->post(route('admin.vouchers.issue-code', $voucher->id))->assertNotFound();
        $as()->put(route('admin.ads.update', $ad->id), ['title' => 'PRV hacked', 'placement' => 'game'])->assertNotFound();
        $as()->put(route('admin.ads.toggle', $ad->id))->assertNotFound();
        $as()->delete(route('admin.ads.delete', $ad->id))->assertNotFound();
        // Deleting a voucher is owner-only for every branch: the role gate turns it away.
        $as()->delete(route('admin.vouchers.delete', $voucher->id))->assertRedirect(route('admin.home'));

        $this->assertEquals($voucherBefore, $voucher->fresh()->toArray(), 'a refused request changed the voucher');
        $this->assertEquals($adBefore, $ad->fresh()->toArray(), 'a refused request changed the ad');
        $this->assertSame(0, DB::table('user_vouchers')->where('voucher_id', $voucher->id)->count(), 'a refused issue-code minted a claim');

        // Streak settings of another branch: 403, nothing written.
        $rows = fn () => Setting::where('branch_id', $this->theirs->id)->count();
        $before = $rows();
        $as()->post(route('admin.streak.rewards.update'), [
            'branch_id' => $this->theirs->id, 'days' => 3, 'reward_type' => 'voucher', 'voucher_id' => $this->voucher(null)->id, 'valid_days' => 5,
        ])->assertForbidden();
        $as()->post(route('admin.streak.branch.toggle'), ['branch_id' => $this->theirs->id])->assertForbidden();
        $as()->post(route('admin.streak.rewards.default'), ['branch_id' => $this->theirs->id])->assertForbidden();
        $this->assertSame($before, $rows());
    }

    public function test_company_wide_promotions_are_read_only_for_a_supervisor(): void
    {
        $supervisor = $this->user('supervisor', $this->mine);
        $voucher = $this->voucher(null);
        $ad = $this->ad(null);

        // Shown (so the counter can quote them) but with no controls...
        $html = $this->actingAs($supervisor, 'admin')->get('/admin/vouchers?q=' . $voucher->code)->getContent();
        $this->assertContains($voucher->id, $this->listedIds($html));
        $this->assertStringNotContainsString(route('admin.vouchers.toggle', $voucher->id), $html);
        $html = $this->actingAs($supervisor, 'admin')->get('/admin/ads')->getContent();
        $this->assertStringContainsString($ad->title, $html);
        $this->assertStringNotContainsString(route('admin.ads.toggle', $ad->id), $html);
        $this->assertStringNotContainsString(route('admin.ads.delete', $ad->id), $html);

        // ...and a typed request is 403, changing nothing.
        $this->actingAs($supervisor, 'admin')->put(route('admin.vouchers.toggle', $voucher->id))->assertForbidden();
        $this->actingAs($supervisor, 'admin')->put(route('admin.ads.toggle', $ad->id))->assertForbidden();
        $this->actingAs($supervisor, 'admin')->delete(route('admin.ads.delete', $ad->id))->assertForbidden();
        $this->assertTrue((bool) $voucher->fresh()->is_active);
        $this->assertTrue((bool) $ad->fresh()->is_active);
        $this->assertNotNull(Ad::find($ad->id));
    }

    public function test_the_owner_still_sees_and_manages_every_branch(): void
    {
        $admin = $this->user('admin');
        $voucher = $this->voucher($this->theirs);
        $ad = $this->ad($this->theirs);

        $html = $this->actingAs($admin, 'admin')->get('/admin/vouchers?branch=all')->assertOk()->getContent();
        $this->assertStringContainsString('<option value="' . $this->theirs->id . '" data-scope-branch', $html);
        $this->assertStringContainsString('data-pc-branch-select', $html);

        $html = $this->actingAs($admin, 'admin')->get(route('admin.streak-rewards', ['streak_branch' => 'default']))->assertOk()->getContent();
        foreach ([$this->mine, $this->theirs] as $branch) {
            $this->assertStringContainsString('data-branch-row="' . $branch->id . '"', $html);
            $this->assertStringContainsString('<span>' . e($branch->name) . '</span>', $html);
        }
        $this->assertStringContainsString('data-global-row', $html);

        $this->actingAs($admin, 'admin')->put(route('admin.vouchers.toggle', $voucher->id))->assertRedirect(route('admin.vouchers'));
        $this->assertFalse((bool) $voucher->fresh()->is_active);
        $this->actingAs($admin, 'admin')->put(route('admin.ads.toggle', $ad->id))->assertRedirect(route('admin.ads'));
        $this->assertFalse((bool) $ad->fresh()->is_active);
    }

    // ══════════ A. the Streak Rewards redesign ══════════

    public function test_the_streak_page_follows_the_design_without_its_demo_parts(): void
    {
        $html = $this->actingAs($this->user('admin'), 'admin')
            ->get(route('admin.streak-rewards', ['streak_branch' => 'default']))->assertOk()->getContent();

        $this->assertStringContainsString('Master Streak Calendar', $html);
        $this->assertStringContainsString('🔥 Streak Rewards', $html);
        $this->assertStringContainsString('🔍 Reward Code Checker', $html);
        $this->assertStringContainsString('🏪 Branch Overview', $html);
        $this->assertStringContainsString('Global defaults vs branch overrides', $html);
        $this->assertStringContainsString('Save Streak Settings', $html);
        $this->assertStringContainsString('data-sr-stamps', $html);
        $this->assertStringContainsString("Also make all branches use this (removes each branch's own settings).", $html);
        // The branch tabs are the page's own tab component, not pill chips.
        $this->assertMatchesRegularExpression('/<nav class="promo-tabs sr-branch-tabs"[^>]*data-testid="streak-rewards-selector"/', $html);
        $this->assertMatchesRegularExpression('/class="promo-tab sr-branch-tab is-active"\s+aria-current="page"\s*>\s*<i class="bi bi-globe2" aria-hidden="true"><\/i>\s*<span>All Branches<\/span>/', $html);

        // Not built: the demo toggle, the sample codes and the old placeholder.
        $this->assertStringNotContainsString('Hide Branch Overview', $html);
        $this->assertStringNotContainsString('STRK-', $html);
        $this->assertStringNotContainsString('E.G.', $html);
        $this->assertStringNotContainsString('Try:', $html);
        $this->assertMatchesRegularExpression('/<input type="text" id="rewardCodeInput" name="reward_code"[^>]*\s+placeholder="Enter reward code"/', $html);
    }

    public function test_the_overview_switch_returns_to_the_tab_the_owner_was_on(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.streak.branch.toggle'), ['branch_id' => $this->theirs->id, 'return_scope' => 'default'])
            ->assertRedirect(route('admin.streak-rewards', ['streak_branch' => 'default']) . '#streak-rewards-card');
        $this->assertFalse(StreakRewardSettings::branchEnabled($this->theirs->id));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.streak.branch.toggle'), ['branch_id' => $this->theirs->id, 'return_scope' => (string) $this->mine->id])
            ->assertRedirect(route('admin.streak-rewards', ['streak_branch' => $this->mine->id]) . '#streak-rewards-card');
        $this->assertTrue(StreakRewardSettings::branchEnabled($this->theirs->id));

        // Garbage falls back to the toggled branch.
        $this->actingAs($admin, 'admin')
            ->post(route('admin.streak.branch.toggle'), ['branch_id' => $this->theirs->id, 'return_scope' => 'nope'])
            ->assertRedirect(route('admin.streak-rewards', ['streak_branch' => $this->theirs->id]) . '#streak-rewards-card');
    }

    // ══════════ E. Free menu item on All Branches ══════════

    private function item(?Branch $branch, string $name): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => $branch?->id,
            'name'          => $name,
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function defaultRows(): array
    {
        return Setting::whereNull('branch_id')->whereIn('key', StreakRewardSettings::KEYS)->orderBy('key')->pluck('value', 'key')->all();
    }

    public function test_free_menu_item_is_selectable_on_all_branches_and_points_to_the_branch_tabs(): void
    {
        $this->assertSame(0, MenuItem::whereNull('branch_id')->count(), 'this test assumes no menu item is sold at every branch');

        $html = $this->actingAs($this->user('admin'), 'admin')
            ->get(route('admin.streak-rewards', ['streak_branch' => 'default', 'reward' => 'menu_item']))->assertOk()->getContent();

        // The option is there and enabled, and here it is picked.
        $this->assertMatchesRegularExpression('/<input type="radio" name="reward_type" value="menu_item" data-sr-type\s+checked>/', $html);
        $this->assertStringContainsString('data-testid="streak-rewards-item-per-branch"', $html);
        $this->assertStringContainsString('Free menu items are set per branch. Select a specific branch to choose the menu item.', $html);
        // One click to a branch tab, with Free menu item still chosen there.
        $this->assertStringContainsString(e(route('admin.streak-rewards', ['streak_branch' => $this->mine->id, 'reward' => 'menu_item'])) . '#streak-rewards-card', $html);
        // Save waits for a branch (the server refuses it as well).
        $this->assertMatchesRegularExpression('/data-sr-save\s+disabled\s*>/', $html);

        // On that branch tab, Free menu item is preselected with its picker.
        $this->item($this->mine, 'PRV Mine Cake');
        $html = $this->actingAs($this->user('admin'), 'admin')
            ->get(route('admin.streak-rewards', ['streak_branch' => $this->mine->id, 'reward' => 'menu_item']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="menu_item" data-sr-type\s+checked>/', $html);
        $this->assertStringContainsString('PRV Mine Cake', $html);
    }

    public function test_saving_a_free_menu_item_for_all_branches_is_refused_without_a_company_wide_item(): void
    {
        $admin = $this->user('admin');
        $before = $this->defaultRows();
        $payload = ['branch_id' => 'default', 'days' => 5, 'reward_type' => 'menu_item', 'valid_days' => 10];

        // No item at all.
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $payload)
            ->assertRedirect(route('admin.streak-rewards', ['streak_branch' => 'default']) . '#streak-rewards-card')
            ->assertSessionHasErrors(['menu_item_id' => StreakRewardController::ITEM_PER_BRANCH_MESSAGE], null, 'streakRewards');

        // One branch's item, sent anyway.
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $payload + ['menu_item_id' => $this->item($this->mine, 'PRV Branch Only')->id])
            ->assertSessionHasErrors(['menu_item_id' => StreakRewardController::ITEM_PER_BRANCH_MESSAGE], null, 'streakRewards');

        $this->assertSame($before, $this->defaultRows(), 'a refused All Branches save wrote something');

        // The refusal re-opens the form with the message under Free menu item.
        $this->actingAs($admin, 'admin')
            ->withSession(['_old_input' => $payload])
            ->get(route('admin.streak-rewards', ['streak_branch' => 'default']))
            ->assertOk()
            ->assertSee('data-testid="streak-rewards-item-per-branch"', false);
    }

    public function test_an_item_sold_at_every_branch_can_still_be_the_all_branches_reward(): void
    {
        // The data model allows one (branch_id NULL = on every branch's menu)
        // even though Menu Items cannot create it; when one exists it is offered
        // and saved, exactly as before this pass.
        $everywhere = $this->item(null, 'PRV Everywhere Cake');

        $html = $this->actingAs($this->user('admin'), 'admin')
            ->get(route('admin.streak-rewards', ['streak_branch' => 'default', 'reward' => 'menu_item']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-testid="streak-rewards-item-per-branch"', $html);
        $this->assertStringContainsString('<option value="' . $everywhere->id . '"', $html);
        $this->assertStringContainsString('Only items sold at every branch can be given here.', $html);

        $this->actingAs($this->user('admin'), 'admin')->post(route('admin.streak.rewards.update'), [
            'branch_id' => 'default', 'days' => 5, 'reward_type' => 'menu_item', 'menu_item_id' => $everywhere->id, 'valid_days' => 10,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Free PRV Everywhere Cake', StreakRewardSettings::current(null)['description']);
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "marker $from missing");
        $end = strpos($html, $to, $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
