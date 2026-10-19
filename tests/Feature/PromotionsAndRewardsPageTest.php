<?php

namespace Tests\Feature;

use App\Http\Controllers\Customer\AuthController;
use App\Models\Branch;
use App\Models\User;
use App\Models\Voucher;
use App\Support\ShopDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * "Promotions & Rewards" (item C, Oct 2026 defect report): Vouchers, Streak
 * Rewards and Ads Management share one page header and pill tab bar, and the
 * Vouchers tab got the new layout (stat cards, Spin Wheel bar, Create card, a
 * searchable / filterable list with a three-dot menu).
 *
 * What did NOT change, and is pinned here and in the older suites: each tab is
 * still its own URL with its own controller, forms, validation and role gate;
 * every voucher rule (redemption, the on/off switch, edit, delete, usage
 * counting) is untouched. The new status / wheel words are display and
 * filtering only, and the wheel word is proved equal to the real prize picker.
 */
class PromotionsAndRewardsPageTest extends TestCase
{
    use DatabaseTransactions;

    private function portal(string $role, ?int $branchId = null): User
    {
        return User::create([
            'name'              => 'Promo ' . $role,
            'email'             => 'promo-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : ($branchId ?? 1),
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    /** A date-only expiry, as the admin form stores it: $days from today (shop time). */
    private function expiry(?int $days): ?string
    {
        return $days === null
            ? null
            : CarbonImmutable::parse(ShopDay::todayString())->addDays($days)->format('Y-m-d') . ' 00:00:00';
    }

    private function voucher(array $attrs = []): Voucher
    {
        return Voucher::create($attrs + [
            'code'            => 'PRT-' . strtoupper(Str::random(8)),
            'description'     => 'Promo test',
            'discount_type'   => 'fixed',
            'discount_value'  => 50,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
            'expires_at'      => null,
            'branch_id'       => null,
        ]);
    }

    private function stats(User $user): array
    {
        $html = $this->actingAs($user, 'admin')->get('/admin/vouchers')->assertOk()->getContent();
        $out = [];
        foreach (['total', 'active', 'on_wheel', 'expired'] as $key) {
            $this->assertSame(1, preg_match('/data-stat="' . $key . '">([\d,]+)</', $html, $m), "stat card $key missing");
            $out[$key] = (int) str_replace(',', '', $m[1]);
        }

        return $out;
    }

    private function listed(User $user, string $query = ''): array
    {
        $html = $this->actingAs($user, 'admin')->get('/admin/vouchers' . $query)->assertOk()->getContent();
        preg_match_all('/data-voucher-row="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    // ══════════ tab routing ══════════

    public function test_each_tab_keeps_its_old_url_and_shows_the_shared_header_with_its_tab_lit(): void
    {
        $admin = $this->portal('admin');

        foreach (['/admin/vouchers' => 'Vouchers', '/admin/streak-rewards' => 'Streak Rewards', '/admin/ads' => 'Ads Management'] as $url => $label) {
            $html = $this->actingAs($admin, 'admin')->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Promotions &amp; Rewards', $html);
            $this->assertStringContainsString('Vouchers, streak rewards and advertisements in one place.', $html);
            // Exactly one tab is the current one, and it is this page's.
            $this->assertSame(1, preg_match_all('/class="promo-tab is-active"/', $html));
            $this->assertMatchesRegularExpression('/class="promo-tab is-active"\s+aria-current="page"\s*>\s*<i[^>]*><\/i>\s*<span>' . preg_quote($label, '/') . '<\/span>/', $html);
        }
    }

    public function test_the_promotions_address_opens_the_requested_tab(): void
    {
        $admin = $this->portal('admin');

        $this->actingAs($admin, 'admin')->get('/admin/promotions')->assertRedirect(route('admin.vouchers'));
        $this->actingAs($admin, 'admin')->get('/admin/promotions?tab=vouchers')->assertRedirect(route('admin.vouchers'));
        $this->actingAs($admin, 'admin')->get('/admin/promotions?tab=streak-rewards')->assertRedirect(route('admin.streak-rewards'));
        $this->actingAs($admin, 'admin')->get('/admin/promotions?tab=ads')->assertRedirect(route('admin.ads'));
        $this->actingAs($admin, 'admin')->get('/admin/promotions?tab=nonsense')->assertRedirect(route('admin.vouchers'));
    }

    public function test_after_saving_a_voucher_the_redirect_lands_on_the_vouchers_tab(): void
    {
        $code = 'PRT-' . strtoupper(Str::random(8));

        $this->actingAs($this->portal('admin'), 'admin')->post(route('admin.vouchers.store'), [
            'code' => $code, 'discount_type' => 'fixed', 'discount_value' => 10, 'max_uses' => 5,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertNotNull(Voucher::where('code', $code)->first());
    }

    // ══════════ who sees which tab ══════════

    public function test_the_tab_bar_lists_exactly_the_tabs_each_role_can_reach(): void
    {
        $expected = ['admin' => 3, 'supervisor' => 3, 'staff' => 1];

        foreach ($expected as $role => $count) {
            $user = $this->portal($role);
            $html = $this->actingAs($user, 'admin')->get('/admin/vouchers')->assertOk()->getContent();

            $this->assertSame($count, preg_match_all('/class="promo-tab[ "]/', $html), "$role sees the wrong number of tabs");

            foreach (['/admin/streak-rewards', '/admin/ads'] as $url) {
                $listed = (bool) preg_match('/href="' . preg_quote(url($url), '/') . '"\s+class="promo-tab/', $html);
                $status = $this->actingAs($user, 'admin')->get($url)->getStatusCode();

                // A tab is listed if and only if the page behind it opens.
                $this->assertSame($status === 200, $listed, "$role: tab for $url and the page disagree (HTTP $status)");
            }
        }
    }

    public function test_staff_asking_for_a_manager_tab_is_sent_to_vouchers_and_the_pages_stay_closed(): void
    {
        $staff = $this->portal('staff');

        $this->actingAs($staff, 'admin')->get('/admin/promotions?tab=ads')->assertRedirect(route('admin.vouchers'));
        $this->actingAs($staff, 'admin')->get('/admin/promotions?tab=streak-rewards')->assertRedirect(route('admin.vouchers'));

        $this->assertNotSame(200, $this->actingAs($staff, 'admin')->get('/admin/ads')->getStatusCode());
        $this->assertNotSame(200, $this->actingAs($staff, 'admin')->get('/admin/streak-rewards')->getStatusCode());
    }

    public function test_one_sidebar_link_replaces_the_three_and_is_lit_on_each_tab(): void
    {
        $admin = $this->portal('admin');

        foreach (['/admin/vouchers', '/admin/streak-rewards', '/admin/ads'] as $url) {
            $html = $this->actingAs($admin, 'admin')->get($url)->assertOk()->getContent();

            $this->assertSame(1, preg_match_all('/<span>Promotions &amp; Rewards<\/span>/', $html), "$url");
            $this->assertMatchesRegularExpression('/nav-link-item active">\s*<i class="bi bi-stars"><\/i>\s*<span>Promotions &amp; Rewards<\/span>/', $html);

            // No separate sidebar entries any more.
            $this->assertDoesNotMatchRegularExpression('/nav-link-item[^"]*">\s*<i class="bi bi-gift"><\/i>\s*<span>Streak Rewards<\/span>/', $html);
            $this->assertDoesNotMatchRegularExpression('/nav-link-item[^"]*">\s*<i class="bi bi-megaphone"><\/i>\s*<span>Ads<\/span>/', $html);
            $this->assertDoesNotMatchRegularExpression('/nav-link-item[^"]*">\s*<i class="bi bi-ticket-perforated"><\/i>\s*<span>Vouchers<\/span>/', $html);
        }

        // Staff get the same single link, and it opens a page they can read.
        $staffHtml = $this->actingAs($this->portal('staff'), 'admin')->get('/admin/home')->assertOk()->getContent();
        $this->assertStringContainsString('<span>Promotions &amp; Rewards</span>', $staffHtml);
    }

    // ══════════ the stat cards ══════════

    public function test_the_four_cards_count_each_state_for_the_owner(): void
    {
        $admin = $this->portal('admin');
        $before = $this->stats($admin);

        $this->voucher(['points_required' => 10, 'expires_at' => $this->expiry(20)]);   // active + wheel
        $this->voucher(['points_required' => 0]);                                        // active, direct
        $this->voucher(['is_active' => false, 'points_required' => 10]);                 // inactive
        $this->voucher(['max_uses' => 5, 'used_count' => 5, 'points_required' => 10]);  // used up
        $this->voucher(['expires_at' => $this->expiry(-3), 'points_required' => 10]);    // expired

        $after = $this->stats($admin);

        $this->assertSame(5, $after['total'] - $before['total']);
        $this->assertSame(2, $after['active'] - $before['active']);
        $this->assertSame(1, $after['on_wheel'] - $before['on_wheel']);
        $this->assertSame(1, $after['expired'] - $before['expired']);
    }

    public function test_the_cards_count_only_what_a_supervisor_may_see(): void
    {
        $supervisor = $this->portal('supervisor', 1);
        $before = $this->stats($supervisor);

        $this->voucher(['branch_id' => 1]);                                  // theirs
        $this->voucher(['branch_id' => null]);                               // global
        $this->voucher(['branch_id' => 2]);                                  // another branch: hidden
        $this->voucher(['branch_id' => 2, 'expires_at' => $this->expiry(-2)]);

        $after = $this->stats($supervisor);

        $this->assertSame(2, $after['total'] - $before['total']);
        $this->assertSame(2, $after['active'] - $before['active']);
        $this->assertSame(0, $after['expired'] - $before['expired'], 'another branch\'s expired voucher was counted');
    }

    public function test_the_cards_ignore_the_lists_filters(): void
    {
        $admin = $this->portal('admin');
        $this->voucher(['code' => 'PRT-ONLYONE', 'is_active' => false]);

        $plain = $this->stats($admin);

        $html = $this->actingAs($admin, 'admin')->get('/admin/vouchers?q=PRT-ONLYONE')->assertOk()->getContent();
        preg_match('/data-stat="total">([\d,]+)</', $html, $m);
        $this->assertSame($plain['total'], (int) str_replace(',', '', $m[1]));
    }

    // ══════════ status and wheel words ══════════

    public function test_the_status_word_follows_the_existing_rules_and_the_fact_comes_before_the_switch(): void
    {
        $this->assertSame('active', $this->voucher()->listStatus());
        $this->assertSame('inactive', $this->voucher(['is_active' => false])->listStatus());
        $this->assertSame('used_up', $this->voucher(['max_uses' => 3, 'used_count' => 3])->listStatus());
        $this->assertSame('expired', $this->voucher(['expires_at' => $this->expiry(-1)])->listStatus());
        // Switched off AND expired / used up: the fact wins.
        $this->assertSame('expired', $this->voucher(['is_active' => false, 'expires_at' => $this->expiry(-1)])->listStatus());
        $this->assertSame('used_up', $this->voucher(['is_active' => false, 'max_uses' => 3, 'used_count' => 3])->listStatus());
        // The last day is still valid; unlimited (max_uses 0) is never used up.
        $this->assertSame('active', $this->voucher(['expires_at' => $this->expiry(0)])->listStatus());
        $this->assertSame('active', $this->voucher(['max_uses' => 0, 'used_count' => 50])->listStatus());
    }

    public function test_the_sql_scopes_agree_with_the_php_status_for_every_state(): void
    {
        $made = [
            $this->voucher(),
            $this->voucher(['is_active' => false]),
            $this->voucher(['max_uses' => 3, 'used_count' => 3]),
            $this->voucher(['expires_at' => $this->expiry(-1)]),
            $this->voucher(['expires_at' => $this->expiry(0)]),
            $this->voucher(['is_active' => false, 'expires_at' => $this->expiry(-4)]),
            $this->voucher(['max_uses' => 0, 'used_count' => 9, 'points_required' => 5]),
        ];
        $ids = collect($made)->pluck('id');

        foreach (Voucher::LIST_STATUSES as $status) {
            $viaSql = Voucher::whereIn('id', $ids)->withListStatus($status)->pluck('id')->sort()->values()->all();
            $viaPhp = collect($made)->filter(fn ($v) => $v->fresh()->listStatus() === $status)->pluck('id')->sort()->values()->all();

            $this->assertSame($viaPhp, $viaSql, "scope and listStatus() disagree for '$status'");
        }
    }

    public function test_on_the_wheel_means_exactly_what_the_prize_picker_picks_from(): void
    {
        $made = [
            $this->voucher(['points_required' => 10]),                                         // yes
            $this->voucher(['points_required' => 0]),                                          // direct: no
            $this->voucher(['points_required' => 10, 'is_active' => false]),                   // off: no
            $this->voucher(['points_required' => 10, 'expires_at' => $this->expiry(-1)]),      // expired: no
            $this->voucher(['points_required' => 10, 'expires_at' => $this->expiry(0)]),       // last day: yes
            $this->voucher(['points_required' => 10, 'max_uses' => 2, 'used_count' => 2]),     // used up: no
            $this->voucher(['points_required' => 10, 'max_uses' => 0, 'used_count' => 7]),     // unlimited: yes
        ];

        // Ask the real picker (AuthController::winnableVoucherFor) over and over,
        // excluding what it already returned, with plenty of points and no branch
        // picked (globals only), until nothing is left.
        $picker = new ReflectionMethod(AuthController::class, 'winnableVoucherFor');
        $picker->setAccessible(true);
        $controller = app(AuthController::class);

        $this->withSession([]);
        $picked = [];
        for ($i = 0; $i < 200; $i++) {
            $won = $picker->invoke($controller, 1_000_000, $picked);
            if ($won === null) {
                break;
            }
            $picked[] = $won->id;
        }

        $expected = Voucher::onWheel()->whereNull('branch_id')->pluck('id')->all();
        sort($picked);
        sort($expected);
        $this->assertSame($expected, $picked, 'the list says a different set of vouchers is on the wheel than the picker uses');

        // And of the seven made here, exactly the three marked "yes" are on it.
        $madeIds = collect($made)->pluck('id')->all();
        $onWheelHere = collect($made)->filter(fn ($v) => $v->fresh()->isOnWheel())->pluck('id')->sort()->values()->all();
        $pickedHere = array_values(array_intersect($picked, $madeIds));
        sort($pickedHere);
        $this->assertCount(3, $onWheelHere);
        $this->assertSame($onWheelHere, $pickedHere);
    }

    // ══════════ the list: search and filters ══════════

    public function test_the_status_filter_lists_only_that_status(): void
    {
        $admin = $this->portal('admin');
        $active = $this->voucher();
        $inactive = $this->voucher(['is_active' => false]);
        $usedUp = $this->voucher(['max_uses' => 2, 'used_count' => 2]);
        $expired = $this->voucher(['expires_at' => $this->expiry(-2)]);

        $expect = ['active' => $active, 'inactive' => $inactive, 'used_up' => $usedUp, 'expired' => $expired];
        foreach ($expect as $status => $voucher) {
            $ids = $this->listed($admin, '?status=' . $status . '&per_page=100');
            $this->assertContains($voucher->id, $ids, "$status filter lost its voucher");

            foreach ($expect as $other => $otherVoucher) {
                if ($other !== $status) {
                    $this->assertNotContains($otherVoucher->id, $ids, "$status filter also listed a $other voucher");
                }
            }
        }

        $this->assertContains($usedUp->id, $this->listed($admin, '?per_page=100'), 'no filter must list everything');
    }

    public function test_search_matches_code_or_description_and_treats_wildcards_literally(): void
    {
        $admin = $this->portal('admin');
        $byCode = $this->voucher(['code' => 'PRT-ZEBRA-1', 'description' => 'plain']);
        $byDesc = $this->voucher(['code' => 'PRT-OTHER-2', 'description' => 'a zebra crossing promo']);
        $none = $this->voucher(['code' => 'PRT-LION-3', 'description' => 'nothing to see']);
        $percent = $this->voucher(['code' => 'PRT-PCT-4', 'description' => '100% off']);

        $ids = $this->listed($admin, '?q=zebra&per_page=100');
        $this->assertContains($byCode->id, $ids);
        $this->assertContains($byDesc->id, $ids);
        $this->assertNotContains($none->id, $ids);

        // A lone % or _ is text, not "match everything".
        $this->assertSame([$percent->id], $this->listed($admin, '?q=' . urlencode('0% o') . '&per_page=100'));
        $this->assertNotContains($none->id, $this->listed($admin, '?q=' . urlencode('%') . '&per_page=100'));
    }

    public function test_the_scope_filter_picks_global_or_one_branch(): void
    {
        $admin = $this->portal('admin');
        $global = $this->voucher(['branch_id' => null]);
        $one = $this->voucher(['branch_id' => 1]);
        $two = $this->voucher(['branch_id' => 2]);

        $g = $this->listed($admin, '?scope=global&per_page=100');
        $this->assertContains($global->id, $g);
        $this->assertNotContains($one->id, $g);

        $b1 = $this->listed($admin, '?scope=1&per_page=100');
        $this->assertContains($one->id, $b1);
        $this->assertNotContains($global->id, $b1);
        $this->assertNotContains($two->id, $b1);

        // The filter offers Global plus the real branches.
        $html = $this->actingAs($admin, 'admin')->get('/admin/vouchers')->getContent();
        $this->assertStringContainsString('<option value="">All scopes</option>', $html);
        $this->assertStringContainsString('<option value="global"', $html);
        foreach (Branch::pluck('id') as $id) {
            $this->assertStringContainsString('<option value="' . $id . '"', $html);
        }
    }

    public function test_a_supervisor_cannot_widen_their_listing_with_a_filter(): void
    {
        $supervisor = $this->portal('supervisor', 1);
        $mine = $this->voucher(['branch_id' => 1]);
        $global = $this->voucher(['branch_id' => null]);
        $other = $this->voucher(['branch_id' => 2]);

        // Asking for another branch's scope is not a filter they have: it is ignored.
        $ids = $this->listed($supervisor, '?scope=2&per_page=100');
        $this->assertContains($mine->id, $ids);
        $this->assertContains($global->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $this->assertNotContains($other->id, $this->listed($supervisor, '?q=' . $other->code . '&per_page=100'));

        // Their scope filter offers Global and their own branch only.
        $html = $this->actingAs($supervisor, 'admin')->get('/admin/vouchers')->getContent();
        $this->assertStringContainsString('<option value="1"', $html);
        $this->assertStringNotContainsString('<option value="2"', $html);
    }

    public function test_garbage_filters_are_ignored_not_errors(): void
    {
        $admin = $this->portal('admin');
        $v = $this->voucher();

        foreach (['?status=bogus', '?scope=abc', '?scope=999999', '?q[]=x', '?status[]=active'] as $query) {
            $this->assertContains($v->id, $this->listed($admin, $query . '&per_page=100'), $query);
        }
    }

    // ══════════ the list's cells ══════════

    public function test_the_valid_column_says_days_left_expired_ago_or_no_expiry(): void
    {
        $admin = $this->portal('admin');
        $left = $this->voucher(['expires_at' => $this->expiry(20)]);
        $ago = $this->voucher(['expires_at' => $this->expiry(-5)]);
        $none = $this->voucher(['expires_at' => null]);
        $last = $this->voucher(['expires_at' => $this->expiry(0)]);

        $html = $this->actingAs($admin, 'admin')->get('/admin/vouchers?per_page=100')->getContent();

        $row = fn (Voucher $v) => $this->between($html, 'data-voucher-row="' . $v->id . '"', '</tr>');
        $this->assertStringContainsString('20d left', $row($left));
        $this->assertStringContainsString('Expired 5d ago', $row($ago));
        $this->assertStringContainsString('No expiry', $row($none));
        $this->assertStringContainsString('Last day', $row($last));
    }

    public function test_the_row_shows_usage_scope_and_status_badges(): void
    {
        $admin = $this->portal('admin');
        $v = $this->voucher(['max_uses' => 8, 'used_count' => 2, 'branch_id' => null, 'points_required' => 12]);
        $b = $this->voucher(['branch_id' => 1, 'is_active' => false]);

        $html = $this->actingAs($admin, 'admin')->get('/admin/vouchers?per_page=100')->getContent();
        $rowV = $this->between($html, 'data-voucher-row="' . $v->id . '"', '</tr>');
        $rowB = $this->between($html, 'data-voucher-row="' . $b->id . '"', '</tr>');

        $this->assertStringContainsString('2 / 8', $rowV);
        $this->assertStringContainsString('aria-valuenow="25"', $rowV);
        $this->assertStringContainsString('>Global<', $rowV);
        $this->assertStringContainsString('vch-status is-active">Active<', $rowV);
        $this->assertStringContainsString('data-wheel="1"', $rowV);
        $this->assertStringContainsString('12 pts', $rowV);
        $this->assertStringContainsString('vch-status is-inactive">Inactive<', $rowB);
        $this->assertStringContainsString('data-wheel="0"', $rowB);
        $this->assertStringContainsString('Main Branch', $rowB);
    }

    // ══════════ the three-dot menu, per role ══════════

    public function test_the_menu_holds_only_what_each_role_may_do(): void
    {
        $mine = $this->voucher(['branch_id' => 1]);
        $global = $this->voucher(['branch_id' => null]);

        $menu = function (string $role, Voucher $voucher) use (&$mine) {
            $user = $this->portal($role, 1);
            $html = $this->actingAs($user, 'admin')->get('/admin/vouchers?per_page=100')->getContent();

            return $this->between($html, 'id="vchMenu' . $voucher->id . '"', '</td>');
        };

        // Owner: edit, issue, delete, and the on/off switch.
        $admin = $menu('admin', $mine);
        $this->assertStringContainsString('openEditVoucherModal(this)', $admin);
        $this->assertStringContainsString(route('admin.vouchers.issue-code', $mine->id), $admin);
        $this->assertStringContainsString(route('admin.vouchers.delete', $mine->id), $admin);

        // Supervisor: edit + issue on their own branch's voucher, never delete.
        $sup = $menu('supervisor', $mine);
        $this->assertStringContainsString('openEditVoucherModal(this)', $sup);
        $this->assertStringContainsString(route('admin.vouchers.issue-code', $mine->id), $sup);
        $this->assertStringNotContainsString('admin/vouchers/' . $mine->id . '"', str_replace(route('admin.vouchers.issue-code', $mine->id), '', $sup));
        $this->assertStringNotContainsString('Delete', $sup);

        // ...and no Edit on a global voucher (read-only for them).
        $this->assertStringNotContainsString('openEditVoucherModal(this)', $menu('supervisor', $global));

        // Staff: Issue code only.
        $staff = $menu('staff', $mine);
        $this->assertStringContainsString(route('admin.vouchers.issue-code', $mine->id), $staff);
        $this->assertStringNotContainsString('openEditVoucherModal', $staff);
        $this->assertStringNotContainsString('Delete', $staff);
    }

    public function test_an_unissuable_voucher_keeps_a_disabled_issue_item_with_the_reason(): void
    {
        $dead = $this->voucher(['is_active' => false]);

        $html = $this->actingAs($this->portal('staff', 1), 'admin')->get('/admin/vouchers?per_page=100')->getContent();
        $menu = $this->between($html, 'id="vchMenu' . $dead->id . '"', '</td>');

        $this->assertStringContainsString('disabled title="' . e($dead->issuanceErrorFor()) . '"', $menu);
        $this->assertStringNotContainsString(route('admin.vouchers.issue-code', $dead->id), $menu);
    }

    public function test_the_on_off_switch_posts_to_the_unchanged_toggle_route_and_only_for_managers(): void
    {
        $mine = $this->voucher(['branch_id' => 1]);
        $global = $this->voucher(['branch_id' => null]);

        $admin = $this->actingAs($this->portal('admin'), 'admin')->get('/admin/vouchers?per_page=100')->getContent();
        $this->assertStringContainsString(route('admin.vouchers.toggle', $mine->id), $admin);

        $sup = $this->actingAs($this->portal('supervisor', 1), 'admin')->get('/admin/vouchers?per_page=100')->getContent();
        $this->assertStringContainsString(route('admin.vouchers.toggle', $mine->id), $sup);
        $this->assertStringNotContainsString(route('admin.vouchers.toggle', $global->id), $sup, 'a supervisor was offered a global voucher\'s switch');

        $staff = $this->actingAs($this->portal('staff', 1), 'admin')->get('/admin/vouchers?per_page=100')->getContent();
        $this->assertStringNotContainsString('/toggle', $staff);

        // Still does what it always did.
        $this->actingAs($this->portal('admin'), 'admin')->put(route('admin.vouchers.toggle', $mine->id))->assertRedirect();
        $this->assertFalse($mine->fresh()->is_active);
    }

    // ══════════ the Create card ══════════

    public function test_the_create_card_has_the_specified_fields_hints_and_buttons(): void
    {
        $html = $this->actingAs($this->portal('admin'), 'admin')->get('/admin/vouchers')->getContent();
        $card = $this->between($html, 'data-testid="create-voucher-card"', 'data-testid="voucher-list-card"');

        // Order of the fields in the grid.
        $labels = ['Voucher Code *', 'Discount Type *', 'Discount Value *', 'Max Uses *', 'Description', 'Minimum Order (₱)', 'Points Required', 'Valid From (Start Date)', 'Expiry Date', 'Branch Scope'];
        $last = -1;
        foreach ($labels as $label) {
            $pos = strpos($card, '>' . $label . '</label>');
            $this->assertNotFalse($pos, "'$label' is missing");
            $this->assertGreaterThan($last, $pos, "'$label' is out of order");
            $last = $pos;
        }

        $this->assertStringContainsString('Fixed (₱)', $card);
        $this->assertStringContainsString('Percent', $card);
        $this->assertStringContainsString('Date when voucher can be used', $card);
        $this->assertStringContainsString('Leave as All Branches for a company-wide promo', $card);
        $this->assertStringContainsString('<option value="">All Branches (global)</option>', $card);
        $this->assertStringContainsString('<button type="reset" class="vch-btn-ghost">Clear</button>', $card);
        $this->assertStringContainsString('Create Voucher', $card);
        $this->assertStringContainsString('action="' . route('admin.vouchers.store') . '"', $card);
    }

    public function test_staff_and_supervisor_never_see_the_branch_picker_and_staff_never_the_create_card(): void
    {
        $sup = $this->actingAs($this->portal('supervisor', 1), 'admin')->get('/admin/vouchers')->getContent();
        $this->assertStringContainsString('data-testid="create-voucher-card"', $sup);
        $this->assertStringNotContainsString('name="branch_id"', $this->between($sup, 'data-testid="create-voucher-card"', 'data-testid="voucher-list-card"'));
        $this->assertStringContainsString('This voucher will be scoped to your own branch.', $sup);

        $staff = $this->actingAs($this->portal('staff', 1), 'admin')->get('/admin/vouchers')->getContent();
        $this->assertStringNotContainsString('data-testid="create-voucher-card"', $staff);
        $this->assertStringNotContainsString('action="' . route('admin.vouchers.store') . '" method="POST"', $staff);
    }

    public function test_the_spin_wheel_bar_is_the_owners_alone_and_keeps_its_toggle(): void
    {
        $admin = $this->actingAs($this->portal('admin'), 'admin')->get('/admin/vouchers')->getContent();
        $this->assertStringContainsString('data-testid="spin-wheel-bar"', $admin);
        $this->assertStringContainsString('Enable or disable the game for customers', $admin);
        $this->assertStringContainsString('action="' . route('admin.game.toggle') . '"', $admin);

        foreach (['supervisor', 'staff'] as $role) {
            $html = $this->actingAs($this->portal($role, 1), 'admin')->get('/admin/vouchers')->getContent();
            $this->assertStringNotContainsString('data-testid="spin-wheel-bar"', $html);
            $this->assertStringNotContainsString(route('admin.game.toggle'), $html);
        }
    }

    // ══════════ phone layout ══════════

    public function test_the_tab_bar_and_the_table_scroll_inside_themselves(): void
    {
        $html = $this->actingAs($this->portal('admin'), 'admin')->get('/admin/vouchers')->getContent();

        $this->assertMatchesRegularExpression('/\.promo-tabs \{[^}]*overflow-x: auto;/', $html);
        $this->assertMatchesRegularExpression('/\.vch-table-wrap \{[^}]*overflow-x: auto;[^}]*max-width: 100%;/', $html);
        $this->assertStringContainsString('placeholder="Search code or description..."', $html);
        $this->assertStringContainsString('All statuses', $html);
        $this->assertStringContainsString('Used up', $html);
    }

    private function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        $this->assertNotFalse($start, "CONTROL: '$from' not on the page");
        $end = strpos($haystack, $to, $start);

        return substr($haystack, $start, ($end === false ? strlen($haystack) : $end) - $start);
    }
}
