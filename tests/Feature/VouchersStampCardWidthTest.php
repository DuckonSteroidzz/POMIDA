<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\StreakRewardSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * Vouchers & Rewards: the "Reward stamp card" sits in the same column as
 * "You have N pts", "My rewards" and "My vouchers" (item A, Oct 2026 defect
 * report). It used to carry max-w-3xl, so from tablet width up it was a
 * narrower, left-hugging box. The Account page uses the same partial; since the Oct 2026
 * Account redesign its card is a cell of the Overview tab's grid (no cap either). Pixel widths at 390 / 768 / 1440 are measured in a real
 * browser (see the item A report); these tests pin the markup that makes it so.
 */
class VouchersStampCardWidthTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresStreakReward;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearStreakRewardSettings();
        Setting::where('key', Setting::STREAK_CALENDAR_ENABLED)->delete();
        Cache::flush();
    }

    private function customer(): User
    {
        return User::create([
            'name'              => 'Width Test',
            'email'             => 'width-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function cardTag(string $html): string
    {
        $this->assertSame(1, preg_match('#<section id="free-dessert"[^>]*>#', $html, $m), 'the stamp card is missing');

        return $m[0];
    }

    public function test_the_card_on_vouchers_and_rewards_fills_the_column_like_the_blocks_around_it(): void
    {
        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get(route('customer.vouchers'))
            ->assertOk()
            ->getContent();

        $tag = $this->cardTag($html);

        $this->assertStringNotContainsString('max-w-3xl', $tag, 'a width cap makes it narrower than the other blocks');
        $this->assertStringContainsString('w-full', $tag);
        // Same inner padding as the "You have N pts" block above it.
        $this->assertStringContainsString('p-4 sm:p-5', $tag);
        // Content and stamp circles are untouched.
        $this->assertStringContainsString('Reward stamp card', $html);
        $this->assertStringContainsString('data-testid="free-dessert-stamp-grid"', $html);
    }

    public function test_the_account_page_card_is_the_redesigned_full_width_card_not_a_capped_column(): void
    {
        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get(route('customer.account'))
            ->assertOk()
            ->getContent();

        // Account redesign (Oct 2026): the card is a cell of the page's grid and
        // spans the same width as the title and the top nav, so no max-w-3xl.
        $tag = $this->cardTag($html);
        $this->assertStringNotContainsString('max-w-3xl', $tag);
        $this->assertStringContainsString('acct-card', $tag);

        // Four tabs (Oct 2026): the stamp card is in the Overview panel, ahead
        // of the Rewards panel, and the panel is not hidden on first load.
        $overview = strpos($html, 'id="p-overview"');
        $this->assertNotFalse($overview);
        $this->assertGreaterThan($overview, strpos($html, 'id="free-dessert"'));
        $this->assertLessThan(strpos($html, 'id="p-rewards"'), strpos($html, 'id="free-dessert"'));
        $this->assertSame(1, preg_match('#<section[^>]*id="p-overview"[^>]*>#', $html, $panel));
        $this->assertStringNotContainsString(' hidden', $panel[0]);
    }
}
