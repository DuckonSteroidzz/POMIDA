<?php

namespace Tests\Feature;

use App\Models\GamePlayed;
use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\VoucherClaims;
use App\Support\GuestOrders;
use App\Support\ShopDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * The shop-day rules give the SAME answers whether the server runs on
 * APP_TIMEZONE=UTC (this machine's .env) or APP_TIMEZONE=Asia/Manila (what
 * .env.example ships, and possibly the live server).
 *
 * WHAT DIFFERS BETWEEN THE TWO SETTINGS
 * -------------------------------------
 * Laravel writes and reads every timestamp as a wall-clock time in the app
 * zone, so the same moment is stored as a different string:
 *
 *   Manila wall time   stored under UTC       stored under Asia/Manila
 *   Oct 7 23:59        2026-10-07 15:59:00    2026-10-07 23:59:00
 *   Oct 8 00:01        2026-10-07 16:01:00    2026-10-08 00:01:00
 *   Oct 8 07:59        2026-10-07 23:59:00    2026-10-08 07:59:00
 *   Oct 8 08:01        2026-10-08 00:01:00    2026-10-08 08:01:00
 *
 * ShopDay has to express the Manila day in whichever zone that is. A helper
 * that assumed UTC storage would be right under one setting and 8 hours off
 * under the other. Every test below runs one scenario under both settings and
 * requires the same answers from each, and the answers it expects.
 *
 * HOW THE SETTING IS SWITCHED
 * ---------------------------
 * Laravel applies APP_TIMEZONE once at boot with date_default_timezone_set(),
 * and Eloquent parses stored timestamps in that PHP default, so config() alone
 * changes nothing that matters: useAppTimezone() sets both. Each setting runs
 * inside its own savepoint, so the two runs never see each other's rows. The
 * clock is frozen in the app zone: under a frozen clock, Carbon parses stored
 * values in the frozen instant's zone, so freezing in any other zone would
 * misread every timestamp by the difference.
 */
class ShopDayTimezoneParityTest extends TestCase
{
    use DatabaseTransactions;
    use ForcesSpinOutcome;

    private const ZONES = ['UTC', 'Asia/Manila'];

    /** The four boundary instants, as Manila wall times. */
    private const T2359 = '2026-10-07 23:59';
    private const T0001 = '2026-10-08 00:01';
    private const T0759 = '2026-10-08 07:59';
    private const T0801 = '2026-10-08 08:01';

    private const POINTS = [self::T2359, self::T0001, self::T0759, self::T0801];

    private string $zoneBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zoneBefore = date_default_timezone_get();
        Cache::flush();
        config(['app.shop_timezone' => 'Asia/Manila']);
        $this->enableGame();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
        // Process-wide: leaving it on Manila would leak into the next test.
        date_default_timezone_set($this->zoneBefore);
    }

    // ── switching the setting ───────────────────────────────────────────

    private function useAppTimezone(string $zone): void
    {
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
    }

    private function at(string $manila): void
    {
        $this->travelTo(CarbonImmutable::parse($manila, 'Asia/Manila')->setTimezone(config('app.timezone')));
    }

    /**
     * Run $scenario once per setting, each in its own savepoint and with a
     * fresh visitor, and return what it answered keyed by setting.
     *
     * @return array<string, mixed>
     */
    private function runUnderEachZone(callable $scenario): array
    {
        $answers = [];

        foreach (self::ZONES as $zone) {
            DB::beginTransaction();

            try {
                $this->useAppTimezone($zone);
                $this->freshVisitor();
                $answers[$zone] = $scenario($zone);
            } finally {
                DB::rollBack();
                $this->travelBack();
            }
        }

        return $answers;
    }

    private function assertSameAnswersUnderBothZones(array $expected, callable $scenario): void
    {
        $answers = $this->runUnderEachZone($scenario);

        foreach (self::ZONES as $zone) {
            $this->assertSame($expected, $answers[$zone], "APP_TIMEZONE={$zone} gave the wrong answers");
        }

        $this->assertSame($answers['UTC'], $answers['Asia/Manila'], 'the two settings disagree');
    }

    private function freshVisitor(): void
    {
        Cache::flush();
        session()->flush();
        $this->app['auth']->forgetGuards();
    }

    /** The raw column value, exactly as stored (no Eloquent cast). */
    private function stored(string $table, int $id, string $column): string
    {
        return (string) DB::table($table)->where('id', $id)->value($column);
    }

    // ── fixtures (all inside the savepoint) ─────────────────────────────

    private function enableGame(): void
    {
        DB::table('settings')->where('key', 'game_enabled')->delete();
        DB::table('settings')->insert([
            'key' => 'game_enabled', 'branch_id' => null, 'value' => '1',
            'group' => 'business', 'label' => 'Spin & Win Enabled', 'type' => 'text',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'name'              => 'TZ Parity Test',
            'email'             => 'tzparity-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->fresh();
    }

    /** An order placed at the current (frozen) time; created_at is not fillable, the clock sets it. */
    private function order(?User $user = null): Order
    {
        $order = Order::create([
            'order_number'   => 'TZP-' . substr(uniqid(), -8),
            'user_id'        => $user?->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 500,
            'total'          => 500,
        ]);

        if ($user === null) {
            GuestOrders::remember($order->id);
        }

        return $order;
    }

    private function wheelVoucher(array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'code'            => 'TZP' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'TZ parity test voucher',
            'discount_type'   => 'fixed',
            'discount_value'  => 10,
            'max_uses'        => 0,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'expires_at'      => null,
            'valid_from'      => null,
            'is_active'       => true,
            'points_required' => 5,
        ], $attrs));
    }

    private function spinState(?User $user = null): array
    {
        $request = $user ? $this->actingAs($user, 'customer') : $this;

        return $request->get('/customer/game')->assertOk()->viewData('spinState');
    }

    private function spendDailyCap(User $user, Order $order): void
    {
        for ($i = 1; $i <= 15; $i++) {
            GamePlayed::create([
                'user_id' => $user->id, 'order_id' => $order->id,
                'spin_number' => $i, 'points_awarded' => 0,
            ]);
        }
    }

    /** Points from $from onward, for "seen at" columns. */
    private function pointsFrom(string $from): array
    {
        return array_values(array_filter(self::POINTS, fn ($p) => $p >= $from));
    }

    // ══════════════════════════════════════════════════════════════════
    // The two runs really are different storage
    // ══════════════════════════════════════════════════════════════════

    /**
     * Without this, a zone switch that silently did nothing would make the
     * parity tests below pass trivially: both runs would store the same
     * strings. The stored values must DIFFER by setting, and both must read
     * back as the same instant.
     */
    public function test_the_same_instant_is_stored_differently_under_each_setting(): void
    {
        $stored  = [];
        $instant = [];

        $this->runUnderEachZone(function (string $zone) use (&$stored, &$instant) {
            foreach (self::POINTS as $point) {
                $this->at($point);
                $order = $this->order($this->customer());

                $stored[$zone][$point]  = $this->stored('orders', $order->id, 'created_at');
                $instant[$zone][$point] = $order->fresh()->created_at->getTimestamp();
            }
        });

        $this->assertSame([
            self::T2359 => '2026-10-07 15:59:00',
            self::T0001 => '2026-10-07 16:01:00',
            self::T0759 => '2026-10-07 23:59:00',
            self::T0801 => '2026-10-08 00:01:00',
        ], $stored['UTC']);

        $this->assertSame([
            self::T2359 => '2026-10-07 23:59:00',
            self::T0001 => '2026-10-08 00:01:00',
            self::T0759 => '2026-10-08 07:59:00',
            self::T0801 => '2026-10-08 08:01:00',
        ], $stored['Asia/Manila']);

        $this->assertSame($instant['UTC'], $instant['Asia/Manila'], 'the two settings read back different instants');
    }

    public function test_bounds_are_the_manila_day_in_the_app_zone_under_each_setting(): void
    {
        $answers = $this->runUnderEachZone(function () {
            $this->at(self::T0001);
            [$start, $end] = ShopDay::bounds();

            return [
                'zone'    => $start->getTimezone()->getName(),
                'start'   => $start->format('Y-m-d H:i:s'),
                'end'     => $end->format('Y-m-d H:i:s'),
                'instant' => [$start->getTimestamp(), $end->getTimestamp()],
                'today'   => ShopDay::todayString(),
            ];
        });

        // The query builder binds a Carbon by formatting it WITHOUT converting
        // its zone, so the bounds must be written in the zone rows are stored in.
        $this->assertSame('UTC', $answers['UTC']['zone']);
        $this->assertSame('2026-10-07 16:00:00', $answers['UTC']['start']);
        $this->assertSame('2026-10-08 16:00:00', $answers['UTC']['end']);

        $this->assertSame('Asia/Manila', $answers['Asia/Manila']['zone']);
        $this->assertSame('2026-10-08 00:00:00', $answers['Asia/Manila']['start']);
        $this->assertSame('2026-10-09 00:00:00', $answers['Asia/Manila']['end']);

        // Same two instants, same shop date.
        $this->assertSame($answers['UTC']['instant'], $answers['Asia/Manila']['instant']);
        $this->assertSame('2026-10-08', $answers['UTC']['today']);
        $this->assertSame('2026-10-08', $answers['Asia/Manila']['today']);
    }

    // ══════════════════════════════════════════════════════════════════
    // Spins per order placed today
    // ══════════════════════════════════════════════════════════════════

    public function test_spins_per_order_give_the_same_answers_under_both_settings(): void
    {
        $expected = [
            'placed ' . self::T2359 => ['seen ' . self::T2359 => [7, true], 'seen ' . self::T0001 => [0, false], 'seen ' . self::T0759 => [0, false], 'seen ' . self::T0801 => [0, false]],
            'placed ' . self::T0001 => ['seen ' . self::T0001 => [7, true], 'seen ' . self::T0759 => [7, true], 'seen ' . self::T0801 => [7, true]],
            'placed ' . self::T0759 => ['seen ' . self::T0759 => [7, true], 'seen ' . self::T0801 => [7, true]],
            'placed ' . self::T0801 => ['seen ' . self::T0801 => [7, true]],
        ];

        $this->assertSameAnswersUnderBothZones($expected, function () {
            $answers = [];

            foreach (self::POINTS as $placedAt) {
                $user = $this->customer();
                $this->at($placedAt);
                $order = $this->order($user);

                foreach ($this->pointsFrom($placedAt) as $seenAt) {
                    $this->at($seenAt);

                    // [spins the Game page grants, what the Spin Prompt's isToday() says]
                    $answers["placed {$placedAt}"]["seen {$seenAt}"] = [
                        $this->spinState($user)['spins_total'],
                        ShopDay::isToday($order->fresh()->created_at),
                    ];
                }
            }

            return $answers;
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // The daily spin cap
    // ══════════════════════════════════════════════════════════════════

    public function test_the_daily_spin_cap_gives_the_same_answers_under_both_settings(): void
    {
        $expected = [
            'cap spent ' . self::T2359 => ['seen ' . self::T2359 => 0, 'seen ' . self::T0001 => 15, 'seen ' . self::T0759 => 15, 'seen ' . self::T0801 => 15],
            'cap spent ' . self::T0001 => ['seen ' . self::T0001 => 0, 'seen ' . self::T0759 => 0, 'seen ' . self::T0801 => 0],
            'cap spent ' . self::T0759 => ['seen ' . self::T0759 => 0, 'seen ' . self::T0801 => 0],
            'cap spent ' . self::T0801 => ['seen ' . self::T0801 => 0],
            // The spin endpoint re-derives the cap itself.
            'endpoint at 00:01, cap spent at 23:59' => 'allowed',
            'endpoint at 08:01, cap spent at 00:01' => 'daily_limit',
        ];

        $this->assertSameAnswersUnderBothZones($expected, function () {
            $answers = [];

            foreach (self::POINTS as $spentAt) {
                $user = $this->customer();
                $this->at($spentAt);
                $this->spendDailyCap($user, $this->order($user));

                foreach ($this->pointsFrom($spentAt) as $seenAt) {
                    $this->at($seenAt);
                    $answers["cap spent {$spentAt}"]["seen {$seenAt}"] = $this->spinState($user)['daily_remaining'];
                }
            }

            $this->forceSpinOutcome(3);

            $spin = function (User $user): string {
                $response = $this->actingAs($user, 'customer')->postJson('/customer/add-points');

                return $response->json('success') ? 'allowed' : (string) $response->json('blocked');
            };

            $lastNight = $this->customer();
            $this->at(self::T2359);
            $this->spendDailyCap($lastNight, $this->order($lastNight));
            $this->at(self::T0001);
            $this->order($lastNight); // a fresh order, so only the cap can refuse
            $answers['endpoint at 00:01, cap spent at 23:59'] = $spin($lastNight);

            $earlyMorning = $this->customer();
            $this->at(self::T0001);
            $this->spendDailyCap($earlyMorning, $this->order($earlyMorning));
            $this->at(self::T0801);
            $this->order($earlyMorning); // 3 orders today = 21 spins > 15 used,
            $this->order($earlyMorning); // so the order window still has spins
            $answers['endpoint at 08:01, cap spent at 00:01'] = $spin($earlyMorning);

            return $answers;
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // Voucher valid_from
    // ══════════════════════════════════════════════════════════════════

    public function test_voucher_valid_from_gives_the_same_answers_under_both_settings(): void
    {
        $expected = [
            'claim valid from 2026-10-08' => ['seen ' . self::T2359 => 'not_yet_valid', 'seen ' . self::T0001 => 'ready', 'seen ' . self::T0759 => 'ready', 'seen ' . self::T0801 => 'ready'],
            'public code valid from 2026-10-08' => ['seen ' . self::T2359 => false, 'seen ' . self::T0001 => true, 'seen ' . self::T0759 => true, 'seen ' . self::T0801 => true],
            'counter code minted' => ['at ' . self::T2359 => '2026-10-07', 'at ' . self::T0001 => '2026-10-08', 'at ' . self::T0759 => '2026-10-08', 'at ' . self::T0801 => '2026-10-08'],
        ];

        $this->assertSameAnswersUnderBothZones($expected, function () {
            $answers = [];
            $user    = $this->customer();
            $claim   = UserVoucher::create([
                'user_id' => $user->id, 'voucher_id' => $this->wheelVoucher()->id,
                'claim_code' => VoucherClaims::mintCode(),
                'acquired_date' => '2026-10-07', 'valid_from' => '2026-10-08', 'is_used' => false,
            ]);
            $publicCode = $this->wheelVoucher(['points_required' => 0, 'valid_from' => '2026-10-08']);
            $counter    = $this->wheelVoucher();

            foreach (self::POINTS as $point) {
                $this->at($point);

                $answers['claim valid from 2026-10-08']["seen {$point}"]       = $claim->fresh()->statusKey();
                $answers['public code valid from 2026-10-08']["seen {$point}"] = $publicCode->fresh()->isRedeemableToday();
                $answers['counter code minted']["at {$point}"]                 = VoucherClaims::mintForCounter($counter, $user->id)
                    ->fresh()->valid_from->toDateString();
            }

            return $answers;
        });
    }

    /** The prize a spin wins is dated by the shop day too: it is usable the moment it is won. */
    public function test_a_spin_prize_is_dated_by_the_shop_day_under_both_settings(): void
    {
        $expected = [
            'won ' . self::T2359 => ['2026-10-07', '2026-10-07', true],
            'won ' . self::T0001 => ['2026-10-08', '2026-10-08', true],
            'won ' . self::T0759 => ['2026-10-08', '2026-10-08', true],
            'won ' . self::T0801 => ['2026-10-08', '2026-10-08', true],
        ];

        $this->assertSameAnswersUnderBothZones($expected, function () {
            $answers = [];
            $this->forceSpinOutcome(8);

            foreach (self::POINTS as $point) {
                $this->freshVisitor(); // a new guest each time: fresh points and spins
                Voucher::query()->update(['is_active' => false]); // inside the savepoint
                $prize = $this->wheelVoucher();

                $this->at($point);
                $this->order();

                $spin = $this->postJson('/customer/add-points')->assertOk();
                $this->assertTrue($spin->json('success'), json_encode($spin->json()));

                $claim = UserVoucher::where('voucher_id', $prize->id)->latest('id')->firstOrFail();

                $answers["won {$point}"] = [
                    $claim->acquired_date->toDateString(),
                    $claim->valid_from->toDateString(),
                    $prize->fresh()->redemptionErrorFor(null, 100, $claim) === null,
                ];
            }

            return $answers;
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // Voucher expiry
    // ══════════════════════════════════════════════════════════════════

    public function test_voucher_expiry_gives_the_same_answers_under_both_settings(): void
    {
        $expected = [
            // An admin-form date: valid through that whole Manila date.
            'expires 2026-10-07' => ['seen ' . self::T2359 => 'valid', 'seen ' . self::T0001 => 'expired', 'seen ' . self::T0759 => 'expired', 'seen ' . self::T0801 => 'expired'],
            'expires 2026-10-08' => ['seen ' . self::T2359 => 'valid', 'seen ' . self::T0001 => 'valid', 'seen ' . self::T0759 => 'valid', 'seen ' . self::T0801 => 'valid', 'seen 2026-10-08 23:59' => 'valid', 'seen 2026-10-09 00:01' => 'expired'],
            // A precise instant expires at that instant.
            'expires at 2026-10-08 10:00 Manila' => ['seen ' . self::T0801 => 'valid', 'seen 2026-10-08 09:59' => 'valid', 'seen 2026-10-08 10:01' => 'expired'],
        ];

        $this->assertSameAnswersUnderBothZones($expected, function (string $zone) {
            // How each kind of value is really written: the admin form posts a
            // bare date; code would write an instant in the app zone (now()-based).
            $byDate7   = $this->wheelVoucher(['points_required' => 0, 'expires_at' => '2026-10-07']);
            $byDate8   = $this->wheelVoucher(['points_required' => 0, 'expires_at' => '2026-10-08']);
            $byInstant = $this->wheelVoucher([
                'points_required' => 0,
                'expires_at'      => CarbonImmutable::parse('2026-10-08 10:00', 'Asia/Manila')->setTimezone($zone),
            ]);

            // The date is stored the same under both settings; the instant is not.
            $this->assertSame('2026-10-07 00:00:00', $this->stored('vouchers', $byDate7->id, 'expires_at'));
            $this->assertSame(
                $zone === 'UTC' ? '2026-10-08 02:00:00' : '2026-10-08 10:00:00',
                $this->stored('vouchers', $byInstant->id, 'expires_at')
            );

            $verdict = function (Voucher $voucher, string $seenAt): string {
                $this->at($seenAt);
                $fresh     = $voucher->fresh();
                $model     = $fresh->isExpired() ? 'expired' : 'valid';
                $sql       = Voucher::whereKey($voucher->id)->notExpired()->exists() ? 'valid' : 'expired';
                $redeem    = $fresh->availabilityErrorFor(null) === null ? 'valid' : 'expired';

                $this->assertSame($model, $sql, "isExpired() and scopeNotExpired() disagree at {$seenAt}");
                $this->assertSame($model, $redeem, "isExpired() and availabilityErrorFor() disagree at {$seenAt}");

                return $model;
            };

            $answers = [];

            foreach (self::POINTS as $point) {
                $answers['expires 2026-10-07']["seen {$point}"] = $verdict($byDate7, $point);
            }

            foreach ([...self::POINTS, '2026-10-08 23:59', '2026-10-09 00:01'] as $point) {
                $answers['expires 2026-10-08']["seen {$point}"] = $verdict($byDate8, $point);
            }

            foreach ([self::T0801, '2026-10-08 09:59', '2026-10-08 10:01'] as $point) {
                $answers['expires at 2026-10-08 10:00 Manila']["seen {$point}"] = $verdict($byInstant, $point);
            }

            return $answers;
        });
    }
}
