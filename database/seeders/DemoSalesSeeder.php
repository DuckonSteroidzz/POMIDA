<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Services\DemoSalesTopUp;
use Illuminate\Database\Seeder;

/**
 * Historical COMPLETED sales, purely for demonstration.
 *
 * WHY THIS EXISTS
 * ---------------
 * AnalyticsService::salesForecast() refuses to draw anything until at least
 * FORECAST_MIN_DAYS_WITH_SALES of the last FORECAST_LOOKBACK_DAYS days have
 * completed sales. The real order history only had three such days, so the
 * Admin Analytics forecast chart and its low-sales alert never rendered.
 *
 * THESE ORDERS ARE FABRICATED.
 *
 * WHAT CHANGED
 * ------------
 * This seeder is now only the MANUAL entry point. All the generation logic —
 * and the conventions that go with it (the notes marker, walk-in/no-user
 * attribution, no inventory movement, derived money) — lives in
 * App\Services\DemoSalesTopUp, which also tops the data up automatically when
 * Admin → Analytics is loaded and coverage has gone thin.
 *
 * That matters: seeding once at fixed calendar dates was always going to rot,
 * because the forecast window rolls forward and those dates do not. Running
 * this seeder by hand is no longer required for the chart to keep working; it
 * remains useful for laying down a full, reproducible history in one go.
 *
 *     php artisan db:seed --class=DemoSalesSeeder     (insert)
 *     DemoSalesSeeder::purge()                         (remove every demo row)
 *
 * or by hand:
 *     DELETE FROM order_items WHERE order_id IN
 *         (SELECT id FROM orders WHERE notes = 'SEEDED_DEMO_SALES');
 *     DELETE FROM orders WHERE notes = 'SEEDED_DEMO_SALES';
 */
class DemoSalesSeeder extends Seeder
{
    /**
     * Kept for backwards compatibility with anything already referencing
     * DemoSalesSeeder::MARKER. The canonical definition now lives on the
     * service, so there is still only one string in play.
     */
    public const MARKER = DemoSalesTopUp::MARKER;

    /** Seeded days, counted back from today. Today is left out on purpose:
     *  today's takings should reflect whatever actually happens during a demo. */
    private const FIRST_DAY_BACK = 1;
    private const LAST_DAY_BACK  = 17;

    public function run(): void
    {
        if (Order::where('notes', self::MARKER)->exists()) {
            $this->command?->warn('Demo sales already present — nothing to do. Run DemoSalesSeeder::purge() first to reseed.');
            return;
        }

        $days = range(self::FIRST_DAY_BACK, self::LAST_DAY_BACK);

        // Fixed seed: reseeding produces the same figures, so a demo shown twice
        // tells the same story and any screenshot stays reproducible. Only the
        // artisan path seeds the RNG — the automatic top-up deliberately does
        // not, because mt_srand() reaches beyond the caller inside a request.
        $created = app(DemoSalesTopUp::class)->generateDays($days, 20260823);

        if ($created === 0) {
            $this->command?->error('No demo orders created — check that branch 1 has at least 3 available menu items.');
            return;
        }

        $revenue = Order::where('notes', self::MARKER)->sum('total');

        $this->command?->info(sprintf(
            'Seeded %d demo orders (P%s) across days %d-%d back. All marked notes = "%s". No inventory was deducted.',
            $created,
            number_format((float) $revenue, 2),
            self::FIRST_DAY_BACK,
            self::LAST_DAY_BACK,
            self::MARKER
        ));
    }

    /**
     * Remove every row this seeder (or the automatic top-up) created, and
     * nothing else.
     */
    public static function purge(): int
    {
        return app(DemoSalesTopUp::class)->purgeAll();
    }
}
