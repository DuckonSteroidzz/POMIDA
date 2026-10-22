<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vouchers tab: the search box and the status / scope dropdowns refresh the
 * list in the background instead of reloading the page. The page's script asks
 * the SAME address for just the results region (header X-Vouchers-Fragment: 1).
 *
 * What is pinned here is the server half of that: the fragment is only the
 * list area, it is exactly what the full page would list for the same filters,
 * it goes through the same auth + role + branch scope, and a normal page load
 * (no header, no JavaScript) still returns the whole filtered page. The browser
 * half (no navigation, URL, scroll, focus, row actions after a swap) is proved
 * in a real browser; see the Playwright run in the change's report.
 */
class VoucherListBackgroundFilterTest extends TestCase
{
    use DatabaseTransactions;

    private const FRAGMENT = ['X-Vouchers-Fragment' => '1'];

    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        // Every fixture code carries this, and every request searches for it, so
        // the live-copy rows in the testing database never leak into an assertion.
        $this->tag = 'BGF' . strtoupper(Str::random(6));
    }

    private function portal(string $role, ?int $branchId = null): User
    {
        return User::create([
            'name'              => 'Bgf ' . $role,
            'email'             => 'bgf-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : ($branchId ?? 1),
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function voucher(string $suffix, array $attrs = []): Voucher
    {
        return Voucher::create($attrs + [
            'code'            => $this->tag . '-' . $suffix,
            'description'     => 'Background filter test',
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

    /** Row ids listed in a response body, in order. */
    private function rows(string $html): array
    {
        preg_match_all('/data-voucher-row="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    private function fragment(User $user, string $query)
    {
        return $this->actingAs($user, 'admin')->withHeaders(self::FRAGMENT)->get('/admin/vouchers?' . $query);
    }

    private function page(User $user, string $query)
    {
        return $this->actingAs($user, 'admin')->get('/admin/vouchers?' . $query);
    }

    public function test_a_background_request_returns_only_the_results_region(): void
    {
        $v = $this->voucher('A');

        $html = $this->fragment($this->portal('admin'), 'q=' . $this->tag)->assertOk()->getContent();

        $this->assertStringContainsString('id="vchResults"', $html);
        $this->assertSame([$v->id], $this->rows($html));

        // None of the page around it: not the layout, the stat cards, the
        // create form, the filter form, or the edit modal.
        foreach (['<html', '<body', 'voucher-stats', 'create-voucher-card', 'voucher-filters', 'editVoucherModal', 'promo-tabs'] as $page) {
            $this->assertStringNotContainsString($page, $html, "fragment leaked page part: $page");
        }
    }

    public function test_the_fragment_is_never_cached_and_says_it_varies(): void
    {
        $response = $this->fragment($this->portal('admin'), 'q=' . $this->tag)->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('X-Vouchers-Fragment', $response->headers->get('Vary'));
    }

    public function test_without_the_header_the_same_address_is_still_the_whole_page(): void
    {
        $v = $this->voucher('A', ['is_active' => false]);

        $html = $this->page($this->portal('admin'), 'status=inactive&q=' . $this->tag)->assertOk()->getContent();

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('voucher-stats', $html);
        $this->assertStringContainsString('voucher-filters', $html);
        $this->assertSame([$v->id], $this->rows($html));
        // The chosen filter values are what the controls show on a direct visit.
        $this->assertMatchesRegularExpression('/<option value="inactive"\s+selected>/', $html);
        $this->assertMatchesRegularExpression('/name="q" value="' . preg_quote($this->tag, '/') . '"/', $html);
    }

    public function test_the_fragment_lists_exactly_what_the_full_page_lists_for_the_same_filters(): void
    {
        $admin = $this->portal('admin');
        $this->voucher('ACT');
        $this->voucher('OFF', ['is_active' => false]);
        $this->voucher('GLB', ['branch_id' => null]);
        $this->voucher('BR1', ['branch_id' => 1]);
        $this->voucher('BR2', ['branch_id' => 2]);

        foreach (['', '&status=active', '&status=inactive', '&scope=global', '&scope=1', '&scope=2', '&status=active&scope=1'] as $extra) {
            $query = 'q=' . $this->tag . $extra;
            $this->assertSame(
                $this->rows($this->page($admin, $query)->assertOk()->getContent()),
                $this->rows($this->fragment($admin, $query)->assertOk()->getContent()),
                "fragment and full page disagree for: $query"
            );
        }

        // And the filters do narrow it (so the comparison above is not vacuous).
        $this->assertCount(5, $this->rows($this->fragment($admin, 'q=' . $this->tag)->getContent()));
        $this->assertCount(1, $this->rows($this->fragment($admin, 'q=' . $this->tag . '&status=inactive')->getContent()));
        $this->assertCount(1, $this->rows($this->fragment($admin, 'q=' . $this->tag . '&scope=2')->getContent()));
    }

    public function test_the_fragment_reports_the_total_and_whether_filters_are_on(): void
    {
        $admin = $this->portal('admin');
        $this->voucher('A');
        $this->voucher('B');

        $filtered = $this->fragment($admin, 'q=' . $this->tag)->getContent();
        $this->assertStringContainsString('data-total="2"', $filtered);
        $this->assertStringContainsString('data-filters-on="1"', $filtered);

        $none = $this->fragment($admin, 'q=' . $this->tag . 'ZZZ')->getContent();
        $this->assertStringContainsString('data-total="0"', $none);
        $this->assertStringContainsString('No vouchers match these filters', $none);

        $unfiltered = $this->fragment($admin, '')->getContent();
        $this->assertStringContainsString('data-filters-on="0"', $unfiltered);
    }

    public function test_the_fragment_paginates_and_its_pager_links_keep_the_filters(): void
    {
        $admin = $this->portal('admin');
        for ($i = 1; $i <= 20; $i++) {
            $this->voucher(sprintf('P%02d', $i));
        }

        $first = $this->fragment($admin, 'q=' . $this->tag . '&per_page=15')->assertOk()->getContent();
        $second = $this->fragment($admin, 'q=' . $this->tag . '&per_page=15&page=2')->assertOk()->getContent();

        $this->assertCount(15, $this->rows($first));
        $this->assertCount(5, $this->rows($second));
        $this->assertSame([], array_intersect($this->rows($first), $this->rows($second)));
        $this->assertStringContainsString('of 20 vouchers', $first);

        // Pager hrefs carry the search and page size, so a swap to page 2 is
        // the same filtered list the full page would show.
        preg_match('/href="([^"]*page=2[^"]*)"/', $first, $m);
        $href = html_entity_decode($m[1] ?? '');
        $this->assertStringContainsString('q=' . $this->tag, $href);
        $this->assertStringContainsString('per_page=15', $href);
    }

    public function test_a_supervisor_gets_only_their_branch_and_the_globals_through_the_fragment(): void
    {
        $supervisor = $this->portal('supervisor', 1);
        $own = $this->voucher('OWN', ['branch_id' => 1]);
        $global = $this->voucher('GLB', ['branch_id' => null]);
        $other = $this->voucher('OTH', ['branch_id' => 2]);

        $rows = $this->rows($this->fragment($supervisor, 'q=' . $this->tag)->assertOk()->getContent());

        $this->assertEqualsCanonicalizing([$own->id, $global->id], $rows);
        $this->assertNotContains($other->id, $rows);

        // Asking for the other branch's scope by hand widens nothing.
        $forced = $this->rows($this->fragment($supervisor, 'q=' . $this->tag . '&scope=2')->getContent());
        $this->assertNotContains($other->id, $forced);
    }

    public function test_a_supervisor_can_edit_only_their_own_branchs_rows_in_the_fragment(): void
    {
        $supervisor = $this->portal('supervisor', 1);
        $own = $this->voucher('OWN', ['branch_id' => 1]);
        $global = $this->voucher('GLB', ['branch_id' => null]);

        $html = $this->fragment($supervisor, 'q=' . $this->tag)->getContent();

        // Edit buttons carry data-id; only the supervisor's own voucher has one
        // (drawn twice since Oct 2026: the table row and the phone card).
        preg_match_all('/class="vch-menu-item"\s+data-id="(\d+)"/', $html, $m);
        $this->assertSame([(string) $own->id], array_values(array_unique($m[1])));
        $this->assertStringNotContainsString('data-id="' . $global->id . '"', $html);
        // The owner-only Delete never appears for a supervisor.
        $this->assertStringNotContainsString('vch-menu-item is-danger', $html);
    }

    public function test_staff_get_the_branch_scoped_read_only_list_through_the_fragment(): void
    {
        $staff = $this->portal('staff', 1);
        $own = $this->voucher('OWN', ['branch_id' => 1]);
        $other = $this->voucher('OTH', ['branch_id' => 2]);

        $html = $this->fragment($staff, 'q=' . $this->tag)->assertOk()->getContent();

        // Same rows the full page gives staff today.
        $this->assertSame(
            $this->rows($this->page($staff, 'q=' . $this->tag)->getContent()),
            $this->rows($html)
        );
        $this->assertContains($own->id, $this->rows($html));
        $this->assertNotContains($other->id, $this->rows($html));

        // Read-only: no activate/deactivate, no Edit, no Delete, no live Wheel
        // switch — just Issue Voucher.
        $this->assertStringNotContainsString('/toggle"', $html);
        $this->assertStringNotContainsString('Edit Voucher', $html);
        $this->assertStringNotContainsString('vch-menu-item is-danger', $html);
        $this->assertStringNotContainsString('/wheel"', $html);
        $this->assertStringContainsString('Issue Voucher', $html);
    }

    public function test_the_owner_gets_the_row_controls_through_the_fragment(): void
    {
        // A points voucher, so its Wheel switch is live (a 0-point one is disabled).
        $a = $this->voucher('A', ['points_required' => 10]);

        $html = $this->fragment($this->portal('admin'), 'q=' . $this->tag)->getContent();

        $this->assertStringContainsString(route('admin.vouchers.toggle', $a->id), $html);
        $this->assertStringContainsString(route('admin.vouchers.wheel', $a->id), $html);
        $this->assertStringContainsString('Edit Voucher', $html);
        $this->assertStringContainsString('vch-menu-item is-danger', $html);
        $this->assertStringContainsString('data-vch-menu-btn', $html);
        // Each swapped-in form carries its own CSRF token.
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_a_signed_out_background_request_is_sent_to_login_not_given_the_list(): void
    {
        $this->voucher('A');

        $response = $this->withHeaders(self::FRAGMENT)->get('/admin/vouchers?q=' . $this->tag);

        $response->assertRedirect();
        $this->assertStringNotContainsString($this->tag, (string) $response->getContent());
    }

    public function test_the_page_wires_the_filters_to_the_background_swap(): void
    {
        $html = $this->page($this->portal('admin'), 'q=' . $this->tag)->assertOk()->getContent();

        // The old whole-page submit on change is gone from the filters (the
        // owner's Spin Wheel switch still submits its own small form); the swap
        // region, the error slot and the script that drives them are there.
        $filters = substr($html, strpos($html, 'data-testid="voucher-filters"'));
        $filters = substr($filters, 0, strpos($filters, '</form>'));
        $this->assertStringContainsString('name="status"', $filters);
        $this->assertStringNotContainsString('onchange=', $filters);
        $this->assertStringContainsString('id="vchResults"', $html);
        $this->assertStringContainsString('id="vchError"', $html);
        $this->assertStringContainsString("'X-Vouchers-Fragment': '1'", $html);
        $this->assertStringContainsString('history.replaceState', $html);
        // The no-JavaScript fallback is still a real GET form with a button.
        $this->assertMatchesRegularExpression('/<form action="[^"]*admin\/vouchers" method="GET" class="vch-toolbar"/', $html);
        $this->assertStringContainsString('<noscript><button type="submit"', $html);
    }
}
