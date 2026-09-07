<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the Admin → Analytics "Sales Forecast" demonstrable as the calendar moves.
 *
 * THE PROBLEM THIS SOLVES
 * -----------------------
 * salesForecast() regresses over a ROLLING window ending today, and refuses to
 * draw anything until at least FORECAST_MIN_DAYS_WITH_SALES of those days carry
 * completed sales. DemoSalesSeeder was run once, on 2026-08-23, writing orders
 * at fixed calendar dates. Those dates do not move. Every day that passes slides
 * one of them out of the window, so the forecast was guaranteed to revert to
 * "insufficient data" at some point before the defense, silently, with nothing
 * to announce it.
 *
 * So instead of seeding once, coverage is checked (cheaply) whenever the
 * forecast is about to be rendered, and topped up only on the rare occasion it
 * has actually gone thin. Rows that have aged out of the window are pruned on
 * the same pass, so the table cannot grow without bound.
 *
 * THIS FABRICATES SALES. Read isEnabled() before anything else.
 *
 * CONVENTIONS (identical to DemoSalesSeeder, deliberately — the generation code
 * now lives here and the seeder delegates to it, so the two cannot drift):
 *  - every row carries notes = MARKER, the only handle used to find or remove them
 *  - user_id is NULL and type is 'walk_in', so they can never surface inside a
 *    real customer's order history or receipts
 *  - NO inventory is deducted and NO stock_movements rows are written; these
 *    sales never physically happened and must not touch real stock figures
 *  - money is derived, never invented: line subtotal = price x qty, order
 *    subtotal = sum of lines, total = subtotal - discount
 *  - COST is derived the same way, through the SAME service a real sale uses.
 *    Each line carries order_items.ingredient_cost, snapshotted from
 *    MenuItemCosting — which delegates the recipe walk to
 *    InventoryDeductionService::requirementsForLine(), the very walker that
 *    decides what a real order takes off the shelf. Reading a cost does not
 *    move stock, so the no-deduction rule above is untouched.
 *
 *    This matters because it is what the demonstration is FOR. Without the
 *    snapshot every fabricated line arrived at ProfitCalculationService with a
 *    NULL cost and fell down its legacy-approximation branch, so the COGS,
 *    Gross Profit and Margin figures on the Summary screen and the printed
 *    report were produced by a fallback path that no real sale ever takes.
 *    Demo data is only worth showing if it exercises the real logic.
 */
class DemoSalesTopUp
{
    /** Stamped into orders.notes. The only handle for finding these rows. */
    public const MARKER = 'SEEDED_DEMO_SALES';

    /**
     * Coverage aimed for when a top-up runs — comfortably above the forecast's
     * minimum rather than exactly on it, so a single day rolling out of the
     * window does not immediately trigger another top-up.
     */
    private const TARGET_DAYS_WITH_SALES = 17;

    /**
     * Hard ceiling on days generated in one invocation. Belt-and-braces: even
     * if the coverage query were somehow wrong, a single request can never
     * write more than this.
     */
    private const MAX_DAYS_PER_RUN = 25;

    /** Branch the demo history belongs to. Matches DemoSalesSeeder. */
    private const BRANCH_ID = 1;

    private const LOCK_KEY = 'demo-sales-top-up';

    /**
     * MenuItemCosting is the project's single answer to "what does this cost
     * to make". Injected rather than newed so the demo path cannot end up
     * costing items by a different rule than the rest of the app.
     */
    public function __construct(private MenuItemCosting $costing)
    {
    }

    /**
     * May this class fabricate sales right now?
     *
     * TWO independent conditions, both of which must be affirmatively true.
     * Either one alone is not enough, and both default to the safe answer:
     *
     *  1. config('demo.auto_top_up_sales') — env DEMO_SALES_AUTO_TOPUP,
     *     which defaults to FALSE when absent.
     *  2. The application environment is exactly 'local'. config('app.env')
     *     falls back to 'production' when APP_ENV is missing or empty, so a
     *     truncated, half-copied or entirely absent .env fails CLOSED.
     *
     * A production deployment (Hostinger or anywhere else) would have to both
     * set APP_ENV=local AND set DEMO_SALES_AUTO_TOPUP=true before a single
     * fabricated row could be written. Copying the local .env up verbatim is
     * not enough on its own, because a real deployment sets APP_ENV=production.
     */
    public static function isEnabled(): bool
    {
        return config('demo.auto_top_up_sales') === true
            && app()->environment('local');
    }

    /**
     * The whole job: prune what has aged out, then top up if coverage is short.
     *
     * Safe to call on every request. On the happy path — which is every request
     * once coverage is healthy — this runs two cheap indexed reads and writes
     * nothing at all.
     *
     * Never throws into the request: analytics failing to render is a far worse
     * outcome than demo data being briefly stale, so problems are logged and
     * swallowed.
     *
     * @return array{status:string, coverage?:int, pruned?:int, generated_days?:int, generated_orders?:int}
     */
    public function ensureForecastCoverage(): array
    {
        if (!self::isEnabled()) {
            return ['status' => 'disabled'];
        }

        try {
            $lookback = AnalyticsService::FORECAST_LOOKBACK_DAYS;

            // Demo rows written before the cost snapshot existed still have a
            // NULL cost and still push the profit figures down the legacy
            // approximation branch. Heal them in place rather than requiring a
            // purge-and-reseed, which would throw away the history the
            // forecast is currently regressing over.
            $this->backfillCostSnapshots();

            $pruned = $this->pruneAged($lookback);

            $coverage = $this->coverageDays($lookback);

            if ($coverage >= AnalyticsService::FORECAST_MIN_DAYS_WITH_SALES) {
                return ['status' => 'sufficient', 'coverage' => $coverage, 'pruned' => $pruned];
            }

            // Two admins opening Analytics at the same moment must not both
            // generate a history. Whoever loses the lock simply skips; the page
            // still renders, and the next request sees the topped-up data.
            $lock = Cache::lock(self::LOCK_KEY, 30);

            if (!$lock->get()) {
                return ['status' => 'locked', 'coverage' => $coverage, 'pruned' => $pruned];
            }

            try {
                // Re-measure under the lock — the request we were racing may
                // have already fixed it while we waited.
                $coverage = $this->coverageDays($lookback);

                if ($coverage >= AnalyticsService::FORECAST_MIN_DAYS_WITH_SALES) {
                    return ['status' => 'sufficient', 'coverage' => $coverage, 'pruned' => $pruned];
                }

                $result = $this->topUp($lookback, $coverage);

                return array_merge(['status' => 'topped_up', 'pruned' => $pruned], $result);
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            Log::warning('Demo sales top-up skipped: ' . $e->getMessage());

            return ['status' => 'error'];
        }
    }

    /**
     * How many of the last $lookback days carry at least one completed order.
     *
     * Deliberately NOT branch-scoped, unlike salesForecast() itself.
     *
     * If this were scoped to the branch currently selected in the admin UI,
     * viewing a branch with no sales of its own (Branch 2 and 3 have none)
     * would report a shortfall on every single page load, while the generated
     * history — which belongs to Branch 1 — would never satisfy it. That is an
     * unbounded write loop. Measuring globally makes the decision idempotent:
     * once a top-up has run, every subsequent request sees sufficient coverage
     * and writes nothing.
     *
     * The consequence, stated plainly: an admin viewing a single branch that
     * genuinely has no sales still sees "insufficient data". That is the honest
     * answer for that branch, and it is the safe failure direction.
     */
    public function coverageDays(int $lookback): int
    {
        return DB::table('orders')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '>=', now()->subDays($lookback - 1)->toDateString())
            ->whereDate('completed_at', '<=', now()->toDateString())
            ->distinct()
            ->count(DB::raw('DATE(completed_at)'));
    }

    /**
     * Delete demo rows that have slid out of the forecast window.
     *
     * Only ever touches rows carrying MARKER, and only those already too old to
     * influence anything. Real orders are never eligible, whatever their age.
     */
    public function pruneAged(int $lookback): int
    {
        $cutoff = now()->subDays($lookback - 1)->startOfDay();

        $ids = Order::where('notes', self::MARKER)
            ->where(function ($q) use ($cutoff) {
                $q->where('completed_at', '<', $cutoff)
                    ->orWhereNull('completed_at');
            })
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return $this->deleteOrders($ids->all());
    }

    /**
     * Generate history for whichever recent days currently have no sales, until
     * coverage reaches TARGET_DAYS_WITH_SALES.
     *
     * Gaps are filled newest-first so the resulting history is contiguous and
     * ends yesterday. A history with holes in the middle regresses badly and
     * looks broken on the chart.
     *
     * @return array{coverage:int, generated_days:int, generated_orders:int}
     */
    private function topUp(int $lookback, int $coverage): array
    {
        $needed = min(
            self::TARGET_DAYS_WITH_SALES - $coverage,
            self::MAX_DAYS_PER_RUN
        );

        if ($needed <= 0) {
            return ['coverage' => $coverage, 'generated_days' => 0, 'generated_orders' => 0];
        }

        $daysWithSales = $this->daysWithSalesSet($lookback);

        // Day 1 back = yesterday. Today is skipped on purpose: today's takings
        // should reflect whatever actually happens during the demo.
        $targets = [];
        for ($back = 1; $back <= $lookback - 1 && count($targets) < $needed; $back++) {
            $date = now()->subDays($back)->toDateString();

            if (!isset($daysWithSales[$date])) {
                $targets[] = $back;
            }
        }

        $orders = $this->generateDays($targets);

        return [
            'coverage'         => $this->coverageDays($lookback),
            'generated_days'   => count($targets),
            'generated_orders' => $orders,
        ];
    }

    /** @return array<string,true> dates (Y-m-d) that already have completed sales */
    private function daysWithSalesSet(int $lookback): array
    {
        return DB::table('orders')
            ->selectRaw('DATE(completed_at) as d')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '>=', now()->subDays($lookback - 1)->toDateString())
            ->groupBy('d')
            ->pluck('d')
            ->mapWithKeys(fn ($d) => [(string) $d => true])
            ->all();
    }

    /**
     * Build a plausible trading day for each offset given, and return how many
     * orders were written in total.
     *
     * This is the single implementation of "what does a fabricated day look
     * like"; DemoSalesSeeder calls straight into it.
     *
     * @param  int[]     $daysBack     e.g. [1, 2, 5] = yesterday, 2 days ago, 5 days ago
     * @param  int|null  $randomSeed   Seed mt_rand for reproducible output. Only
     *                                 the artisan seeder passes this — seeding the
     *                                 global RNG from inside a web request would
     *                                 reach further than this class should.
     */
    public function generateDays(array $daysBack, ?int $randomSeed = null): int
    {
        if (empty($daysBack)) {
            return 0;
        }

        $menu = MenuItem::where('branch_id', self::BRANCH_ID)
            ->where('is_available', true)
            ->get();

        if ($menu->count() < 3) {
            Log::warning('Demo sales top-up: not enough branch-' . self::BRANCH_ID . ' menu items.');
            return 0;
        }

        if ($randomSeed !== null) {
            mt_srand($randomSeed);
        }

        $created = 0;

        // Oldest first, so the generated sequence reads naturally in the table.
        rsort($daysBack);

        foreach ($daysBack as $back) {
            $day = now()->subDays($back)->startOfDay();

            // Weekends busier than weekdays, so the trend line has some shape
            // instead of being a flat fabricated block.
            $isWeekend = in_array($day->dayOfWeek, [0, 6], true);
            $ordersToday = $isWeekend ? mt_rand(4, 6) : mt_rand(2, 4);

            for ($n = 0; $n < $ordersToday; $n++) {
                $created += $this->createOrder($day, $menu) ? 1 : 0;
            }
        }

        return $created;
    }

    /**
     * One fabricated order, inside its own transaction.
     *
     * @param  \Illuminate\Support\Collection<int,MenuItem>  $menu
     */
    private function createOrder(Carbon $day, $menu): bool
    {
        // Café trading hours, so completed_at is plausible.
        $placedAt = $day->copy()
            ->addHours(mt_rand(9, 19))
            ->addMinutes(mt_rand(0, 59));

        $lines = [];
        $subtotal = 0.0;

        foreach ($menu->random(min(mt_rand(1, 3), $menu->count())) as $item) {
            $qty = mt_rand(1, 3);
            $lineSubtotal = round((float) $item->price * $qty, 2);
            $subtotal += $lineSubtotal;

            $lines[] = [
                'menu_item_id' => $item->id,
                'item_name'    => $item->name,
                'item_price'   => $item->price,
                'quantity'     => $qty,
                'subtotal'     => $lineSubtotal,
                // The COGS snapshot, in exactly the shape and units
                // InventoryDeductionService::deductWithLock() writes for a
                // real sale: cost of ONE unit, priced off the recipe. Read
                // only — no stock moves.
                'ingredient_cost' => $this->costing->costFor($item),
            ];
        }

        $subtotal = round($subtotal, 2);

        // Roughly one order in six carries a PWD/Senior discount, the statutory
        // 20% this app already applies elsewhere.
        $discountType = mt_rand(1, 6) === 1
            ? (mt_rand(0, 1) ? 'pwd' : 'senior')
            : null;

        $discount = $discountType ? round($subtotal * 0.20, 2) : 0.0;
        $total = round($subtotal - $discount, 2);

        DB::transaction(function () use ($lines, $subtotal, $discount, $discountType, $total, $placedAt) {
            $order = Order::create([
                'order_number'    => 'ORD-' . $placedAt->format('Ymd') . '-'
                    . strtoupper(substr(md5(uniqid('', true)), 0, 6)),
                'user_id'         => null,
                'branch_id'       => self::BRANCH_ID,
                'type'            => 'walk_in',
                'status'          => 'completed',
                'subtotal'        => $subtotal,
                'discount_amount' => $discount,
                'tax_amount'      => 0,
                'total'           => $total,
                'discount_type'   => $discountType,
                // NOT NULL with default 'approved'; every real row carries it.
                'discount_status' => 'approved',
                'payment_method'  => mt_rand(1, 4) === 1 ? 'gcash' : 'cash',
                'payment_status'  => 'paid',
                'amount_paid'     => $total,
                'change_amount'   => 0,
                'notes'           => self::MARKER,
                'confirmed_at'    => $placedAt,
                'completed_at'    => $placedAt->copy()->addMinutes(mt_rand(8, 25)),
                'created_at'      => $placedAt,
                'updated_at'      => $placedAt,
            ]);

            // Matches the format completeOrder() writes.
            $order->receipt_number = 'RCP-' . $placedAt->format('Ymd') . '-'
                . str_pad((string) $order->id, 4, '0', STR_PAD_LEFT);
            $order->save();

            foreach ($lines as $line) {
                OrderItem::create($line + [
                    'order_id'   => $order->id,
                    'created_at' => $placedAt,
                    'updated_at' => $placedAt,
                ]);
            }
        });

        return true;
    }

    /**
     * Give older demo lines the COGS snapshot that generateDays() now writes
     * at creation time.
     *
     * Bounded three ways, so it can never touch a real sale: the parent order
     * must carry MARKER, the line must have no usable snapshot already
     * (NULL or 0), and the menu item must still resolve. Nothing else on the
     * row is written.
     *
     * Costing at today's prices is not a liberty being taken — it is the very
     * figure ProfitCalculationService was already substituting at read time
     * for these rows. The numbers on screen do not move; what changes is that
     * they now come from the snapshot column, by the same route a real sale
     * takes, instead of from a fallback branch.
     *
     * @return int lines updated
     */
    public function backfillCostSnapshots(): int
    {
        $items = OrderItem::query()
            ->whereIn(
                'order_id',
                Order::where('notes', self::MARKER)->select('id')
            )
            ->where(function ($q) {
                $q->whereNull('ingredient_cost')->orWhere('ingredient_cost', 0);
            })
            ->with('menuItem')
            ->get();

        $updated = 0;

        foreach ($items as $item) {
            if (!$item->menuItem) {
                continue;
            }

            $cost = $this->costing->costFor($item->menuItem);

            if ($cost <= 0) {
                // A genuinely free recipe, or an item with nothing to cost
                // from. Writing 0 would be indistinguishable from "never
                // recorded", so leave it for the read-time fallback.
                continue;
            }

            $item->ingredient_cost = $cost;
            $item->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * Remove every demo row, whatever its age. Used by DemoSalesSeeder::purge().
     */
    public function purgeAll(): int
    {
        $ids = Order::where('notes', self::MARKER)->pluck('id');

        return $ids->isEmpty() ? 0 : $this->deleteOrders($ids->all());
    }

    /**
     * @param  int[]  $ids
     */
    private function deleteOrders(array $ids): int
    {
        DB::transaction(function () use ($ids) {
            $itemIds = DB::table('order_items')->whereIn('order_id', $ids)->pluck('id');

            if ($itemIds->isNotEmpty()) {
                DB::table('order_item_options')->whereIn('order_item_id', $itemIds)->delete();
            }

            DB::table('order_items')->whereIn('order_id', $ids)->delete();
            DB::table('orders')->whereIn('id', $ids)->delete();
        });

        return count($ids);
    }
}
