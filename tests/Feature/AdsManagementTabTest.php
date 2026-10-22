<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Promotions & Rewards > Ads Management, redesigned to the tester's reference
 * (Defect Report 3 part B, docs/design-refs/peachy-promotions-rewards.html).
 *
 *  - Four stat cards (Total ads, Live now, Game Page, Menu Page) counting what
 *    the viewer may see in the branch they are viewing.
 *  - The Create Advertisement form: drop-zone image, placement radio cards,
 *    owner-only branch choice (a supervisor sees their own branch, never posted).
 *  - The Advertisements table: search + placement + status filters swapped in
 *    the background (X-Ads-Fragment), a Status badge (Live / Inactive / Expired
 *    / Scheduled) that agrees with what customers are shown (Ad::liveNow), the
 *    shared ON/OFF switch on the unchanged admin.ads.toggle route (JSON sets the
 *    sent state; a plain post still flips), and a ⋮ menu with Edit / Delete.
 *  - The "Viewing:" bar really narrows the list and the cards: one branch =
 *    its own ads + the All-Branches ones.
 *  - Roles unchanged: supervisor = own branch, company-wide read-only (403),
 *    another branch's 404 and never listed; staff have no Ads access at all.
 */
class AdsManagementTabTest extends TestCase
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
            'name'      => 'AMT ' . $label . ' ' . Str::lower(Str::random(6)),
            'code'      => 'AMT' . strtoupper(Str::random(6)),
            'address'   => 'Ads tab test address',
            'is_active' => true,
        ]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        return User::create([
            'name'              => 'AMT ' . $role,
            'email'             => 'amt-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : $branch?->id,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function ad(?Branch $branch, array $attrs = []): Ad
    {
        return Ad::create($attrs + [
            'title'         => 'AMT Ad ' . Str::random(8),
            'description'   => 'ads tab test',
            'placement'     => 'menu',
            'is_active'     => true,
            'display_order' => 0,
            'branch_id'     => $branch?->id,
        ]);
    }

    /** @return int[] the ad ids drawn as table rows */
    private function rowIds(string $html): array
    {
        preg_match_all('/data-ad-row="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    private function stat(string $html, string $key): int
    {
        $this->assertSame(1, preg_match('/data-ad-stat="' . $key . '">([\d,]+)</', $html, $m), "stat card $key missing");

        return (int) str_replace(',', '', $m[1]);
    }

    private function stats(string $html): array
    {
        return array_combine(['total', 'live', 'game', 'menu'], array_map(fn ($k) => $this->stat($html, $k), ['total', 'live', 'game', 'menu']));
    }

    /** One ad's table row, from its <tr> to the next one. */
    private function row(string $html, int $id): string
    {
        $this->assertSame(1, preg_match('/<tr data-ad-row="' . $id . '".*?<\/tr>/s', $html, $m), "row for ad $id missing");

        return $m[0];
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "$from missing");
        $end = strpos($html, $to, $start);
        $this->assertNotFalse($end, "$to missing");

        return substr($html, $start, $end - $start);
    }

    // ══════════ the layout follows the reference ══════════

    public function test_the_page_has_the_references_cards_form_and_table(): void
    {
        $ad = $this->ad(null, ['link' => 'https://peachy.test/promo']);
        $html = $this->actingAs($this->user('admin'), 'admin')->get('/admin/ads?branch=all')->assertOk()->getContent();

        foreach (['Total ads', 'Live now', 'Game Page', 'Menu Page'] as $label) {
            $this->assertStringContainsString('<p class="ads-stat-label">' . $label . '</p>', $html);
        }

        $form = $this->between($html, 'data-testid="create-ad-card"', 'data-testid="ad-list-card"');
        $this->assertStringContainsString('Create Advertisement', $form);
        $this->assertMatchesRegularExpression('/<input type="text" id="adsTitle" name="title"[^>]*required/', $form);
        $this->assertMatchesRegularExpression('/<input type="url" id="adsLink" name="link"/', $form);
        $this->assertMatchesRegularExpression('/<textarea id="adsDescription" name="description"/', $form);
        $this->assertMatchesRegularExpression('/<label class="ads-drop" for="adsImage"[^>]*>\s*<input type="file" id="adsImage" name="image"/', $form);
        $this->assertStringContainsString('Tap to upload an image', $form);
        $this->assertMatchesRegularExpression('/<input type="radio" name="placement" value="game" required checked>/', $form);
        $this->assertStringContainsString('name="starts_at"', $form);
        $this->assertStringContainsString('name="ends_at"', $form);
        $this->assertStringContainsString('<select id="adsBranch" name="branch_id"', $form);
        $this->assertStringContainsString('<button type="reset" class="ads-btn-ghost">Clear</button>', $form);
        $this->assertStringContainsString('Publish Ad', $form);
        $this->assertStringContainsString('enctype="multipart/form-data"', $form);

        $list = $this->between($html, 'data-testid="ad-list-card"', 'id="editAdModal"');
        $this->assertStringContainsString('<option value="">All placements</option>', $list);
        $this->assertStringContainsString('<option value="">All statuses</option>', $list);
        preg_match_all('/<th>([^<]+)<\/th>/', $list, $th);
        $this->assertSame(['Ad', 'Placement', 'Schedule', 'Scope', 'Status', 'Active', 'Actions'], $th[1]);

        // The row: title + description + link, the scope badge, the shared
        // switch with ON/OFF written in it, and a ⋮ menu with Edit and Delete.
        $row = $this->row($html, $ad->id);
        $this->assertStringContainsString(e($ad->title), $row);
        $this->assertStringContainsString('ads tab test', $row);
        $this->assertStringContainsString('href="https://peachy.test/promo"', $row);
        $this->assertStringContainsString('🌐</span> Global', $row);
        $this->assertMatchesRegularExpression('/<input type="checkbox" role="switch" class="pc-switch-input" data-ad-active="' . $ad->id . '" data-url="[^"]+\/admin\/ads\/' . $ad->id . '\/toggle"/', $row);
        $this->assertStringContainsString('<span class="pc-switch-text is-on">ON</span><span class="pc-switch-text is-off">OFF</span>', $row);
        $this->assertStringContainsString('class="ads-kebab" data-ads-menu-btn', $row);
        $menu = $this->between($row, 'id="adsMenu' . $ad->id . '"', '</td>');
        $this->assertStringContainsString('</span> Edit', $menu);
        $this->assertStringContainsString('</span> Delete', $menu);
        $this->assertStringContainsString('hidden', substr($menu, 0, 80), 'the menu is closed (display:none) until opened');

        // ...and a phone card for the same ad, with its own copy of both.
        $this->assertStringContainsString('data-ad-card="' . $ad->id . '"', $html);
        $this->assertSame(2, substr_count($html, 'data-ad-active="' . $ad->id . '"'));
        $this->assertStringContainsString('id="adsMenuM' . $ad->id . '"', $html);
    }

    public function test_status_badges_say_what_customers_are_shown(): void
    {
        $admin = $this->user('admin');
        $live = $this->ad(null, ['starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
        $off = $this->ad(null, ['is_active' => false]);
        $expired = $this->ad(null, ['ends_at' => now()->subDays(2)]);
        $scheduled = $this->ad(null, ['starts_at' => now()->addDays(3)]);
        // Switched off wins over the dates, as in the reference.
        $offAndExpired = $this->ad(null, ['is_active' => false, 'ends_at' => now()->subDays(2)]);

        $html = $this->actingAs($admin, 'admin')->get('/admin/ads?branch=all&q=AMT')->assertOk()->getContent();

        foreach ([[$live, 'live', 'Live'], [$off, 'inactive', 'Inactive'], [$expired, 'expired', 'Expired'],
                  [$scheduled, 'scheduled', 'Scheduled'], [$offAndExpired, 'inactive', 'Inactive']] as [$ad, $key, $label]) {
            $this->assertStringContainsString('<span class="ads-status is-' . $key . '" data-ad-status="' . $ad->id . '">' . $label . '</span>', $this->row($html, $ad->id));
            $this->assertSame($key, $ad->fresh()->listStatus());
        }

        // The badge and the filter are one rule, and "Live" is exactly liveNow().
        $liveNow = Ad::liveNow()->pluck('id')->all();
        foreach ([$live, $off, $expired, $scheduled, $offAndExpired] as $ad) {
            $this->assertSame($ad->fresh()->listStatus() === 'live', in_array($ad->id, $liveNow, true));
        }
        foreach (Ad::LIST_STATUSES as $status) {
            $sqlIds = Ad::withListStatus($status)->pluck('id')->sort()->values()->all();
            $phpIds = Ad::all()->filter(fn ($a) => $a->listStatus() === $status)->pluck('id')->sort()->values()->all();
            $this->assertSame($phpIds, $sqlIds, "withListStatus('$status') disagrees with listStatus()");
        }

        // The switch shows is_active, whatever the badge says.
        $this->assertMatchesRegularExpression('/data-ad-active="' . $expired->id . '"[^>]*checked/', $this->row($html, $expired->id));
        $this->assertDoesNotMatchRegularExpression('/data-ad-active="' . $off->id . '"[^>]*checked/', $this->row($html, $off->id));
    }

    // ══════════ search + filters, in the background ══════════

    public function test_search_placement_and_status_filters_narrow_the_list(): void
    {
        $admin = $this->user('admin');
        $tag = 'AMTF' . Str::upper(Str::random(5));
        $gameLive = $this->ad(null, ['title' => $tag . ' game', 'placement' => 'game']);
        $menuLive = $this->ad(null, ['title' => $tag . ' menu']);
        $menuOff = $this->ad(null, ['title' => 'other', 'description' => 'has ' . $tag . ' inside', 'is_active' => false]);
        $as = fn () => $this->actingAs($admin, 'admin');

        $ids = fn (string $query) => $this->rowIds($as()->get('/admin/ads?branch=all&' . $query)->assertOk()->getContent());

        $this->assertEqualsCanonicalizing([$gameLive->id, $menuLive->id, $menuOff->id], $ids('q=' . $tag), 'search covers title and description');
        $this->assertSame([$gameLive->id], $ids('q=' . $tag . '&placement=game'));
        $this->assertEqualsCanonicalizing([$menuLive->id, $menuOff->id], $ids('q=' . $tag . '&placement=menu'));
        $this->assertSame([$menuOff->id], $ids('q=' . $tag . '&status=inactive'));
        $this->assertEqualsCanonicalizing([$gameLive->id, $menuLive->id], $ids('q=' . $tag . '&status=live'));

        // Junk is "no filter", never an error or a wider scope.
        $this->assertEqualsCanonicalizing([$gameLive->id, $menuLive->id, $menuOff->id], $ids('q=' . $tag . '&placement=cart&status=nope'));
        $as()->get('/admin/ads?q[]=x&placement[]=game&status[]=live')->assertOk();

        // The filters keep their values on a full load.
        $html = $as()->get('/admin/ads?branch=all&placement=menu&status=inactive')->getContent();
        $this->assertStringContainsString('<option value="menu" selected>Menu Page</option>', $html);
        $this->assertStringContainsString('<option value="inactive" selected>Inactive</option>', $html);
        $this->assertDoesNotMatchRegularExpression('/id="adsClearFilters"[^>]*hidden/', $html);
    }

    public function test_the_background_fragment_is_only_the_results_with_the_counts(): void
    {
        $admin = $this->user('admin');
        $ad = $this->ad($this->mine, ['placement' => 'game']);

        $response = $this->actingAs($admin, 'admin')
            ->withHeaders(['X-Ads-Fragment' => '1'])
            ->get('/admin/ads?branch=' . $this->mine->id . '&q=' . urlencode($ad->title))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringStartsWith('<div id="adsResults"', ltrim(preg_replace('/^\s*<style>.*?<\/style>/s', '', $html)));
        $this->assertStringNotContainsString('create-ad-card', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertSame([$ad->id], $this->rowIds($html));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('X-Ads-Fragment', $response->headers->get('Vary'));
        foreach (['total', 'live', 'game', 'menu'] as $key) {
            $this->assertMatchesRegularExpression('/data-stat-' . $key . '="\d+"/', $html);
        }
        // The switch inside a swapped fragment still carries its URL, so the
        // page's one delegated listener keeps working after a refresh.
        $this->assertStringContainsString('data-ad-active="' . $ad->id . '" data-url=', $html);
    }

    // ══════════ stat cards + the Viewing branch ══════════

    public function test_the_viewing_bar_narrows_the_list_and_the_cards(): void
    {
        $admin = $this->user('admin');
        $as = fn () => $this->actingAs($admin, 'admin');

        $allBefore = $this->stats($as()->get('/admin/ads?branch=all')->getContent());
        $mineBefore = $this->stats($as()->get('/admin/ads?branch=' . $this->mine->id)->getContent());

        $own = $this->ad($this->mine, ['placement' => 'game']);
        $ownOff = $this->ad($this->mine, ['is_active' => false]);
        $global = $this->ad(null);
        $other = $this->ad($this->theirs, ['placement' => 'game']);

        // One branch: its own ads + the All-Branches ones; never another branch's.
        $response = $as()->get('/admin/ads?branch=' . $this->mine->id);
        $response->assertSessionHas('selected_branch_id', $this->mine->id);
        $html = $response->getContent();
        $ids = $this->rowIds($html);
        $this->assertContains($own->id, $ids);
        $this->assertContains($ownOff->id, $ids);
        $this->assertContains($global->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertSame(['total' => $mineBefore['total'] + 3, 'live' => $mineBefore['live'] + 2, 'game' => $mineBefore['game'] + 1, 'menu' => $mineBefore['menu'] + 2], $this->stats($html));
        $this->assertStringContainsString('name="branch" value="' . $this->mine->id . '" data-ads-viewing', $html);

        // Remembered: the next load with no ?branch stays on that branch.
        $this->assertNotContains($other->id, $this->rowIds($as()->withSession(['selected_branch_id' => $this->mine->id])->get('/admin/ads')->getContent()));

        // All Branches: everything.
        $html = $as()->get('/admin/ads?branch=all')->getContent();
        $this->assertEmpty(array_diff([$own->id, $ownOff->id, $global->id, $other->id], $this->rowIds($html)));
        $this->assertSame(['total' => $allBefore['total'] + 4, 'live' => $allBefore['live'] + 3, 'game' => $allBefore['game'] + 2, 'menu' => $allBefore['menu'] + 2], $this->stats($html));

        // The cards follow the Viewing branch, not the filters.
        $this->assertSame($this->stats($html), $this->stats($as()->get('/admin/ads?branch=all&status=inactive&placement=game')->getContent()));
    }

    // ══════════ the Active switch ══════════

    public function test_the_switch_sets_the_state_it_sends_and_reports_back(): void
    {
        $admin = $this->user('admin');
        $ad = $this->ad($this->mine, ['placement' => 'game']);
        $before = $this->stats($this->actingAs($admin, 'admin')->get('/admin/ads?branch=all')->getContent());

        $off = $this->actingAs($admin, 'admin')->putJson(route('admin.ads.toggle', $ad->id), ['is_active' => false])->assertOk();
        $off->assertJson(['id' => $ad->id, 'is_active' => false, 'status' => 'inactive']);
        $this->assertFalse($ad->fresh()->is_active);
        $this->assertSame($before['live'] - 1, $off->json('stats.live'));
        $this->assertSame($before['total'], $off->json('stats.total'));

        // SET, not flip: the same request twice leaves it off.
        $this->actingAs($admin, 'admin')->putJson(route('admin.ads.toggle', $ad->id), ['is_active' => false])->assertOk()->assertJson(['is_active' => false]);
        $this->assertFalse($ad->fresh()->is_active);

        $this->actingAs($admin, 'admin')->putJson(route('admin.ads.toggle', $ad->id), ['is_active' => true])->assertOk()->assertJson(['is_active' => true, 'status' => 'live']);
        $this->assertTrue($ad->fresh()->is_active);

        // A switched-on ad that has ended reports Expired, not Live.
        $ended = $this->ad(null, ['is_active' => false, 'ends_at' => now()->subDay()]);
        $this->actingAs($admin, 'admin')->putJson(route('admin.ads.toggle', $ended->id), ['is_active' => true])->assertJson(['is_active' => true, 'status' => 'expired']);

        // A junk value is refused and changes nothing.
        $this->actingAs($admin, 'admin')->putJson(route('admin.ads.toggle', $ad->id), ['is_active' => 'maybe'])->assertStatus(422);
        $this->assertTrue($ad->fresh()->is_active);

        // The plain form post is unchanged: it flips and redirects.
        $this->actingAs($admin, 'admin')->put(route('admin.ads.toggle', $ad->id))->assertRedirect(route('admin.ads'));
        $this->assertFalse($ad->fresh()->is_active);
    }

    public function test_a_refused_switch_is_a_json_error_and_changes_nothing(): void
    {
        $supervisor = $this->user('supervisor', $this->mine);
        $global = $this->ad(null);
        $other = $this->ad($this->theirs);
        $own = $this->ad($this->mine);

        $this->actingAs($supervisor, 'admin')->putJson(route('admin.ads.toggle', $global->id), ['is_active' => false])
            ->assertForbidden()->assertJson(['message' => 'Company-wide ads can only be managed by the owner.']);
        $this->actingAs($supervisor, 'admin')->putJson(route('admin.ads.toggle', $other->id), ['is_active' => false])->assertNotFound();
        $this->assertTrue($global->fresh()->is_active);
        $this->assertTrue($other->fresh()->is_active);

        $this->actingAs($supervisor, 'admin')->putJson(route('admin.ads.toggle', $own->id), ['is_active' => false])->assertOk()->assertJson(['is_active' => false]);
        $this->assertFalse($own->fresh()->is_active);

        // Staff have no Ads access at all: the role gate turns them away.
        $staff = $this->user('staff', $this->mine);
        $response = $this->actingAs($staff, 'admin')->putJson(route('admin.ads.toggle', $own->id), ['is_active' => true]);
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertFalse($own->fresh()->is_active);
    }

    // ══════════ roles ══════════

    public function test_a_supervisor_gets_own_branch_controls_and_read_only_globals(): void
    {
        $supervisor = $this->user('supervisor', $this->mine);
        $own = $this->ad($this->mine);
        $global = $this->ad(null);
        $other = $this->ad($this->theirs);

        // A crafted ?branch= means nothing to them, and is never remembered.
        $response = $this->actingAs($supervisor, 'admin')->get('/admin/ads?branch=' . $this->theirs->id)->assertOk();
        $response->assertSessionMissing('selected_branch_id');
        $html = $response->getContent();

        $ids = $this->rowIds($html);
        $this->assertContains($own->id, $ids);
        $this->assertContains($global->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertStringNotContainsString($other->title, $html);
        $this->assertStringNotContainsString($this->theirs->name, $html);
        $this->assertStringNotContainsString('name="branch" value=', $html, 'a supervisor was given a Viewing branch to carry');

        // Own branch: a live switch and Edit / Delete.
        $row = $this->row($html, $own->id);
        $this->assertStringContainsString('data-url="' . route('admin.ads.toggle', $own->id) . '"', $row);
        $this->assertStringContainsString('</span> Edit', $row);
        $this->assertStringContainsString(route('admin.ads.delete', $own->id), $row);

        // Company-wide: a disabled switch with the reason, a "Read-only" menu.
        $row = $this->row($html, $global->id);
        $this->assertMatchesRegularExpression('/<label class="pc-switch"\s+title="Company-wide ads can only be changed by the owner."\s*>\s*<input[^>]*data-ad-active="' . $global->id . '"[^>]*disabled/', $row);
        $this->assertStringNotContainsString('data-url=', $row);
        $this->assertStringContainsString('</span> Read-only', $row);
        $this->assertStringNotContainsString('</span> Edit', $row);
        $this->assertStringNotContainsString(route('admin.ads.delete', $global->id), $html);
        $this->assertStringNotContainsString(route('admin.ads.toggle', $global->id), $html);

        // The create form and the edit modal show their branch, never post one.
        $form = $this->between($html, 'data-testid="create-ad-card"', 'data-testid="ad-list-card"');
        $this->assertMatchesRegularExpression('/<select id="adsBranch" class="form-control-custom" disabled>\s*<option>' . preg_quote(e($this->mine->name), '/') . '<\/option>/', $form);
        $this->assertStringNotContainsString('name="branch_id"', $html);
        $this->assertStringNotContainsString('All Branches (global)', $html);
    }

    public function test_staff_still_have_no_ads_tab(): void
    {
        $staff = $this->user('staff', $this->mine);
        $ad = $this->ad($this->mine);

        $this->assertNotSame(200, $this->actingAs($staff, 'admin')->get('/admin/ads')->getStatusCode());
        $this->assertNotSame(200, $this->actingAs($staff, 'admin')->withHeaders(['X-Ads-Fragment' => '1'])->get('/admin/ads')->getStatusCode());

        $html = $this->actingAs($staff, 'admin')->get('/admin/vouchers')->assertOk()->getContent();
        $this->assertStringNotContainsString(route('admin.ads'), $html);
        $this->assertStringNotContainsString($ad->title, $html);
    }

    // ══════════ hardening that came with it ══════════

    public function test_a_quote_in_a_title_cannot_break_the_delete_confirmation(): void
    {
        $ad = $this->ad(null, ['title' => "Bob's \"deal\"'); window.__x=1;//"]);
        $html = $this->actingAs($this->user('admin'), 'admin')->get('/admin/ads?branch=all')->getContent();

        $this->assertStringContainsString('data-confirm="Delete ad ' . e($ad->title) . '?" onsubmit="return confirm(this.dataset.confirm)"', $html);
        $this->assertStringNotContainsString("confirm('Delete ad", $html);
    }

    public function test_a_refused_save_now_says_why(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'admin')->from('/admin/ads')
            ->post(route('admin.ads.store'), ['title' => '', 'placement' => 'cart'])
            ->assertRedirect('/admin/ads');

        $html = $this->actingAs($admin, 'admin')->get('/admin/ads')->getContent();
        $this->assertStringContainsString('data-testid="ad-errors"', $html);
        $this->assertStringContainsString('The ad was not saved', $html);
    }
}
