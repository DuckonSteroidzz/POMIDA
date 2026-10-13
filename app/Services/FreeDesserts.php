<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\FreeDessertReward;
use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\RewardCode;
use App\Support\ShopDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The stamp card and its rewards (Oct 2026) — every rule in one place.
 *
 * (The class keeps its first name, from when the only reward was a free
 * dessert. The reward — one menu item, or a voucher's discount — is chosen
 * per branch in App\Services\StreakRewardSettings.)
 *
 * PER BRANCH. Every branch has its own stamp card: its own stamps, its own X,
 * its own reward and its own on/off. Nothing is shared between branches, so a
 * customer who orders at two branches fills two cards.
 *
 * THE RULE. One stamp per shop day (Manila) with at least one COMPLETED order
 * AT THE BRANCH (orders.branch_id), counted from
 * config('loyalty.free_dessert_counts_from'). The days do NOT have to be
 * consecutive, and several completed orders on one day are still one stamp.
 * With X = StreakRewardSettings::visitDays($branchId), D stamps at that branch
 * have earned floor(D / X) rewards there in total. Placed, pending, cancelled
 * and rejected orders never stamp, and a completed order is final (staff
 * cancel refuses it), so D only grows.
 *
 * EARNING. Loyalty::orderCompleted() — run after staff complete an order —
 * calls grantDue() for THAT ORDER'S BRANCH, which grants every reward number
 * 1..floor(D / X) of that branch not yet in the ledger. Reward number n at
 * branch B is ledger key "visits:<B>:<n>", written by grant() with the reward
 * in ONE transaction, ledger first: UNIQUE(user_id, type, award_key) is the
 * whole guard, so a retry or two completions at the same instant create
 * exactly one reward per number, and several due at once (a lowered X, an
 * account that qualified late, a reward chosen after customers had already
 * filled their cards) are all granted by the next completion at that branch.
 * The key carries the branch, so two branches' rewards never collide, and no
 * X, so changing X never pays a number twice. Registered, verified customers
 * only — the caller has already applied Loyalty::qualifies().
 *
 * (A legacy "visits:<n>" key, from before stamps were per branch, is renamed
 * by migration 2026_10_13_000000 to the branch of the order that earned it,
 * so a reward already paid is never paid again.)
 *
 * WHAT A REWARD IS. grant() copies the branch's current choice into the row —
 * type, the item or the discount terms, and branch_id = the ISSUING branch —
 * plus its own expiry and its own random, unique code (App\Support\RewardCode).
 * That snapshot is the reward: changing the settings afterwards never changes
 * a reward already earned. Nothing is granted while the branch has no reward.
 *
 * SPENDING. Only by the signed-in owner, with the code, and only at the branch
 * that issued it (FreeDessertReward::branchErrorFor — item and voucher
 * rewards alike; a NULL branch_id, from before per-branch rewards, is any
 * branch): typed in the cart's voucher box (or filled in by "Use now"),
 * checked by findForCustomer(). OrderController::placeOrder(), inside the
 * order transaction: lockForSpend() takes the reward row FOR UPDATE and
 * re-checks it, the order is written (a menu-item reward as one ₱0 line),
 * then markUsed(). An item reward never enters the subtotal, the voucher or
 * the PWD/Senior figures; a voucher reward is priced like a voucher and never
 * stacks with one or with PWD/Senior (the bigger discount wins).
 *
 * GIVING IT BACK. An order cancelled by the customer or staff, or cancelled by
 * a GCash rejection, before completion hands its reward back with the same
 * code (releaseForOrder, idempotent). A completed order keeps it used.
 *
 * ON / OFF. The owner's master switch (Setting::streakCalendarEnabled) and the
 * branch's own switch (StreakRewardSettings::branchEnabled) must both be on
 * for a branch to earn or show its card. Neither ever stops a reward already
 * earned from being used or checked.
 *
 * The 3-day streak POINTS bonus (App\Services\Loyalty) is not part of this
 * and stays company-wide.
 */
class FreeDesserts
{
    /** Above this many stamps per reward the cards show only the progress bar. */
    public const MAX_STAMPS_SHOWN = 12;

    /** X at $branchId (null = the company default): stamps per reward. 0 = off. */
    public static function visitDays(?int $branchId = null): int
    {
        return StreakRewardSettings::visitDays($branchId);
    }

    /**
     * The first shop day (Y-m-d) that earns a stamp, or null for no lower
     * bound (anything that is not a real Y-m-d date counts as unset).
     */
    public static function countsFrom(): ?string
    {
        $from = config('loyalty.free_dessert_counts_from');

        if (! is_string($from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $from));

        return checkdate($m, $d, $y) ? $from : null;
    }

    /** Days a NEW reward earned at $branchId stays valid (0 = never expires). */
    public static function validDays(?int $branchId = null): int
    {
        return StreakRewardSettings::validDays($branchId);
    }

    /** The stamp card is set up at all at $branchId (X > 0). */
    public static function enabled(?int $branchId = null): bool
    {
        return self::visitDays($branchId) > 0;
    }

    /** The owner's master switch: OFF turns every branch off. */
    public static function masterOn(): bool
    {
        return \App\Models\Setting::streakCalendarEnabled();
    }

    /**
     * Both switches on for $branchId: the owner's master switch AND the
     * branch's own. The branch's Streak Calendar shows only while this is true.
     */
    public static function switchedOn(int $branchId): bool
    {
        return self::masterOn() && StreakRewardSettings::branchEnabled($branchId);
    }

    /**
     * Set up AND switched on at $branchId. EARNING and SHOWING the stamp card
     * read this one: grantDue(), the stamp card, the progress bar, the
     * benefits line and the Help topic for that branch.
     *
     * Switching off never deletes anything, and it never stops a reward
     * already earned from being used or checked: spending and the admin code
     * check do not read this. Stamps are computed from completed orders, so
     * the days while it was off still count, and the next completion at that
     * branch after it is switched back on grants every reward that became due
     * meanwhile (grantDue()'s normal catch-up).
     */
    public static function active(int $branchId): bool
    {
        return self::enabled($branchId) && self::switchedOn($branchId);
    }

    /**
     * The branch the customer is ordering from: the pickup branch they picked
     * or their Dine-In table's branch (both live in session('branch_id')).
     * Null when none is picked yet or it no longer exists.
     */
    public static function sessionBranch(): ?Branch
    {
        $id = session('branch_id');

        return is_numeric($id) && (int) $id > 0 ? Branch::find((int) $id) : null;
    }

    // ══════════ stamps ══════════

    /**
     * The shop days that carry a stamp at $branchId: StreakCalendar's
     * completed-order days at that branch (the same red days its calendar
     * shows), from countsFrom() on.
     *
     * @return array<int, string> 'Y-m-d', ascending
     */
    public static function stampDays(User $user, int $branchId): array
    {
        $from = self::countsFrom();
        $days = array_keys(StreakCalendar::completedDays($user, $branchId));

        return $from === null
            ? $days
            : array_values(array_filter($days, fn (string $day) => $day >= $from));
    }

    /** D: how many stamps this customer has collected at $branchId in total. */
    public static function countedDays(User $user, int $branchId): int
    {
        return count(self::stampDays($user, $branchId));
    }

    // ══════════ earning ══════════

    /** The ledger key of the customer's $number-th reward at $branchId. */
    public static function awardKey(int $branchId, int $number): string
    {
        return 'visits:' . $branchId . ':' . $number;
    }

    /** The ledger rows of this customer's rewards at $branchId. */
    private static function heldAt(User $user, int $branchId)
    {
        return LoyaltyAward::where('user_id', $user->id)
            ->where('type', LoyaltyAward::TYPE_FREE_DESSERT)
            ->where('award_key', 'like', 'visits:' . $branchId . ':%');
    }

    /**
     * Grant every reward the customer's stamps at $branchId have earned and
     * the ledger does not hold yet — normally none or one, several when they
     * become due at once. Returns the rewards created by THIS call. Nothing
     * while the branch has no reward or is switched off (the numbers stay
     * unpaid, so they are granted at the first completion there after that
     * changes).
     *
     * @return array<int, FreeDessertReward>
     */
    public static function grantDue(User $user, int $branchId, ?int $orderId = null): array
    {
        $perReward = self::visitDays($branchId);

        if ($perReward <= 0 || ! self::active($branchId)) {
            return [];
        }

        $due = intdiv(self::countedDays($user, $branchId), $perReward);

        if ($due <= 0) {
            return [];
        }

        // One snapshot for everything granted by this call.
        $snapshot = StreakRewardSettings::snapshotForNewReward($branchId);

        if ($snapshot === null) {
            return [];
        }

        // Only a shortcut past numbers already paid. It is not the guard: a
        // stale read here costs one refused insert in grant(), never a second
        // reward.
        $held = self::heldAt($user, $branchId)->pluck('award_key')->flip();

        $granted = [];

        for ($number = 1; $number <= $due; $number++) {
            if (! isset($held[self::awardKey($branchId, $number)]) && ($reward = self::grant($user, $branchId, $number, $orderId, $snapshot))) {
                $granted[] = $reward;
            }
        }

        return $granted;
    }

    /**
     * Create the customer's $number-th reward at $branchId, as $snapshot
     * describes it (that branch's current settings when omitted). Returns the
     * new reward, or null when that number was already granted, the branch
     * has no reward, or it is off (in its settings or by either switch).
     */
    public static function grant(User $user, int $branchId, int $number, ?int $orderId = null, ?array $snapshot = null): ?FreeDessertReward
    {
        if ($number < 1 || ! self::active($branchId)) {
            return null;
        }

        $snapshot ??= StreakRewardSettings::snapshotForNewReward($branchId);

        if ($snapshot === null) {
            return null;
        }

        // The issuing branch is this branch, whatever the snapshot says.
        $snapshot['branch_id'] = $branchId;
        $expiresAt = self::expiresAtFor(now(), self::validDays($branchId));

        try {
            return DB::transaction(function () use ($user, $branchId, $number, $orderId, $snapshot, $expiresAt) {
                // Ledger first: a duplicate key throws here, before any reward exists.
                $award = LoyaltyAward::create([
                    'user_id'   => $user->id,
                    'type'      => LoyaltyAward::TYPE_FREE_DESSERT,
                    'award_key' => self::awardKey($branchId, $number),
                    'points'    => 0,
                    'order_id'  => $orderId,
                ]);

                return FreeDessertReward::create(array_merge($snapshot, [
                    'user_id'          => $user->id,
                    'loyalty_award_id' => $award->id,
                    'code'             => self::uniqueCode(),
                    'status'           => FreeDessertReward::STATUS_AVAILABLE,
                    'expires_at'       => $expiresAt,
                ]));
            });
        } catch (QueryException $e) {
            // A duplicate ledger key: this number was already paid. (A
            // duplicate CODE would land here too and roll the ledger row back
            // with it, so the number is simply granted at the next
            // completion — uniqueCode() makes that a once-in-never event.)
            if (($e->errorInfo[1] ?? null) === 1062) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * A reward code no reward holds and no order has ever recorded. The
     * UNIQUE index on free_dessert_rewards.code is the real guarantee; this
     * loop keeps an astronomically unlikely collision from becoming an error,
     * and the orders check keeps a code from coming back even if its reward
     * row were ever deleted.
     */
    public static function uniqueCode(?callable $generate = null): string
    {
        // $generate exists for tests, to offer a code that is already taken.
        $generate ??= [RewardCode::class, 'generate'];

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = $generate();

            if (! FreeDessertReward::where('code', $code)->exists()
                && ! Order::where('reward_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique reward code.');
    }

    /**
     * Good through the END of the shop day $validDays after the day it was
     * earned, stored as a precise instant in the app's zone. Never a bare
     * midnight, so ShopDay's "midnight = date-only" voucher rule can never
     * apply to it. Null (never expires) when the validity is 0.
     * $validDays defaults to the company default's validity.
     */
    public static function expiresAtFor(CarbonInterface $earnedAt, ?int $validDays = null): ?Carbon
    {
        $valid = $validDays ?? self::validDays();

        if ($valid <= 0) {
            return null;
        }

        $end = CarbonImmutable::instance($earnedAt)
            ->setTimezone(ShopDay::timezone())
            ->startOfDay()
            ->addDays($valid)
            ->endOfDay()
            ->setTimezone(ShopDay::appTimezone());

        return Carbon::instance($end)->startOfSecond();
    }

    // ══════════ reading ══════════

    /** Mark this customer's rewards whose expiry has passed as expired. */
    public static function expireStale(User $user): int
    {
        return FreeDessertReward::where('user_id', $user->id)
            ->where('status', FreeDessertReward::STATUS_AVAILABLE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => FreeDessertReward::STATUS_EXPIRED, 'updated_at' => now()]);
    }

    /** The customer's soonest-expiring usable reward (the pop-up's "use by" date). */
    public static function usableFor(?User $user): ?FreeDessertReward
    {
        if (! $user || ! Loyalty::qualifies($user)) {
            return null;
        }

        return self::usableQuery($user)->first();
    }

    private static function usableQuery(User $user)
    {
        return FreeDessertReward::usable()
            ->where('user_id', $user->id)
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id');
    }

    /**
     * The reward a typed code names — only when it is THIS signed-in,
     * qualifying customer's own. Any status: the caller asks usabilityError()
     * so the owner can be told "already used" or "expired". A guest, a
     * stranger's code and a code that does not exist all return null, which
     * every caller turns into the same "Invalid voucher code." — nothing
     * tells a guesser that a code exists.
     */
    public static function findForCustomer(?User $user, ?string $code): ?FreeDessertReward
    {
        if (! $user || ! Loyalty::qualifies($user) || ! RewardCode::looksLike($code)) {
            return null;
        }

        return FreeDessertReward::where('code', RewardCode::normalise($code))
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * The order line a menu-item reward would give at $branchId right now, as
     * ['menu_item' => MenuItem, 'size' => ?MenuItemSize, 'name' => string],
     * or null when it cannot be given there: not a menu-item reward, the item
     * is archived, switched off, another branch's, has no recipe, or is out
     * of stock.
     *
     * $reserved is InventoryDeductionService::committedQuantities() — stock
     * that open orders already hold. The checkout's locked stock gate is still
     * what decides; this is what the cart preview and the early gate ask.
     */
    public static function itemLineFor(FreeDessertReward $reward, int $branchId, ?array $reserved = null): ?array
    {
        if (! $reward->isItemReward() || ! $reward->menu_item_id) {
            return null;
        }

        // The customer menu's own branch rule. Archived items are excluded by
        // MenuItem's global scope.
        $item = MenuItem::with([
                'recipeIngredients.inventory',
                'inventoryItem',
                'allSizes.ingredients.inventory',
            ])
            ->whereKey($reward->menu_item_id)
            ->where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->first();

        return $item ? self::lineFor($item, $reserved) : null;
    }

    /**
     * A sized item is given in its FIRST live size that can be sold now
     * (display_order, so Regular before Large); an unsized one needs a recipe
     * and stock for one serving.
     */
    private static function lineFor(MenuItem $item, ?array $reserved): ?array
    {
        if ($item->hasSizes()) {
            foreach ($item->sizeChoices($reserved) as $choice) {
                if ($choice['orderable']) {
                    return [
                        'menu_item' => $item,
                        'size'      => $choice['size'],
                        'name'      => $item->name . ' (' . $choice['name'] . ')',
                    ];
                }
            }

            return null;
        }

        if ($item->isMissingRecipe() || ! $item->hasIngredientStock(1, $reserved)) {
            return null;
        }

        return ['menu_item' => $item, 'size' => null, 'name' => $item->name];
    }

    // ══════════ spending ══════════

    /**
     * Inside the order transaction: lock this reward and re-check it under
     * the lock. Null when it is not this customer's, is no longer usable —
     * another checkout spent it a moment ago, or it just expired — or cannot
     * be used at the order's branch.
     */
    public static function lockForSpend(User $user, int $rewardId, int $branchId): ?FreeDessertReward
    {
        $reward = FreeDessertReward::whereKey($rewardId)->lockForUpdate()->first();

        if ($reward
            && (int) $reward->user_id === (int) $user->id
            && $reward->isUsable()
            && $reward->branchErrorFor($branchId) === null) {
            return $reward;
        }

        return null;
    }

    /** $line is the ₱0 order line of a menu-item reward; null for a voucher reward. */
    public static function markUsed(FreeDessertReward $reward, Order $order, ?OrderItem $line = null): void
    {
        $reward->status = FreeDessertReward::STATUS_USED;
        $reward->order_id = $order->id;
        $reward->order_item_id = $line?->id;
        $reward->used_branch_id = $order->branch_id;
        $reward->used_at = now();
        $reward->save();
    }

    /**
     * Hand back the reward spent on $order once it has been CANCELLED (by the
     * customer, by staff, or by a GCash rejection). Back to available — same
     * code — or to expired if its date passed meanwhile. Idempotent: a second
     * call finds no used reward on the order. A completed order is never
     * released.
     */
    public static function releaseForOrder(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'cancelled') {
                return false;
            }

            $reward = FreeDessertReward::where('order_id', $order->id)
                ->where('status', FreeDessertReward::STATUS_USED)
                ->lockForUpdate()
                ->first();

            if (! $reward) {
                return false;
            }

            $reward->status = $reward->isExpired()
                ? FreeDessertReward::STATUS_EXPIRED
                : FreeDessertReward::STATUS_AVAILABLE;
            $reward->order_id = null;
            $reward->order_item_id = null;
            $reward->used_branch_id = null;
            $reward->used_at = null;
            $reward->save();

            return true;
        });
    }

    /**
     * releaseForOrder() for the three cancel paths: never allowed to undo or
     * break a cancellation that has already been saved. A failure is logged;
     * the release can be repeated safely.
     */
    public static function releaseAfterCancel(Order $order): void
    {
        try {
            self::releaseForOrder($order);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Loyalty reward could not be returned after cancellation', [
                'order_id'  => $order->id,
                'exception' => $e,
            ]);
        }
    }

    // ══════════ Account page + Vouchers & Rewards ══════════

    /**
     * Every reward of this customer, FROM EVERY BRANCH, for the Vouchers &
     * Rewards page (each is labeled with its branch there), in the order a
     * customer wants them: ready to use (soonest expiry first), then on an
     * order that is still open, then the rest (newest first, at most
     * $historyLimit of those). Shown whatever the switches say — a reward
     * already earned can always be used — except that with the owner's
     * master switch off only the usable ones are listed.
     *
     * @return Collection<int, FreeDessertReward>
     */
    public static function rewardsFor(User $user, int $historyLimit = 10): Collection
    {
        self::expireStale($user);

        $usable = self::usableQuery($user)->with('branch')->get();

        // A reward earned before rewards had snapshots (reward_type NULL) is
        // "available" but not usable yet; it is listed after the usable ones.
        $notSet = FreeDessertReward::with('branch')
            ->where('user_id', $user->id)
            ->where('status', FreeDessertReward::STATUS_AVAILABLE)
            ->whereNull('reward_type')
            ->orderBy('id')
            ->get();

        if (! self::masterOn()) {
            return $usable->concat($notSet)->values();
        }

        $rest = FreeDessertReward::with(['order', 'branch'])
            ->where('user_id', $user->id)
            ->whereIn('status', [FreeDessertReward::STATUS_USED, FreeDessertReward::STATUS_EXPIRED])
            ->orderByDesc('id')
            ->limit($historyLimit)
            ->get();

        [$onOpenOrder, $finished] = $rest->partition(fn (FreeDessertReward $r) => $r->statusKey() === FreeDessertReward::KEY_ON_ORDER);

        return $usable->concat($notSet)->concat($onOpenOrder)->concat($finished)->values();
    }

    /**
     * What the stamp card (customer.partials.free-dessert-stamp-card, on the
     * Account page and Vouchers & Rewards) and the Account page's progress
     * bar show for ONE branch — the one the customer is ordering from.
     * Read-only apart from expiring stale rewards.
     *
     * counted is D at that branch; progress is D mod X: the stamps on the
     * CURRENT card. A full card is handed in for a reward, exactly like a
     * paper one, so 7 of 7 shows as a ready reward plus a new card at 0.
     *
     * 'waiting' counts full cards at that branch that earned nothing yet
     * because it has no reward: they are granted at the next completion there
     * after one is chosen. 'available' is the usable rewards that can be
     * spent at that branch.
     *
     * ['enabled' => false] when no branch is known, or the branch is off
     * (either switch, or X = 0): the card and the bar are hidden; rewards
     * already earned stay listed and usable on Vouchers & Rewards.
     */
    public static function summaryFor(User $user, ?int $branchId): array
    {
        if ($branchId === null || ! self::active($branchId)) {
            return ['enabled' => false];
        }

        self::expireStale($user);

        $settings = StreakRewardSettings::current($branchId);
        $perReward = $settings['days'];
        $counted = self::countedDays($user, $branchId);

        $waiting = 0;

        if (! $settings['configured']) {
            $waiting = max(0, intdiv($counted, $perReward) - self::heldAt($user, $branchId)->count());
        }

        return [
            'enabled'     => true,
            'branch_id'   => $branchId,
            'branch_name' => Branch::whereKey($branchId)->value('name') ?? 'this branch',
            'qualifies'   => Loyalty::qualifies($user),
            'visit_days'  => $perReward,
            'valid_days'  => $settings['valid_days'],
            'counted'     => $counted,
            'progress'    => $counted % $perReward,
            'show_stamps' => $perReward <= self::MAX_STAMPS_SHOWN,
            'reward_text' => $settings['description'],
            'waiting'     => $waiting,
            'available'   => self::usableQuery($user)
                ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
                ->get(),
        ];
    }
}
