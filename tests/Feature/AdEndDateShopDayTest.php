<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An ad's End Date is a whole shop day: it stays live until 23:59:59 Manila
 * on that date (Defect Report 3 part B follow-up).
 *
 * The admin form stores the date at 00:00:00 in APP_TIMEZONE. Compared as an
 * instant under APP_TIMEZONE=UTC, that ended the ad at 8:00 AM Manila ON its
 * end date. Ad::scopeLiveNow() / hasEnded() now read it by the voucher
 * expiry rule (App\Support\ShopDay): a midnight value is a date, valid
 * through that date in the shop; any other value is a precise instant.
 * Nothing stored changes. The Game page and the Menu popup both select
 * through liveNow(), and the admin Status badge through listStatus().
 */
class AdEndDateShopDayTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::selectOne('select database() as d')->d);
    }

    /** Freeze the clock at a Manila wall-clock time, given in the app's zone. */
    private function at(string $manila): void
    {
        $this->travelTo(CarbonImmutable::parse($manila, 'Asia/Manila')->setTimezone(config('app.timezone')));
    }

    private function branch(): Branch
    {
        return Branch::create([
            'name'      => 'AED ' . Str::lower(Str::random(8)),
            'code'      => 'AED' . strtoupper(Str::random(6)),
            'address'   => 'Ad end date test',
            'is_active' => true,
        ]);
    }

    private function ad(Branch $branch, string $placement, array $attrs = []): Ad
    {
        return Ad::create($attrs + [
            'title'         => 'AED ' . $placement . ' ' . Str::random(8),
            'placement'     => $placement,
            'is_active'     => true,
            // First in line for the one-ad Menu popup.
            'display_order' => -100000,
            'branch_id'     => $branch->id,
        ]);
    }

    private function onGamePage(Branch $branch, Ad $ad): bool
    {
        $ads = $this->withSession(['branch_id' => $branch->id])->get('/customer/game')->assertOk()->viewData('gameAds');

        return $ads->contains('id', $ad->id);
    }

    private function inMenuPopup(Branch $branch, Ad $ad): bool
    {
        // A fresh session each time: the popup is once per session.
        $this->flushSession();

        return str_contains(
            $this->withSession(['order_type' => 'pick_up', 'branch_id' => $branch->id])->get(route('customer.menu'))->assertOk()->getContent(),
            e($ad->title)
        );
    }

    private function adminBadge(Ad $ad): string
    {
        $admin = User::create([
            'name' => 'AED admin', 'email' => 'aed-' . Str::lower(Str::random(10)) . '@example.test',
            'password' => 'Aa1!aaaaaa', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $html = $this->actingAs($admin, 'admin')->get('/admin/ads?branch=all&q=' . urlencode($ad->title))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/data-ad-status="' . $ad->id . '">([A-Za-z]+)</', $html, $m), 'badge missing');

        return $m[1];
    }

    public function test_an_ad_stays_live_until_the_end_of_its_end_date_in_manila(): void
    {
        $branch = $this->branch();
        // Exactly what the admin form saves for End Date = Oct 21.
        $game = $this->ad($branch, 'game', ['ends_at' => '2026-10-21']);
        $menu = $this->ad($branch, 'menu', ['ends_at' => '2026-10-21']);

        foreach (['2026-10-20 23:59:59', '2026-10-21 07:59:00', '2026-10-21 08:01:00', '2026-10-21 23:59:59'] as $manila) {
            $this->at($manila);

            foreach ([$game, $menu] as $ad) {
                $fresh = $ad->fresh();
                $this->assertTrue($fresh->isActive(), "not live at $manila Manila");
                $this->assertSame('live', $fresh->listStatus(), "status at $manila Manila");
                $this->assertTrue(Ad::whereKey($ad->id)->liveNow()->exists(), "liveNow() disagrees at $manila Manila");
                $this->assertTrue(Ad::whereKey($ad->id)->withListStatus('live')->exists());
                $this->assertFalse(Ad::whereKey($ad->id)->withListStatus('expired')->exists());
            }
            $this->assertTrue($this->onGamePage($branch, $game), "game page dropped it at $manila Manila");
            $this->assertTrue($this->inMenuPopup($branch, $menu), "menu popup dropped it at $manila Manila");
        }
        $this->assertSame('Live', $this->adminBadge($game));

        // CONTROL: compared as an instant, the stored value had already passed
        // at 08:01 Manila on the end date — the old 8:00 AM cut-off.
        $this->at('2026-10-21 08:01:00');
        if (config('app.timezone') === 'UTC') {
            $this->assertTrue($game->fresh()->ends_at->isPast());
        }

        // The first moment of the next Manila day: ended everywhere.
        $this->at('2026-10-22 00:00:00');
        foreach ([$game, $menu] as $ad) {
            $fresh = $ad->fresh();
            $this->assertFalse($fresh->isActive());
            $this->assertSame('expired', $fresh->listStatus());
            $this->assertFalse(Ad::whereKey($ad->id)->liveNow()->exists());
            $this->assertTrue(Ad::whereKey($ad->id)->withListStatus('expired')->exists());
        }
        $this->assertFalse($this->onGamePage($branch, $game), 'game page still shows it the day after');
        $this->assertFalse($this->inMenuPopup($branch, $menu), 'menu popup still shows it the day after');
        $this->assertSame('Expired', $this->adminBadge($game));
    }

    public function test_an_end_with_a_time_of_day_still_ends_at_that_instant(): void
    {
        $branch = $this->branch();
        $this->at('2026-10-21 10:00:00');
        $ad = $this->ad($branch, 'game', ['ends_at' => now()->subMinute()]);

        $this->assertSame('expired', $ad->fresh()->listStatus());
        $this->assertFalse(Ad::whereKey($ad->id)->liveNow()->exists());
        $this->assertFalse($this->onGamePage($branch, $ad));
    }

    public function test_the_admin_list_shows_the_end_date_as_entered(): void
    {
        $branch = $this->branch();
        $ad = $this->ad($branch, 'game', ['ends_at' => '2026-10-21']);
        $this->at('2026-10-21 12:00:00');

        $admin = User::create([
            'name' => 'AED admin', 'email' => 'aed-' . Str::lower(Str::random(10)) . '@example.test',
            'password' => 'Aa1!aaaaaa', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $html = $this->actingAs($admin, 'admin')->get('/admin/ads?branch=all&q=' . urlencode($ad->title))->getContent();

        $this->assertStringContainsString('to Oct 21, 2026', $html);
    }
}
