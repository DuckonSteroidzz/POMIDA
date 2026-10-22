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
 * An ad's Start Date is a whole shop day too: it goes live at 00:00 Manila on
 * that date, not 8:00 AM (the mirror of AdEndDateShopDayTest).
 *
 * The admin form stores the date at 00:00:00 in APP_TIMEZONE. Compared as an
 * instant under APP_TIMEZONE=UTC, that started the ad at 8:00 AM Manila ON its
 * start date. Ad::scopeLiveNow() / hasStarted() / listStatus() /
 * scopeWithListStatus() now read it by ShopDay::hasStartedAt() / whereStarted():
 * a midnight value is a date, live from the first moment of that date in the
 * shop; any other value is a precise instant. Nothing stored changes.
 */
class AdStartDateShopDayTest extends TestCase
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
            'name'      => 'ASD ' . Str::lower(Str::random(8)),
            'code'      => 'ASD' . strtoupper(Str::random(6)),
            'address'   => 'Ad start date test',
            'is_active' => true,
        ]);
    }

    private function ad(Branch $branch, string $placement, array $attrs = []): Ad
    {
        return Ad::create($attrs + [
            'title'         => 'ASD ' . $placement . ' ' . Str::random(8),
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
            'name' => 'ASD admin', 'email' => 'asd-' . Str::lower(Str::random(10)) . '@example.test',
            'password' => 'Aa1!aaaaaa', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $html = $this->actingAs($admin, 'admin')->get('/admin/ads?branch=all&q=' . urlencode($ad->title))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/data-ad-status="' . $ad->id . '">([A-Za-z]+)</', $html, $m), 'badge missing');

        return $m[1];
    }

    /** Assert one ad is (or is not) live at the frozen moment, through every reader. */
    private function assertLiveness(Branch $branch, Ad $game, Ad $menu, bool $live, string $when): void
    {
        foreach ([$game, $menu] as $ad) {
            $fresh = $ad->fresh();
            $this->assertSame($live, $fresh->isActive(), "isActive() at $when Manila");
            $this->assertSame($live ? 'live' : 'scheduled', $fresh->listStatus(), "status at $when Manila");
            $this->assertSame($live, Ad::whereKey($ad->id)->liveNow()->exists(), "liveNow() at $when Manila");
            $this->assertSame($live, Ad::whereKey($ad->id)->withListStatus('live')->exists(), "live filter at $when Manila");
            $this->assertSame(! $live, Ad::whereKey($ad->id)->withListStatus('scheduled')->exists(), "scheduled filter at $when Manila");
        }
        $this->assertSame($live, $this->onGamePage($branch, $game), "game page at $when Manila");
        $this->assertSame($live, $this->inMenuPopup($branch, $menu), "menu popup at $when Manila");
        $this->assertSame($live ? 'Live' : 'Scheduled', $this->adminBadge($game), "badge at $when Manila");
    }

    public function test_an_ad_starts_at_the_first_moment_of_its_start_date_in_manila(): void
    {
        $branch = $this->branch();
        // Exactly what the admin form saves for Start Date = Oct 21.
        $game = $this->ad($branch, 'game', ['starts_at' => '2026-10-21']);
        $menu = $this->ad($branch, 'menu', ['starts_at' => '2026-10-21']);

        $this->at('2026-10-20 23:59:00');
        $this->assertLiveness($branch, $game, $menu, false, '2026-10-20 23:59');

        foreach (['2026-10-21 00:00:00', '2026-10-21 07:59:00', '2026-10-21 08:01:00', '2026-10-21 23:59:59'] as $manila) {
            $this->at($manila);
            $this->assertLiveness($branch, $game, $menu, true, $manila);
        }

        // CONTROL: compared as an instant, the stored value was still ahead at
        // 07:59 Manila on the start date — the old 8:00 AM start.
        $this->at('2026-10-21 07:59:00');
        if (config('app.timezone') === 'UTC') {
            $this->assertTrue($game->fresh()->starts_at->isFuture());
        }
    }

    public function test_a_start_with_a_time_of_day_still_starts_at_that_instant(): void
    {
        $branch = $this->branch();
        $this->at('2026-10-21 10:00:00');
        $game = $this->ad($branch, 'game', ['starts_at' => now()->addMinute()]);
        $menu = $this->ad($branch, 'menu', ['starts_at' => now()->addMinute()]);

        $this->assertLiveness($branch, $game, $menu, false, '2026-10-21 10:00');

        $this->at('2026-10-21 10:01:00');
        $this->assertLiveness($branch, $game, $menu, true, '2026-10-21 10:01');
    }

    public function test_no_start_date_means_live_and_a_switched_off_ad_stays_inactive(): void
    {
        $branch = $this->branch();
        $this->at('2026-10-21 12:00:00');

        $open = $this->ad($branch, 'game', ['starts_at' => null]);
        $this->assertSame('live', $open->fresh()->listStatus());
        $this->assertTrue(Ad::whereKey($open->id)->liveNow()->exists());

        $off = $this->ad($branch, 'game', ['starts_at' => '2026-10-21', 'is_active' => false]);
        $this->assertSame('inactive', $off->fresh()->listStatus());
        $this->assertFalse(Ad::whereKey($off->id)->liveNow()->exists());
    }

    public function test_an_ad_can_be_both_started_and_ended_on_the_same_date(): void
    {
        $branch = $this->branch();
        $ad = $this->ad($branch, 'game', ['starts_at' => '2026-10-21', 'ends_at' => '2026-10-21']);

        $this->at('2026-10-20 23:59:59');
        $this->assertSame('scheduled', $ad->fresh()->listStatus());

        foreach (['2026-10-21 00:00:00', '2026-10-21 07:59:00', '2026-10-21 23:59:59'] as $manila) {
            $this->at($manila);
            $this->assertSame('live', $ad->fresh()->listStatus(), $manila);
            $this->assertTrue(Ad::whereKey($ad->id)->liveNow()->exists(), $manila);
        }

        $this->at('2026-10-22 00:00:00');
        $this->assertSame('expired', $ad->fresh()->listStatus());
        $this->assertFalse(Ad::whereKey($ad->id)->liveNow()->exists());
    }
}
