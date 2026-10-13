<?php

/*
|--------------------------------------------------------------------------
| Registered-customer loyalty bonuses (Oct 2026)
|--------------------------------------------------------------------------
|
| Every number a customer is promised for having an account lives here, and
| ONLY here. The award engine (App\Services\Loyalty), the Account page's
| "Account benefits" card and the guest sign-up teasers all read these values,
| so the page can never advertise a figure the server does not pay.
|
| The server caches configuration (`php artisan optimize`, step 4 of the
| update routine in docs/DEPLOYMENT.md). After editing this file there, run
| `php artisan config:clear` and `php artisan optimize` again, or the old
| values stay in force.
|
| WHAT THE POINTS ARE WORTH
| -------------------------
| Bonus points land in users.bonus_points, a balance that is NOT reset when an
| order is placed (users.points, the Spin & Win balance, is). The wheel adds
| the two together when it checks whether a spin has reached a wheel voucher,
| so bonus points do the one thing points can do in this app: bring the next
| wheel voucher closer. See AuthController::addPoints().
|
| For scale, the wheel (AuthController::WHEEL_SEGMENTS) pays about 3.6 points
| per spin on average, so a 7-spin order is worth about 25 points, and the
| wheel vouchers on the live database cost 20, 25 and 35 points. The values
| below were proposed on that scale:
|
|   order bonus   3  — roughly one extra spin per completed order
|   welcome      10  — about a third of a typical wheel voucher
|   3-day streak 10  — the same again for coming back three days running
|   7-day streak  0  — the owner chose a free dessert instead (Oct 2026);
|                      see free_dessert_visit_days below
|
| Set any value to 0 to switch that bonus off. Nothing is awarded for a 0.
|
*/

return [

    /*
     * The shop's time zone, used for every "which day was this?" question the
     * streak calendar asks, is config app.shop_timezone (default Asia/Manila),
     * read through App\Support\ShopDay — the same value the spin and voucher
     * day rules use, so the two can never disagree about when a day starts.
     */

    /* One time, when the customer verifies their email address. */
    'welcome_bonus_points' => 10,

    /* Every completed order placed from a verified customer account. Guests do not get it. */
    'order_bonus_points' => 3,

    /*
     * Consecutive days with at least one COMPLETED order => bonus points.
     * Paid once per milestone per streak: a streak that reaches 3 days pays
     * the 3-day bonus once, however many more orders are completed during it;
     * a new streak, after a missed day, can earn it again.
     */
    'streak_milestones' => [
        3 => 10,
        7 => 0,
    ],

    /*
     * STAMP CARD REWARD (Oct 2026) — a stamp card, NOT a streak. Every shop
     * day (Manila) on which a verified customer had at least one COMPLETED
     * order is one stamp; several orders on the same day are still one stamp,
     * and the days do not have to be consecutive. Every this-many stamps
     * earns ONE reward: 7 stamps = the 1st, 14 = the 2nd, and so on. Paid by
     * App\Services\Loyalty::orderCompleted, the same path and the same ledger
     * as the streak bonuses above. 0 = off. (The key keeps its first name,
     * from when the reward was always a free dessert.)
     *
     * Since the Streak Rewards pass this is only the FALLBACK: the owner sets
     * the days, the reward (one menu item, or a voucher's discount) and the
     * validity on the admin Vouchers & Game page, and those saved values win
     * (App\Services\StreakRewardSettings). No reward is given until the owner
     * chooses one there.
     */
    'free_dessert_visit_days' => 7,

    /*
     * The first shop day (Y-m-d, Manila) that earns a stamp. Orders completed
     * before it never count, so switching the stamp card on cannot hand an
     * old regular a surprise batch of desserts for past history. Moving it
     * later never takes back a dessert already earned. null counts every
     * completed order ever (the catch-up would then grant past desserts on
     * each customer's next completed order).
     */
    'free_dessert_counts_from' => '2026-10-08',

    /*
     * How many days a reward can be used for, counted in shop days: it is
     * good through the end of (day earned + this many days), Manila time.
     * 0 = it never expires. The FALLBACK for the owner's "Valid for (days)"
     * Streak Rewards setting, which wins once saved.
     */
    'free_dessert_valid_days' => 30,

];
