<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\VoucherClaims;
use App\Support\ShopDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pages print the SHOP's time (Asia/Manila), whatever APP_TIMEZONE is.
 *
 * THE BUG
 * -------
 * Views printed the stored timestamp as is. Laravel stores timestamps as wall
 * clock in APP_TIMEZONE, so with APP_TIMEZONE=UTC an order placed at 8:55 PM
 * Manila printed "12:55 PM", and the orders page grouped by the UTC day, which
 * changes at 8:00 AM Manila. Every display now goes through ShopDay::format()
 * (or isToday()/isYesterday() for the grouping), which converts the stored
 * value from the app zone to Manila: a no-op under APP_TIMEZONE=Asia/Manila.
 *
 * Every test runs under both settings. The stored string differs between them
 * (that is the point) and the printed time must not.
 */
class ShopDisplayTimeTest extends TestCase
{
    use DatabaseTransactions;

    private const ORDER_PREFIX = 'TZD-';

    private string $zoneBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zoneBefore = date_default_timezone_get();
        Cache::flush();
        config(['app.shop_timezone' => 'Asia/Manila']);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
        // Process-wide: leaving it on Manila would leak into the next test.
        date_default_timezone_set($this->zoneBefore);
    }

    /**
     * One instant, 8:55 PM Manila on Oct 7 2026, as Laravel stores it under
     * each setting.
     */
    public static function zones(): array
    {
        return [
            'APP_TIMEZONE=UTC'         => ['UTC', '2026-10-07 12:55:00'],
            'APP_TIMEZONE=Asia/Manila' => ['Asia/Manila', '2026-10-07 20:55:00'],
        ];
    }

    /** Just the zone, for tests that build their own stored values. */
    public static function zoneNames(): array
    {
        return [
            'APP_TIMEZONE=UTC'         => ['UTC'],
            'APP_TIMEZONE=Asia/Manila' => ['Asia/Manila'],
        ];
    }

    // ── switching the setting (see ShopDayTimezoneParityTest) ────────────

    private function useAppTimezone(string $zone): void
    {
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
    }

    private function at(string $manila): void
    {
        $this->travelTo(CarbonImmutable::parse($manila, 'Asia/Manila')->setTimezone(config('app.timezone')));
    }

    /** A Manila wall time as Laravel would store it under the current setting. */
    private function storedFor(string $manila): string
    {
        return CarbonImmutable::parse($manila, 'Asia/Manila')
            ->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function customer(): User
    {
        return User::create([
            'name'              => 'TZ Display Test',
            'email'             => 'tzdisplay-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->fresh();
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    /** An order whose created_at (and completed_at) hold exactly $stored. */
    private function orderStoredAt(string $stored, ?User $user = null, string $status = 'completed', int $lines = 1): Order
    {
        $item  = MenuItem::where('is_available', true)->orderBy('id')->firstOrFail();
        $order = Order::create([
            'order_number'   => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'user_id'        => $user?->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal'       => 250,
            'total'          => 250,
        ]);

        for ($i = 0; $i < $lines; $i++) {
            OrderItem::create([
                'order_id'     => $order->id,
                'menu_item_id' => $item->id,
                'item_name'    => $item->name,
                'item_price'   => 125,
                'quantity'     => 2,
                'subtotal'     => 250,
            ]);
        }

        // created_at is not fillable: write the stored value directly.
        DB::table('orders')->where('id', $order->id)->update([
            'created_at'   => $stored,
            'completed_at' => $status === 'completed' ? $stored : null,
        ]);

        $this->assertSame($stored, (string) DB::table('orders')->where('id', $order->id)->value('created_at'));

        return $order->fresh();
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    // ══════════════════════════════════════════════════════════════════
    // The helper
    // ══════════════════════════════════════════════════════════════════

    #[DataProvider('zones')]
    public function test_the_helper_converts_a_stored_time_to_manila(string $zone, string $stored): void
    {
        $this->useAppTimezone($zone);
        $this->at('2026-10-08 09:00');

        $order = $this->orderStoredAt($stored);

        // CONTROL: what the views used to print. Under UTC it is the bug.
        $this->assertSame(
            $zone === 'UTC' ? '12:55 PM' : '08:55 PM',
            $order->created_at->format('h:i A')
        );

        $this->assertSame('Oct 07, 2026 08:55 PM', ShopDay::format($order->created_at, 'M d, Y h:i A'));
        $this->assertSame('Oct 07, 2026 08:55 PM', ShopDay::format($stored, 'M d, Y h:i A'), 'a raw DB string is read in the app zone');
        $this->assertSame('Asia/Manila', ShopDay::local($order->created_at)->getTimezone()->getName());
        $this->assertSame($order->created_at->getTimestamp(), ShopDay::local($order->created_at)->getTimestamp(), 'same instant, different wall clock');
        $this->assertSame('', ShopDay::format(null, 'h:i A'));
        $this->assertNull(ShopDay::local(''));

        // The model's own value is untouched: display only.
        $this->assertSame($zone, $order->created_at->getTimezone()->getName());
    }

    #[DataProvider('zoneNames')]
    public function test_an_expiry_date_prints_as_entered_and_an_instant_prints_its_manila_date(string $zone): void
    {
        $this->useAppTimezone($zone);
        $this->at('2026-10-08 09:00');

        $byDate = Voucher::create([
            'code' => 'TZD' . strtoupper(substr(uniqid(), -7)), 'discount_type' => 'fixed', 'discount_value' => 10,
            'max_uses' => 0, 'used_count' => 0, 'minimum_order' => 0, 'is_active' => true,
            'expires_at' => '2026-10-20',
        ]);
        // 4:00 AM Manila on the 21st = 8:00 PM UTC on the 20th.
        $byInstant = Voucher::create([
            'code' => 'TZD' . strtoupper(substr(uniqid(), -7)), 'discount_type' => 'fixed', 'discount_value' => 10,
            'max_uses' => 0, 'used_count' => 0, 'minimum_order' => 0, 'is_active' => true,
            'expires_at' => CarbonImmutable::parse('2026-10-21 04:00', 'Asia/Manila')->setTimezone($zone),
        ]);

        $this->assertSame('Oct 20, 2026', ShopDay::formatExpiry($byDate->fresh()->expires_at));
        $this->assertSame('Oct 21, 2026', ShopDay::formatExpiry($byInstant->fresh()->expires_at));
        $this->assertSame('', ShopDay::formatExpiry(null));

        // And My Vouchers prints exactly those.
        $user = $this->customer();
        foreach ([$byDate, $byInstant] as $voucher) {
            UserVoucher::create([
                'user_id' => $user->id, 'voucher_id' => $voucher->id,
                'claim_code' => VoucherClaims::mintCode(),
                'acquired_date' => '2026-10-08', 'valid_from' => '2026-10-08', 'is_used' => false,
            ]);
        }

        $html = $this->actingAs($user, 'customer')->get('/customer/vouchers')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Oct 08, 2026\s*–\s*Oct 20, 2026/u', $html);
        $this->assertMatchesRegularExpression('/Oct 08, 2026\s*–\s*Oct 21, 2026/u', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // Customer receipt
    // ══════════════════════════════════════════════════════════════════

    #[DataProvider('zones')]
    public function test_the_customer_receipt_prints_the_manila_time(string $zone, string $stored): void
    {
        $this->useAppTimezone($zone);
        $this->at('2026-10-08 09:00');

        $user  = $this->customer();
        $order = $this->orderStoredAt($stored, $user);

        $html = $this->actingAs($user, 'customer')
            ->get(route('customer.receipt', $order->id))
            ->assertOk()
            ->getContent();

        // Header line and the Order Details "Date" row.
        $this->assertSame(2, substr_count($html, 'Oct 07, 2026 08:55 PM'), 'the receipt does not print 8:55 PM Manila twice');
        $this->assertStringNotContainsString('12:55 PM', $html);
    }

    #[DataProvider('zones')]
    public function test_the_staff_receipt_prints_the_manila_time(string $zone, string $stored): void
    {
        $this->useAppTimezone($zone);
        $this->at('2026-10-08 09:00');

        $order = $this->orderStoredAt($stored);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.receipt', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, 'Oct 07, 2026 08:55 PM'));
        $this->assertStringNotContainsString('12:55 PM', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // Customer orders page: times and the Today / Yesterday grouping
    // ══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string, 1: string}> order number => [group heading, printed time] */
    private function historyRows(string $html): array
    {
        $xp   = $this->xpath($html);
        $rows = [];

        foreach ($xp->query("//div[@id='historyTab']//p[contains(concat(' ', normalize-space(@class), ' '), ' date-group ')]") as $heading) {
            $grid = $xp->query('following-sibling::div[1]', $heading)->item(0);

            foreach ($xp->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' history-card ')]", $grid) as $card) {
                $number = trim($xp->query(".//span[contains(@class, 'history-order-num')]", $card)->item(0)->textContent);
                $time   = trim($xp->query(".//span[contains(@class, 'history-time')]", $card)->item(0)->textContent);

                $rows[$number] = [trim($heading->textContent), $time];
            }
        }

        return $rows;
    }

    #[DataProvider('zoneNames')]
    public function test_the_orders_page_groups_by_the_manila_day_around_midnight(string $zone): void
    {
        $this->useAppTimezone($zone);
        $this->at('2026-10-08 00:30');

        $user = $this->customer();

        // Manila wall times, either side of both midnights and of 8:00 AM.
        $placed = [
            'A' => '2026-10-08 00:10',
            'B' => '2026-10-07 23:50',
            'C' => '2026-10-07 07:30',
            'D' => '2026-10-07 00:05',
            'E' => '2026-10-06 23:55',
        ];

        $numbers = [];
        foreach ($placed as $key => $manila) {
            $numbers[$key] = $this->orderStoredAt($this->storedFor($manila), $user)->order_number;
        }

        $rowsAt = function (string $manila) use ($user, $numbers): array {
            $this->at($manila);
            $rows = $this->historyRows(
                $this->actingAs($user, 'customer')->get('/customer/orders?tab=history')->assertOk()->getContent()
            );

            return array_map(fn ($number) => $rows[$number] ?? null, $numbers);
        };

        // Just after Manila midnight. Under UTC this is still Oct 7 (16:30),
        // so the old grouping put B under "Today" and E under "Yesterday".
        $this->assertSame([
            'A' => ['Today', '12:10 AM'],
            'B' => ['Yesterday', '11:50 PM'],
            'C' => ['Yesterday', '07:30 AM'],
            'D' => ['Yesterday', '12:05 AM'],
            'E' => ['Oct 06, 2026', '11:55 PM'],
        ], $rowsAt('2026-10-08 00:30'));

        // Just after 8:00 AM Manila, where the UTC day used to change.
        $this->assertSame([
            'A' => ['Today', '12:10 AM'],
            'B' => ['Yesterday', '11:50 PM'],
            'C' => ['Yesterday', '07:30 AM'],
            'D' => ['Yesterday', '12:05 AM'],
            'E' => ['Oct 06, 2026', '11:55 PM'],
        ], $rowsAt('2026-10-08 08:01'));

        // Just before the next Manila midnight.
        $this->assertSame([
            'A' => ['Today', '12:10 AM'],
            'B' => ['Yesterday', '11:50 PM'],
            'C' => ['Yesterday', '07:30 AM'],
            'D' => ['Yesterday', '12:05 AM'],
            'E' => ['Oct 06, 2026', '11:55 PM'],
        ], $rowsAt('2026-10-08 23:59'));

        // And just after it: everything moves back one day.
        $this->assertSame([
            'A' => ['Yesterday', '12:10 AM'],
            'B' => ['Oct 07, 2026', '11:50 PM'],
            'C' => ['Oct 07, 2026', '07:30 AM'],
            'D' => ['Oct 07, 2026', '12:05 AM'],
            'E' => ['Oct 06, 2026', '11:55 PM'],
        ], $rowsAt('2026-10-09 00:01'));
    }

    #[DataProvider('zones')]
    public function test_the_orders_page_prints_the_manila_time(string $zone, string $stored): void
    {
        $this->useAppTimezone($zone);
        $this->at('2026-10-08 09:00');

        $user   = $this->customer();
        $number = $this->orderStoredAt($stored, $user)->order_number;

        $rows = $this->historyRows(
            $this->actingAs($user, 'customer')->get('/customer/orders?tab=history')->assertOk()->getContent()
        );

        $this->assertSame(['Yesterday', '08:55 PM'], $rows[$number]);
    }

    // ══════════════════════════════════════════════════════════════════
    // Staff order lists and details
    // ══════════════════════════════════════════════════════════════════

    #[DataProvider('zones')]
    public function test_the_order_details_modal_on_the_staff_board_prints_the_manila_time(string $zone, string $stored): void
    {
        $this->useAppTimezone($zone);
        $this->at('2026-10-08 09:00');

        // The full-details button (and its "Placed ..." line) only renders
        // when a card hides lines: it shows 4, so give it 5.
        $order = $this->orderStoredAt($stored, null, 'pending', 5);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.home'))
            ->assertOk()
            ->getContent();

        $created = $this->xpath($html)
            ->query("//*[@data-order-number='{$order->order_number}']/@data-created")
            ->item(0)?->nodeValue;

        $this->assertSame('Oct 07, 2026 08:55 PM', $created);
    }

    #[DataProvider('zones')]
    public function test_the_completed_orders_list_and_its_print_view_print_the_manila_time(string $zone, string $stored): void
    {
        $this->useAppTimezone($zone);
        $this->at('2026-10-08 09:00');

        $order = $this->orderStoredAt($stored);
        $admin = $this->admin();

        $list = $this->actingAs($admin, 'admin')->get(route('admin.completed-orders'))->assertOk()->getContent();
        $date = $this->xpath($list)
            ->query("//tr[td[contains(@class, 'co-order-no') and normalize-space(.)='{$order->order_number}']]/td[contains(@class, 'co-date')]")
            ->item(0)?->textContent;

        $this->assertSame('Oct 07, 2026 08:55 PM', trim((string) $date));
        $this->assertStringContainsString('Generated: Oct 08, 2026 9:00 AM', $list);

        $print = $this->actingAs($admin, 'admin')->get(route('admin.completed-orders.print'))->assertOk()->getContent();
        $date  = $this->xpath($print)
            ->query("//tr[td[normalize-space(.)='{$order->order_number}']]/td[1]")
            ->item(0)?->textContent;

        $this->assertSame('Oct 07, 2026 08:55 PM', trim((string) $date));
        $this->assertStringContainsString('Generated: Oct 08, 2026 9:00 AM', $print);
    }
}
