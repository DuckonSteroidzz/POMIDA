<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderRating;
use App\Models\User;
use App\Support\OrderHistoryFilter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The customer Order History (Orders page, History tab), rebuilt Oct 2026 to
 * docs/design-refs/peachy-order-history.html: a filter bar (All / Today /
 * 7 days / 30 days, From–To, status, per page, "N orders", Reset), day-grouped
 * cards, a pager, full (never truncated) order numbers and a Back button to
 * More.
 *
 * Filtering and paging are done on the SERVER from the query string
 * (App\Support\OrderHistoryFilter + OrderController::showOrders), so each case
 * below is a plain GET. "Now" is pinned to 2026-10-20 12:00 Manila (04:00 UTC);
 * orders are stored in UTC, as the app does (APP_TIMEZONE=UTC), via manila().
 * The customer and every order are created here, inside DatabaseTransactions.
 */
class CustomerOrderHistoryPageTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
        $this->assertSame('UTC', config('app.timezone'), 'fixtures assume the app stores UTC');

        $this->travelTo(CarbonImmutable::parse('2026-10-20 04:00:00', 'UTC'));

        $this->user = $this->customer();
    }

    private function customer(): User
    {
        return User::create([
            'name'              => 'History Test',
            'email'             => 'hist-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function manila(string $local): string
    {
        return CarbonImmutable::parse($local, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
    }

    /** An order placed at a Manila wall-clock time (created_at is not fillable). */
    private function order(string $placedManila, string $status = 'completed', ?User $owner = null, array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'order_number'   => 'OHP-' . strtoupper(Str::random(10)),
            'user_id'        => ($owner ?? $this->user)->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => $status === 'completed' ? 'paid' : 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ], $extra));

        DB::table('orders')->where('id', $order->id)->update(['created_at' => $this->manila($placedManila)]);

        return $order->fresh();
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->user, 'customer')
            ->get(route('customer.orders', array_merge(['tab' => 'history'], $query)));
    }

    /** @return list<string> the order numbers shown, in page order */
    private function shown(string $html): array
    {
        preg_match_all('/<span class="history-order-num[^>]*>([^<]+)</', $html, $m);

        return array_map('trim', $m[1]);
    }

    private function counter(string $html): ?string
    {
        return preg_match('/data-testid="history-count"[^>]*>\s*([^<]+?)\s*</', $html, $m) ? $m[1] : null;
    }

    // ══════════ the page itself ══════════

    public function test_the_page_renders_the_design_with_filter_bar_cards_and_counter(): void
    {
        $this->order('2026-10-20 01:10', 'cancelled');
        $this->order('2026-10-19 12:27');

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('Your Orders', $html);
        $this->assertStringContainsString('data-testid="history-filters"', $html);
        foreach (['All', 'Today', '7 days', '30 days'] as $chip) {
            $this->assertMatchesRegularExpression('/data-preset="[^"]+"[^>]*>' . preg_quote($chip, '/') . '</', $html);
        }
        $this->assertStringContainsString('name="from"', $html);
        $this->assertStringContainsString('name="to"', $html);
        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString('name="per_page"', $html);
        $this->assertStringContainsString('>Reset<', $html);
        $this->assertSame('2 orders', $this->counter($html));
        $this->assertStringContainsString('Current Order</button>', $html);
        $this->assertStringContainsString('View receipt', $html);
        $this->assertSame(2, substr_count($html, 'class="history-card '));
    }

    public function test_cards_are_grouped_by_the_shop_day_as_today_yesterday_and_a_date(): void
    {
        $this->order('2026-10-20 00:10');
        $this->order('2026-10-19 23:50');
        $this->order('2026-10-15 22:23');

        $html = $this->page()->assertOk()->getContent();

        preg_match_all('/<p class="date-group[^>]*>([^<]+)</', $html, $m);
        $this->assertSame(['Today', 'Yesterday', 'Oct 15, 2026'], array_map('trim', $m[1]));
    }

    public function test_the_full_order_number_is_shown_and_is_never_truncated(): void
    {
        $order = $this->order('2026-10-20 01:10', 'completed', null, ['order_number' => 'ORD-20261020-DHVVAQ']);

        $html = $this->page()->assertOk()->getContent();

        $this->assertSame(['ORD-20261020-DHVVAQ'], $this->shown($html));
        preg_match('/<span class="history-order-num[^"]*"/', $html, $m);
        $this->assertStringNotContainsString('truncate', $m[0], 'the order number must wrap, not be cut with an ellipsis');
        $this->assertStringNotContainsString('text-ellipsis', $m[0]);
        $this->assertStringContainsString('[overflow-wrap:anywhere]', $m[0]);
        $this->assertSame($order->order_number, $this->shown($html)[0]);
    }

    public function test_the_grid_is_a_fluid_auto_fill_that_cannot_overflow_a_phone(): void
    {
        $this->order('2026-10-20 01:10');

        $html = $this->page()->assertOk()->getContent();

        // min(100%, 19rem): one column on a 390px phone, three at 1440, never wider than the screen.
        $this->assertStringContainsString('grid-cols-[repeat(auto-fill,minmax(min(100%,19rem),1fr))]', $html);
        // The price never shrinks or wraps its own digits.
        $this->assertMatchesRegularExpression('/history-amount[^"]*whitespace-nowrap/', $html);
    }

    public function test_view_receipt_rate_meal_and_the_rating_display_are_kept(): void
    {
        $unrated = $this->order('2026-10-20 01:09');
        $rated = $this->order('2026-10-20 01:08');
        OrderRating::create(['order_id' => $rated->id, 'user_id' => $this->user->id, 'rating' => 4, 'rating_type' => 'stars']);

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString(route('customer.receipt', $unrated->id), $html);
        $this->assertSame(1, substr_count($html, 'data-testid="rate-meal-btn"'), 'only the unrated order offers Rate meal');
        $this->assertStringContainsString('openRatingModal(' . $unrated->id, $html);
        $this->assertStringNotContainsString('openRatingModal(' . $rated->id, $html);
        $this->assertStringContainsString('data-testid="meal-rated"', $html);
        $this->assertStringContainsString('4/5', $html);
        $this->assertStringContainsString('Not rated yet', $html);
    }

    public function test_a_cancelled_order_offers_the_receipt_but_not_rating(): void
    {
        $this->order('2026-10-20 01:10', 'cancelled');

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('View receipt', $html);
        $this->assertStringNotContainsString('data-testid="rate-meal-btn"', $html);
        $this->assertStringNotContainsString('Not rated yet', $html);
    }

    public function test_only_this_customers_finished_orders_are_listed(): void
    {
        $mine = $this->order('2026-10-20 01:10');
        $pending = $this->order('2026-10-20 01:09', 'pending');
        $theirs = $this->order('2026-10-20 01:08', 'completed', $this->customer());

        $shown = $this->shown($this->page()->assertOk()->getContent());

        $this->assertSame([$mine->order_number], $shown);
        $this->assertNotContains($pending->order_number, $shown);
        $this->assertNotContains($theirs->order_number, $shown);
    }

    // ══════════ back button ══════════

    public function test_the_history_view_has_a_back_button_to_more_labelled_back(): void
    {
        $html = $this->page()->assertOk()->getContent();

        preg_match('/<header\b.*?<\/header>/s', $html, $header);
        $this->assertMatchesRegularExpression(
            '/<a href="' . preg_quote(route('customer.more'), '/') . '"\s+data-header-back\s+aria-label="Back"/',
            $header[0]
        );
        $this->assertMatchesRegularExpression('/data-header-back.*?<i class="bi bi-arrow-left[^"]*"[^>]*><\/i>\s*<span class="hidden sm:inline">Back<\/span>/s', $header[0]);
        // Opened on the History view, so it shows from the start.
        $this->assertMatchesRegularExpression('/<span class="shrink-0" data-history-back\s*>/', $header[0]);
    }

    public function test_the_plain_orders_tab_still_has_no_back_button(): void
    {
        $html = $this->actingAs($this->user, 'customer')->get(route('customer.orders'))->assertOk()->getContent();

        preg_match('/<header\b.*?<\/header>/s', $html, $header);
        // The Order History back button is in the markup for the History pill,
        // but hidden while the Current Order view is showing; it is the only
        // back button there.
        $this->assertSame(1, substr_count($header[0], 'data-header-back'));
        $this->assertMatchesRegularExpression('/<span class="shrink-0" data-history-back\s+hidden\s*>\s*<a href="' . preg_quote(route('customer.more'), '/') . '"\s+data-header-back/', $header[0]);
    }

    public function test_opening_history_with_the_pill_shows_the_back_button_and_current_order_hides_it(): void
    {
        $html = $this->actingAs($this->user, 'customer')->get(route('customer.orders'))->assertOk()->getContent();

        // The pill that opens History in place, without ?tab=history...
        $this->assertMatchesRegularExpression('/<button class="tab-btn\s*" data-tab-index="1" onclick="switchTab\(1, this\)">/', $html);
        // ...and the panel switch shows the Back button exactly when History
        // (index 1) is the view, hiding it again on Current Order.
        $this->assertMatchesRegularExpression(
            "/function switchTab\(index, btn\).*?const historyBack = document\.querySelector\('\[data-history-back\]'\);\s*if \(historyBack\) \{\s*historyBack\.hidden = target !== 1;\s*\}/s",
            $html
        );
    }

    // ══════════ filters ══════════

    public function test_today_means_the_manila_day_even_when_utc_is_still_yesterday(): void
    {
        // 00:10 Manila on the 20th is 16:10 UTC on the 19th.
        $today = $this->order('2026-10-20 00:10');
        $this->order('2026-10-19 23:50');

        $this->assertSame([$today->order_number], $this->shown($this->page(['range' => 'today'])->getContent()));
    }

    public function test_seven_days_covers_today_and_the_six_days_before_it(): void
    {
        $in = $this->order('2026-10-14 00:05');   // 6 days ago: inside
        $this->order('2026-10-13 23:55');          // 7 days ago: outside

        $html = $this->page(['range' => '7'])->getContent();

        $this->assertSame([$in->order_number], $this->shown($html));
        $this->assertSame('1 order', $this->counter($html));
    }

    public function test_thirty_days_covers_today_and_the_twenty_nine_days_before_it(): void
    {
        $in = $this->order('2026-09-21 00:05');   // 29 days ago: inside
        $this->order('2026-09-20 23:55');          // 30 days ago: outside

        $this->assertSame([$in->order_number], $this->shown($this->page(['range' => '30'])->getContent()));
    }

    public function test_a_chip_is_lit_and_the_others_are_not(): void
    {
        $this->order('2026-10-20 01:10');

        foreach (['today', '7', '30'] as $preset) {
            $html = $this->page(['range' => $preset])->getContent();
            $this->assertSame(1, substr_count($html, 'aria-current="true"'), "{$preset}: exactly one chip is lit");
            $this->assertMatchesRegularExpression('/data-preset="' . $preset . '"\s+aria-current="true"/', $html);
        }

        $this->assertMatchesRegularExpression('/data-preset="all"\s+aria-current="true"/', $this->page()->getContent());
    }

    public function test_the_date_range_filters_inclusively_and_unlights_the_chips(): void
    {
        $this->order('2026-10-15 22:23');
        $this->order('2026-10-15 22:18');
        $this->order('2026-10-16 00:01');
        $this->order('2026-10-14 23:59');

        $html = $this->page(['from' => '2026-10-15', 'to' => '2026-10-15'])->getContent();

        $this->assertSame('2 orders', $this->counter($html));
        $this->assertStringNotContainsString('aria-current="true"', $html, 'a typed range lights no chip');
        $this->assertStringContainsString('value="2026-10-15"', $html);
    }

    public function test_a_reversed_range_is_swapped_and_junk_dates_are_ignored(): void
    {
        $this->order('2026-10-15 12:00');

        $this->assertSame('1 order', $this->counter($this->page(['from' => '2026-10-16', 'to' => '2026-10-14'])->getContent()));
        $this->assertSame('1 order', $this->counter($this->page(['from' => '2026-13-45', 'to' => "x'; --"])->getContent()));
    }

    public function test_status_filter_keeps_only_completed_or_only_cancelled(): void
    {
        $done = $this->order('2026-10-20 01:10', 'completed');
        $gone = $this->order('2026-10-20 01:09', 'cancelled');

        $this->assertSame([$done->order_number], $this->shown($this->page(['status' => 'completed'])->getContent()));
        $this->assertSame([$gone->order_number], $this->shown($this->page(['status' => 'cancelled'])->getContent()));
        $this->assertCount(2, $this->shown($this->page(['status' => 'bogus'])->getContent()));
    }

    public function test_changing_only_the_status_keeps_the_chip_lit(): void
    {
        $this->order('2026-10-20 01:10');

        // What the form submits after picking a status while "7 days" is lit:
        // the chip's own dates travel with it.
        $html = $this->page(['range' => '7', 'from' => '2026-10-14', 'to' => '2026-10-20', 'status' => 'completed'])->getContent();

        $this->assertMatchesRegularExpression('/data-preset="7"\s+aria-current="true"/', $html);
    }

    public function test_an_empty_filtered_result_says_so_and_offers_reset(): void
    {
        $this->order('2026-10-01 12:00');

        $html = $this->page(['range' => 'today'])->assertOk()->getContent();

        $this->assertSame('0 orders', $this->counter($html));
        $this->assertStringContainsString('No orders in this range. Try a different date.', $html);
        $this->assertStringContainsString('data-testid="history-filters"', $html, 'the bar stays so the filter can be changed');
    }

    public function test_a_customer_with_no_orders_sees_the_empty_state_without_a_filter_bar(): void
    {
        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('No order history yet', $html);
        $this->assertStringNotContainsString('data-testid="history-filters"', $html);
    }

    public function test_reset_returns_to_all_orders_and_all_statuses_keeping_the_page_size(): void
    {
        $filter = OrderHistoryFilter::fromRequest(Request::create('/', 'GET', ['range' => '7', 'status' => 'cancelled', 'per_page' => '6']), CarbonImmutable::parse('2026-10-20', 'Asia/Manila'));

        $this->assertSame(['tab' => 'history', 'per_page' => 6], $filter->query(['range' => 'all', 'status' => 'all']));
        $this->assertTrue($filter->isFiltered());
    }

    // ══════════ per page and pagination ══════════

    public function test_the_default_is_nine_per_page_and_the_counter_is_the_total(): void
    {
        foreach (range(1, 15) as $i) {
            $this->order(sprintf('2026-10-%02d 12:00', $i));
        }

        $html = $this->page()->assertOk()->getContent();

        $this->assertCount(9, $this->shown($html));
        $this->assertSame('15 orders', $this->counter($html));
        $this->assertStringContainsString('data-testid="history-pager"', $html);
    }

    public function test_per_page_accepts_six_nine_and_twelve_and_falls_back_otherwise(): void
    {
        foreach (range(1, 15) as $i) {
            $this->order(sprintf('2026-10-%02d 12:00', $i));
        }

        $this->assertCount(6, $this->shown($this->page(['per_page' => 6])->getContent()));
        $this->assertCount(9, $this->shown($this->page(['per_page' => 9])->getContent()));
        $this->assertCount(12, $this->shown($this->page(['per_page' => 12])->getContent()));
        $this->assertCount(9, $this->shown($this->page(['per_page' => 500])->getContent()));
        $this->assertCount(9, $this->shown($this->page(['per_page' => 'all'])->getContent()));
    }

    public function test_the_pager_walks_through_every_order_newest_first_with_no_repeats(): void
    {
        foreach (range(1, 15) as $i) {
            $this->order(sprintf('2026-10-%02d 12:00', $i), 'completed', null, ['order_number' => sprintf('OHP-DAY%02d', $i)]);
        }

        $first  = $this->shown($this->page(['per_page' => 6])->getContent());
        $second = $this->shown($this->page(['per_page' => 6, 'page' => 2])->getContent());
        $third  = $this->shown($this->page(['per_page' => 6, 'page' => 3])->getContent());

        $this->assertSame(['OHP-DAY15', 'OHP-DAY14', 'OHP-DAY13', 'OHP-DAY12', 'OHP-DAY11', 'OHP-DAY10'], $first);
        $this->assertSame(['OHP-DAY09', 'OHP-DAY08', 'OHP-DAY07', 'OHP-DAY06', 'OHP-DAY05', 'OHP-DAY04'], $second);
        $this->assertSame(['OHP-DAY03', 'OHP-DAY02', 'OHP-DAY01'], $third);
    }

    public function test_pager_links_keep_the_active_filters(): void
    {
        foreach (range(1, 8) as $i) {
            $this->order(sprintf('2026-10-%02d 12:00', $i), $i % 2 ? 'completed' : 'cancelled');
        }

        $html = $this->page(['status' => 'completed', 'per_page' => 6])->assertOk()->getContent();

        $this->assertSame('4 orders', $this->counter($html));
        $this->assertStringNotContainsString('data-testid="history-pager"', $html, 'four orders fit on one page of six');

        $html = $this->page(['per_page' => 6])->getContent();
        $this->assertMatchesRegularExpression('/href="[^"]*per_page=6[^"]*page=2[^"]*"|href="[^"]*page=2[^"]*per_page=6[^"]*"/', $html);
        $this->assertStringContainsString('rel="next"', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html, 'Prev is disabled on page one');
    }

    public function test_a_page_past_the_end_shows_the_last_page_and_junk_page_numbers_the_first(): void
    {
        foreach (range(1, 10) as $i) {
            $this->order(sprintf('2026-10-%02d 12:00', $i));
        }

        $this->assertCount(1, $this->shown($this->page(['page' => 99])->assertOk()->getContent()));
        $this->assertCount(9, $this->shown($this->page(['page' => 'abc'])->assertOk()->getContent()));
        $this->assertCount(9, $this->shown($this->page(['page' => -4])->assertOk()->getContent()));
    }

    // ══════════ the filter object ══════════

    public function test_filter_defaults_and_validation(): void
    {
        $today = CarbonImmutable::parse('2026-10-20', 'Asia/Manila');
        $f = OrderHistoryFilter::fromRequest(Request::create('/', 'GET'), $today);

        $this->assertSame('all', $f->preset);
        $this->assertNull($f->from);
        $this->assertNull($f->to);
        $this->assertSame('all', $f->status);
        $this->assertSame(9, $f->perPage);
        $this->assertFalse($f->isFiltered());

        $f = OrderHistoryFilter::fromRequest(Request::create('/', 'GET', ['range' => '7']), $today);
        $this->assertSame(['7', '2026-10-14', '2026-10-20'], [$f->preset, $f->from, $f->to]);

        $f = OrderHistoryFilter::fromRequest(Request::create('/', 'GET', ['range' => 'forever', 'status' => ['x'], 'per_page' => '7']), $today);
        $this->assertSame(['all', 'all', 9], [$f->preset, $f->status, $f->perPage]);
    }
}
