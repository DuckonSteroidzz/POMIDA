<?php

namespace Tests\Feature;

use App\Models\Ad;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two defects found on the game page during Batch 3 and left alone there
 * because that batch was "game page unchanged":
 *
 *  (a) The game ad query ignored starts_at, so an ad scheduled for next week
 *      already played in the carousel. It now uses Ad::liveNow() — the same
 *      active + starts_at + ends_at rule the Menu popup uses.
 *  (b) game.blade.php's popup built its slide as an HTML string and put
 *      ad.title / ad.description into it unescaped, so an ad titled
 *      `<img src=x onerror=...>` ran script on a customer's phone. Reproduced
 *      in headless Chrome before the fix (window.__pwned became true and an
 *      injected element existed); after it the text is shown literally. The
 *      slide is now built from DOM nodes with textContent.
 *
 * The browser behaviour itself cannot be seen from PHP, so (b) is pinned here
 * two ways: the served page cannot break out of its own <script> block, and
 * the popup function no longer contains an HTML-string sink for ad text.
 *
 * Every row is created inside DatabaseTransactions on pomida_db_testing.
 */
class GameAdScheduleAndEscapingTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'GADSCH';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'pomida_db_testing',
            DB::selectOne('select database() as d')->d,
            'GameAdScheduleAndEscapingTest must only ever run against pomida_db_testing.'
        );
    }

    private function ad(string $placement, string $label, array $extra = []): Ad
    {
        return Ad::create(array_merge([
            'title'     => self::PREFIX . ' ' . $label . ' ' . uniqid(),
            'placement' => $placement,
            'is_active' => true,
        ], $extra));
    }

    /** ids of the ads the game carousel is handed. */
    private function gameIds(): array
    {
        return $this->withSession(['branch_id' => 1])
            ->get('/customer/game')->assertOk()
            ->viewData('gameAds')->pluck('id')->all();
    }

    /** The whole game.blade.php popup function, from its opening to closeAd(). */
    private function popupSource(): string
    {
        $src = file_get_contents(resource_path('views/customer/game.blade.php'));
        $start = strpos($src, 'function showAdPopup()');
        $end = strpos($src, 'function closeAd()');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($src, $start, $end - $start);
    }

    // ══════════ (a) starts_at ══════════

    public function test_an_ad_that_has_not_started_is_not_in_the_game_carousel(): void
    {
        $future = $this->ad('game', 'future', ['starts_at' => now()->addDays(3)]);

        $this->assertNotContains($future->id, $this->gameIds());
    }

    public function test_running_open_ended_and_just_started_ads_still_show(): void
    {
        $running = $this->ad('game', 'running', ['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $undated = $this->ad('game', 'undated');
        $started = $this->ad('game', 'started', ['starts_at' => now()->subMinute()]);

        $ids = $this->gameIds();

        foreach ([$running, $undated, $started] as $ad) {
            $this->assertContains($ad->id, $ids, "{$ad->title} should be live");
        }
    }

    public function test_inactive_and_expired_game_ads_still_never_show(): void
    {
        $off = $this->ad('game', 'off', ['is_active' => false]);
        $gone = $this->ad('game', 'gone', ['ends_at' => now()->subDay()]);

        $ids = $this->gameIds();

        $this->assertNotContains($off->id, $ids);
        $this->assertNotContains($gone->id, $ids);
    }

    public function test_game_and_menu_agree_on_which_ads_are_live(): void
    {
        $shapes = [
            'live'     => [[], true],
            'future'   => [['starts_at' => now()->addDay()], false],
            'expired'  => [['ends_at' => now()->subDay()], false],
            'inactive' => [['is_active' => false], false],
            'window'   => [['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()], true],
        ];

        foreach ($shapes as $label => [$attrs, $expectLive]) {
            $game = $this->ad('game', "g-$label", $attrs);
            $menu = $this->ad('menu', "m-$label", $attrs);

            $this->assertSame($expectLive, in_array($game->id, $this->gameIds(), true), "game / $label");
            $this->assertSame($expectLive, Ad::where('placement', 'menu')->liveNow()->whereKey($menu->id)->exists(), "scope / $label");
            $this->assertSame($expectLive, $game->fresh()->isActive(), "isActive() / $label");
        }
    }

    public function test_the_game_page_still_only_takes_game_ads(): void
    {
        $menu = $this->ad('menu', 'menu');
        $cart = $this->ad('cart', 'cart');

        $this->assertNotContains($menu->id, $this->gameIds());
        $this->assertNotContains($cart->id, $this->gameIds());
    }

    // ══════════ (b) escaping ══════════

    public function test_a_hostile_title_cannot_break_out_of_the_pages_script_block(): void
    {
        $this->ad('game', 'xss', [
            'title'       => '</script><script>window.__pwned=1</script>',
            'description' => '<img src=x onerror=alert(1)>',
        ]);

        $html = $this->withSession(['branch_id' => 1])->get('/customer/game')->assertOk()->getContent();

        $this->assertStringNotContainsString('</script><script>window.__pwned=1</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    public function test_the_popup_puts_ad_text_in_through_textcontent_never_an_html_string(): void
    {
        $fn = $this->popupSource();

        // The sink is gone: nothing in the popup function assigns innerHTML,
        // and ad text is no longer concatenated into markup.
        $this->assertStringNotContainsString('innerHTML', $fn);
        $this->assertStringNotContainsString("' + ad.title", $fn);
        $this->assertStringNotContainsString("' + (ad.description", $fn);
        $this->assertStringNotContainsString('onclick=', $fn);

        // ...and it is built from DOM nodes with textContent.
        $this->assertStringContainsString('node.textContent = text', $fn);
        $this->assertMatchesRegularExpression("/el\('p',[^\n]*, ad\.title\)/", $fn);
        $this->assertMatchesRegularExpression("/el\('p',[^\n]*, ad\.description \|\| ''\)/", $fn);
        $this->assertStringContainsString("closeBtn.addEventListener('click', closeAd)", $fn);
    }
}
