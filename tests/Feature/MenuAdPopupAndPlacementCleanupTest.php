<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Branch;
use App\Models\User;
use App\Services\TableEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Batch 3 — Ads placement cleanup + the Menu-page popup.
 *
 * THE PROBLEM. The admin Ads form offered Game / Menu / Cart / Orders, but ads
 * were only ever displayed on the game page, so three of the four choices did
 * nothing. Now: only Game and Menu exist (Ad::PLACEMENTS, validated as
 * in:game,menu), and a Menu ad is shown as a dismissible popup, once per
 * session, when a customer with a branch opens the menu.
 *
 * ROWS ALREADY SAVED WITH cart/orders are neither deleted nor migrated. No
 * customer query ever selects them, admin lists them with a "No longer used"
 * label, and the edit form opens them on a "Choose a placement…" option that
 * `required` refuses until an admin picks a live one. The `placement` column is
 * still an ENUM of four values on purpose (see Ad::PLACEMENTS), so no migration
 * is involved anywhere in this batch.
 *
 * DATA HYGIENE. Every row is created inside DatabaseTransactions, so nothing
 * survives the run in pomida_db_testing, and no pre-existing row is selected
 * for mutation. The existing live game ad (#10) is only ever READ, indirectly,
 * by the game-page assertion.
 */
class MenuAdPopupAndPlacementCleanupTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MADPOP';
    private const POPUP = 'id="menuAdPopup"';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'pomida_db_testing',
            DB::selectOne('select database() as d')->d,
            'MenuAdPopupAndPlacementCleanupTest must only ever run against pomida_db_testing.'
        );
    }

    // ══════════════════ fixtures ══════════════════

    private function branch(string $label): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' ' . $label . ' ' . uniqid(),
            'code'      => 'MAD' . strtoupper(substr(uniqid(), -7)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function ad(string $placement, ?int $branchId = null, array $extra = []): Ad
    {
        return Ad::create(array_merge([
            'branch_id'  => $branchId,
            'title'      => self::PREFIX . ' ' . $placement . ' ' . uniqid(),
            'placement'  => $placement,
            'is_active'  => true,
        ], $extra));
    }

    private function owner(): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Owner',
            'email'     => strtolower(self::PREFIX) . '-owner-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => 'admin',
            'branch_id' => null,
            'is_active' => true,
        ]);
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

    /** A Pick-Up customer at $branchId opening the menu. */
    private function menuAt(?int $branchId): string
    {
        $session = ['order_type' => 'pick_up'];
        if ($branchId !== null) {
            $session['branch_id'] = $branchId;
        }

        return $this->withSession($session)->get(route('customer.menu'))->assertOk()->getContent();
    }

    /** A Dine-In QR guest at $branch, landed on the menu the real way. */
    private function dineInGuestAt(Branch $branch): string
    {
        $code = TableEntry::findOrRegister($branch->id, '1')->code;

        $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', [
                'tableData' => "branch_id={$branch->id}&table=1&k={$code}",
                'next'      => 'guest',
            ])->assertRedirect(route('customer.menu'));

        $this->assertSame('dine_in', session('order_type'));
        $this->assertSame($branch->id, session('branch_id'));

        return $this->get(route('customer.menu'))->assertOk()->getContent();
    }

    // ══════════════════ 1. Validation: only game + menu ══════════════════

    public function test_store_refuses_cart_and_orders_placements(): void
    {
        $owner = $this->owner();

        foreach (['cart', 'orders', 'bogus'] as $bad) {
            $title = self::PREFIX . ' refused ' . $bad . ' ' . uniqid();

            $this->actingAs($owner, 'admin')
                ->from(route('admin.ads'))
                ->post(route('admin.ads.store'), ['title' => $title, 'placement' => $bad])
                ->assertSessionHasErrors('placement');

            $this->assertNull(Ad::where('title', $title)->first(), "an ad was created with placement '$bad'");
        }
    }

    public function test_store_accepts_game_and_menu_placements(): void
    {
        $owner = $this->owner();

        foreach (['game', 'menu'] as $ok) {
            $title = self::PREFIX . ' accepted ' . $ok . ' ' . uniqid();

            $this->actingAs($owner, 'admin')
                ->post(route('admin.ads.store'), ['title' => $title, 'placement' => $ok])
                ->assertSessionHasNoErrors();

            $this->assertSame($ok, Ad::where('title', $title)->firstOrFail()->placement);
        }
    }

    public function test_update_refuses_cart_and_orders_and_leaves_the_ad_unchanged(): void
    {
        $owner = $this->owner();
        $ad = $this->ad('game');

        foreach (['cart', 'orders'] as $bad) {
            $this->actingAs($owner, 'admin')
                ->from(route('admin.ads'))
                ->put(route('admin.ads.update', $ad->id), ['title' => 'Retitled', 'placement' => $bad])
                ->assertSessionHasErrors('placement');
        }

        $fresh = Ad::find($ad->id);
        $this->assertSame('game', $fresh->placement);
        $this->assertSame($ad->title, $fresh->title, 'a refused update still wrote the title');
    }

    public function test_the_ads_form_offers_only_game_and_menu(): void
    {
        $html = $this->actingAs($this->owner(), 'admin')->get(route('admin.ads'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Cart Page', $html);
        $this->assertStringNotContainsString('Orders Page', $html);
        $this->assertStringNotContainsString('value="cart"', $html);
        $this->assertStringNotContainsString('value="orders"', $html);
        $this->assertSame(2, substr_count($html, '<option value="game">Game Page</option>'), 'create + edit forms');
        $this->assertSame(2, substr_count($html, '<option value="menu">Menu Page</option>'), 'create + edit forms');
    }

    // ══════════════════ 2. Old cart/orders ads: nothing breaks ══════════════════

    public function test_old_placement_ads_are_listed_labelled_and_do_not_break_the_admin_page(): void
    {
        $cart = $this->ad('cart');
        $orders = $this->ad('orders');

        // The ads are global, so a supervisor (own branch only) can list them but
        // is offered no Edit — that is the existing branch rule, not this batch's.
        foreach ([[$this->owner(), true], [$this->supervisorAt(1), false]] as [$who, $canEdit]) {
            $html = $this->actingAs($who, 'admin')->get(route('admin.ads'))->assertOk()->getContent();

            $this->assertStringContainsString($cart->title, $html);
            $this->assertStringContainsString($orders->title, $html);
            $this->assertSame(2, substr_count($html, 'No longer used'), 'each old ad carries the label');
            if ($canEdit) {
                // The edit control for the old ad is still there, so it can be re-pointed.
                $this->assertStringContainsString('data-placement="cart"', $html);
            }
        }

        $this->assertTrue($cart->hasRemovedPlacement());
        $this->assertFalse($this->ad('menu')->hasRemovedPlacement());
    }

    public function test_an_old_ad_can_be_re_pointed_at_a_live_placement_and_then_shows(): void
    {
        $owner = $this->owner();
        $old = $this->ad('cart', null, ['title' => self::PREFIX . ' Revived']);

        // Opening the edit form's page must not error, and the JS fallback exists.
        $html = $this->actingAs($owner, 'admin')->get(route('admin.ads'))->assertOk()->getContent();
        $this->assertStringContainsString('id="editAdPlacementLegacy"', $html);
        $this->assertStringContainsString("isLive ? btn.dataset.placement : ''", $html);

        $this->actingAs($owner, 'admin')
            ->put(route('admin.ads.update', $old->id), ['title' => 'Revived ad', 'placement' => 'menu'])
            ->assertSessionHasNoErrors();

        $this->assertSame('menu', Ad::find($old->id)->placement);
        $this->assertStringContainsString('Revived ad', $this->menuAt(1));
    }

    public function test_an_old_ad_can_still_be_toggled_and_deleted(): void
    {
        $owner = $this->owner();
        $old = $this->ad('orders');

        $this->actingAs($owner, 'admin')->put(route('admin.ads.toggle', $old->id))->assertRedirect(route('admin.ads'));
        $this->assertFalse((bool) Ad::find($old->id)->is_active);

        $this->actingAs($owner, 'admin')->delete(route('admin.ads.delete', $old->id))->assertRedirect(route('admin.ads'));
        $this->assertNull(Ad::find($old->id));
    }

    public function test_old_placement_ads_never_show_on_the_menu_or_the_game(): void
    {
        $cart = $this->ad('cart');
        $orders = $this->ad('orders');

        $html = $this->menuAt(1);
        $this->assertStringNotContainsString(self::POPUP, $html);
        $this->assertStringNotContainsString($cart->title, $html);

        $game = $this->withSession(['branch_id' => 1])->get('/customer/game')->assertOk()->viewData('gameAds');
        $this->assertFalse($game->contains('id', $cart->id));
        $this->assertFalse($game->contains('id', $orders->id));
    }

    // ══════════════════ 3. The popup renders / does not render ══════════════════

    public function test_a_matching_menu_ad_renders_the_popup(): void
    {
        $ad = $this->ad('menu', null, ['description' => 'Half price today', 'image' => 'uploads/ads/x.jpg']);

        $html = $this->menuAt(1);

        $this->assertStringContainsString(self::POPUP, $html);
        $this->assertStringContainsString($ad->title, $html);
        $this->assertStringContainsString('Half price today', $html);
        $this->assertStringContainsString('uploads/ads/x.jpg', $html);
        $this->assertStringContainsString('id="menuAdClose"', $html, 'a close button');
        $this->assertStringContainsString("event.target === overlay", $html, 'click-outside dismiss');
        $this->assertStringContainsString("event.key === 'Escape'", $html, 'Esc dismiss');
    }

    public function test_no_menu_ad_means_no_popup(): void
    {
        // A game ad exists (and the live one #10) — neither is a menu ad.
        $this->ad('game');

        $this->assertStringNotContainsString(self::POPUP, $this->menuAt(1));
    }

    public function test_a_game_ad_is_not_shown_as_a_menu_popup_and_a_menu_ad_is_not_in_the_game(): void
    {
        $game = $this->ad('game');
        $menu = $this->ad('menu');

        $this->assertStringNotContainsString($game->title, $this->menuAt(1));

        $gameAds = $this->withSession(['branch_id' => 1])->get('/customer/game')->assertOk()->viewData('gameAds');
        $this->assertTrue($gameAds->contains('id', $game->id), 'game page must still show game ads');
        $this->assertFalse($gameAds->contains('id', $menu->id), 'game page must not pick up menu ads');
    }

    public function test_no_popup_until_a_branch_is_selected(): void
    {
        $this->ad('menu');

        $this->assertStringNotContainsString(self::POPUP, $this->menuAt(null));
        $this->assertNull(session('menu_ad_popup_shown'), 'the once-per-session slot must not be spent on the branch picker');
    }

    // ══════════════════ 4. Never shown: inactive / expired / not started / other branch ══════════════════

    public function test_inactive_expired_and_not_yet_started_ads_never_show(): void
    {
        $inactive = $this->ad('menu', null, ['is_active' => false]);
        $expired = $this->ad('menu', null, ['ends_at' => now()->subDay()]);
        $future = $this->ad('menu', null, ['starts_at' => now()->addDay()]);

        $html = $this->menuAt(1);

        $this->assertStringNotContainsString(self::POPUP, $html);
        foreach ([$inactive, $expired, $future] as $ad) {
            $this->assertStringNotContainsString($ad->title, $html);
        }
    }

    public function test_a_currently_running_dated_ad_shows(): void
    {
        $ad = $this->ad('menu', null, ['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

        $this->assertStringContainsString($ad->title, $this->menuAt(1));
    }

    public function test_pickup_never_shows_another_branchs_ad_but_shows_own_and_global(): void
    {
        $far = $this->branch('Far');
        $theirs = $this->ad('menu', $far->id);

        $html = $this->menuAt(1);
        $this->assertStringNotContainsString(self::POPUP, $html);
        $this->assertStringNotContainsString($theirs->title, $html);

        // Own branch: shown.
        $this->flushSession();
        $mine = $this->ad('menu', 1);
        $this->assertStringContainsString($mine->title, $this->menuAt(1));

        // Global: shown to a customer of the far branch too (fresh session).
        $this->flushSession();
        $mine->delete();
        $theirs->delete();
        $global = $this->ad('menu', null);
        $this->assertStringContainsString($global->title, $this->menuAt($far->id));
    }

    public function test_dine_in_guest_gets_own_branch_and_global_ads_but_not_another_branchs(): void
    {
        $here = $this->branch('Here');
        $far = $this->branch('Far');

        $theirs = $this->ad('menu', $far->id);
        $html = $this->dineInGuestAt($here);
        $this->assertStringNotContainsString($theirs->title, $html);
        $this->assertStringNotContainsString(self::POPUP, $html);

        $this->flushSession();
        $mine = $this->ad('menu', $here->id);
        $this->assertStringContainsString($mine->title, $this->dineInGuestAt($here));
    }

    // ══════════════════ 5. Once per session ══════════════════

    public function test_the_popup_shows_once_per_session_only(): void
    {
        $ad = $this->ad('menu');

        $this->assertStringContainsString(self::POPUP, $this->menuAt(1));
        $this->assertTrue((bool) session('menu_ad_popup_shown'));

        // Refresh / come back to the menu in the same session: not again.
        $second = $this->get(route('customer.menu'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::POPUP, $second);
        $this->assertStringNotContainsString($ad->title, $second);

        $third = $this->get(route('customer.menu'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::POPUP, $third);

        // A brand-new session (new visit) sees it again.
        $this->flushSession();
        $this->assertStringContainsString(self::POPUP, $this->menuAt(1));
    }

    public function test_the_slot_is_not_spent_when_there_was_nothing_to_show(): void
    {
        $this->assertStringNotContainsString(self::POPUP, $this->menuAt(1));
        $this->assertNull(session('menu_ad_popup_shown'));

        // An ad created afterwards is still shown on the next open.
        $this->ad('menu');
        $this->assertStringContainsString(self::POPUP, $this->get(route('customer.menu'))->getContent());
    }

    public function test_only_one_ad_is_popped_up_lowest_display_order_then_newest(): void
    {
        $older = $this->ad('menu', null, ['display_order' => 0]);
        $newer = $this->ad('menu', null, ['display_order' => 0]);
        $lowOrder = $this->ad('menu', null, ['display_order' => -1]);

        $html = $this->menuAt(1);

        $this->assertStringContainsString($lowOrder->title, $html);
        $this->assertStringNotContainsString($newer->title, $html);
        $this->assertStringNotContainsString($older->title, $html);
        $this->assertSame(1, substr_count($html, self::POPUP));
    }

    // ══════════════════ 6. Escaping + link safety ══════════════════

    public function test_ad_text_is_escaped_and_a_non_http_link_is_not_rendered(): void
    {
        $this->ad('menu', null, [
            'title'       => '<script>window.__pwned=1</script>Sale',
            'description' => '"><img src=x onerror=alert(1)>',
            'link'        => 'javascript:alert(1)',
        ]);

        $html = $this->menuAt(1);

        $this->assertStringNotContainsString('<script>window.__pwned=1</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;window.__pwned=1&lt;/script&gt;Sale', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('javascript:alert(1)', $html);
        $this->assertStringNotContainsString('Learn more', $html);
    }

    public function test_an_https_link_renders_with_noopener(): void
    {
        $this->ad('menu', null, ['link' => 'https://example.test/promo?a=1&b=2']);

        $html = $this->menuAt(1);

        $this->assertStringContainsString('href="https://example.test/promo?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    // ══════════════════ 7. Sequencing + scope of the popup ══════════════════

    public function test_the_popup_waits_for_the_welcome_popup_and_sits_beneath_every_other_modal(): void
    {
        $this->ad('menu');

        $partial = file_get_contents(resource_path('views/customer/partials/menu-ad-popup.blade.php'));

        // Waits for the welcome popup and the order notice, gives up on the idle prompt.
        $this->assertStringContainsString("shown('welcomePopup')", $partial);
        $this->assertStringContainsString("shown('orderStatusNotice')", $partial);
        $this->assertStringContainsString("getElementById('idleTimeoutModal')", $partial);

        // z-order: ad (9990) is under order notice (10000), welcome (10001), idle (10002).
        preg_match('/z-index:(\d+)/', $partial, $m);
        $this->assertSame(9990, (int) $m[1]);
        $this->assertStringContainsString('z-index:10001', file_get_contents(resource_path('views/customer/partials/welcome-popup.blade.php')));
        $this->assertStringContainsString('z-index:10002', file_get_contents(resource_path('views/customer/partials/idle-timeout.blade.php')));
        $this->assertStringContainsString('z-index:10000', file_get_contents(resource_path('views/customer/menu.blade.php')));

        // It starts hidden, so it can never flash up beside the welcome popup.
        $this->assertStringContainsString('style="display:none;position:fixed', $partial);
    }

    public function test_a_fresh_login_gets_the_welcome_popup_and_the_ad_in_the_same_page_in_order(): void
    {
        $this->ad('menu');
        $user = User::create([
            'name'      => self::PREFIX . ' Customer',
            'email'     => strtolower(self::PREFIX) . '-c-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => 'customer',
            'branch_id' => null,
            'is_active' => true,
        ]);

        $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->post(route('customer.login.post'), ['email' => $user->email, 'password' => 'Aa1!aaaaaa'])
            ->assertRedirect(route('customer.menu'));

        $html = $this->get(route('customer.menu'))->assertOk()->getContent();

        $this->assertStringContainsString('id="welcomePopup"', $html);
        $this->assertStringContainsString(self::POPUP, $html);
        $this->assertLessThan(strpos($html, self::POPUP), strpos($html, 'id="welcomePopup"'), 'welcome first, ad after');
        $this->assertStringContainsString('id="idleTimeoutModal"', $html, 'idle prompt untouched');
    }

    public function test_the_popup_is_only_wired_into_the_customer_menu(): void
    {
        $this->ad('menu');

        // Admin pages never carry it.
        $owner = $this->owner();
        foreach (['admin.ads', 'admin.home'] as $route) {
            $html = $this->actingAs($owner, 'admin')->get(route($route))->assertOk()->getContent();
            $this->assertStringNotContainsString(self::POPUP, $html, "$route rendered the customer popup");
        }

        // And no other view includes the partial.
        $includers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if ($file->isFile() && str_contains(file_get_contents($file->getPathname()), "customer.partials.menu-ad-popup")) {
                $includers[] = str_replace('\\', '/', substr($file->getPathname(), strlen(resource_path('views')) + 1));
            }
        }
        $this->assertSame(['customer/menu.blade.php'], $includers);
    }
}
