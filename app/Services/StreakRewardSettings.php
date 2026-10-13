<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\FreeDessertReward;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\Voucher;
use App\Support\ShopDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Streak Rewards settings — what a full stamp card earns, PER BRANCH
 * (Oct 2026), edited on the admin Vouchers & Game page.
 *
 * WHERE THEY LIVE
 * ---------------
 * Rows in the `settings` table, which was built for exactly this: branch_id
 * NULL is a global row, branch_id N belongs to branch N, UNIQUE(branch_id,
 * key) keeps one value per key per branch, and deleting a branch deletes its
 * rows (ON DELETE CASCADE). No new table and no schema change.
 *
 *   streak_reward_days          X: stamps (days with a completed order AT THE
 *                               BRANCH) per reward
 *   streak_reward_valid_days    how many days a reward stays usable after it is earned
 *   streak_reward_type          'voucher' or 'menu_item'
 *   streak_reward_menu_item_id  the one menu item given free (menu_item type)
 *   streak_reward_voucher       JSON: the terms copied from the template voucher
 *                               when it was saved (voucher type)
 *   streak_reward_enabled       BRANCH ROWS ONLY: that branch's own on/off.
 *                               A missing row is ON; only exactly '0' is off.
 *
 * The five reward keys are saved together, as a SET. The global set
 * (branch_id NULL) is the COMPANY DEFAULT.
 *
 * FALLBACK (whole set, never key by key)
 * --------------------------------------
 *   1. the branch has any reward row of its own -> its own set;
 *   2. otherwise the company default set;
 *   3. days and validity missing from the chosen set -> config/loyalty.php
 *      (free_dessert_visit_days = 7, free_dessert_valid_days = 30).
 * No reward type in the chosen set means NOTHING is granted at that branch:
 * stamps keep counting there and grantDue()'s catch-up pays every reward that
 * became due at that branch's next completed order once one is chosen. A
 * branch's set is never mixed with the default's, so a branch can never end
 * up with a combination nobody picked. Deploying changes nothing: with no
 * branch rows every branch reads the global rows exactly as before.
 *
 * WHAT A BRANCH MAY GIVE
 * ----------------------
 * A branch's reward must be something valid AT that branch: a voucher for
 * every branch or for that branch, a menu item for every branch or for that
 * branch. The company default is used by any branch, so it must be valid at
 * every branch (a company-wide voucher, an all-branches item). Saving refuses
 * anything else (StreakRewardController); current() re-checks it when read,
 * so a default saved before this rule, or an item later moved to another
 * branch, reads as "not set here" — nothing is granted — and the admin page
 * says why.
 *
 * ON / OFF
 * --------
 * The owner's master switch (Setting::streakCalendarEnabled(), global, owner
 * only) and each branch's own switch (branchEnabled(), the owner or that
 * branch's supervisor). See FreeDesserts::switchedOn()/active().
 *
 * SNAPSHOTS
 * ---------
 * The settings only decide what a NEW reward is. FreeDesserts::grant() copies
 * snapshotForNewReward($branchId) into the reward row (type, the item or the
 * discount terms, and the issuing branch) with its own expiry, so changing
 * anything here never changes a reward already earned. A voucher reward's
 * terms are copied from the template voucher at SAVE time, so editing or
 * deleting that voucher afterwards changes nothing until these settings are
 * saved again, and a reward never counts against the template's own uses.
 */
class StreakRewardSettings
{
    public const DAYS = 'streak_reward_days';
    public const VALID_DAYS = 'streak_reward_valid_days';
    public const TYPE = 'streak_reward_type';
    public const MENU_ITEM_ID = 'streak_reward_menu_item_id';
    public const VOUCHER = 'streak_reward_voucher';

    /** A branch's own on/off (branch rows only). */
    public const BRANCH_ENABLED = 'streak_reward_enabled';

    public const TYPE_VOUCHER = 'voucher';
    public const TYPE_MENU_ITEM = 'menu_item';

    public const TYPES = [self::TYPE_VOUCHER, self::TYPE_MENU_ITEM];

    /** Upper bounds for the two numbers on the form. Generous; they stop typos like 7000. */
    public const MAX_DAYS = 365;
    public const MAX_VALID_DAYS = 365;

    /** The keys of one reward set (saved together, read together). */
    public const KEYS = [self::DAYS, self::VALID_DAYS, self::TYPE, self::MENU_ITEM_ID, self::VOUCHER];

    /** current()['source']: the branch's own set, or the company default. */
    public const SOURCE_BRANCH = 'branch';
    public const SOURCE_DEFAULT = 'default';

    /** current()['problem']: why no reward can be granted (null = one can). */
    public const PROBLEM_NOT_CHOSEN = 'not_chosen';
    public const PROBLEM_ITEM_GONE = 'item_gone';
    public const PROBLEM_ITEM_OTHER_BRANCH = 'item_other_branch';
    public const PROBLEM_VOUCHER_OTHER_BRANCH = 'voucher_other_branch';

    /**
     * The reward rows that decide $branchId (null = the company default
     * itself), and where they came from.
     *
     * @return array{0: array<string, ?string>, 1: string}
     */
    private static function resolveRows(?int $branchId): array
    {
        $query = Setting::query()->whereIn('key', self::KEYS);

        if ($branchId === null) {
            return [$query->whereNull('branch_id')->pluck('value', 'key')->all(), self::SOURCE_DEFAULT];
        }

        $rows = $query
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->get(['branch_id', 'key', 'value']);

        $own = $rows->filter(fn ($row) => $row->branch_id !== null && (int) $row->branch_id === $branchId);

        if ($own->isNotEmpty()) {
            return [$own->pluck('value', 'key')->all(), self::SOURCE_BRANCH];
        }

        return [$rows->whereNull('branch_id')->pluck('value', 'key')->all(), self::SOURCE_DEFAULT];
    }

    private static function positiveInt($value): ?int
    {
        return is_string($value) && ctype_digit($value) && (int) $value >= 1 ? (int) $value : null;
    }

    /** X at $branchId (null = the company default). The saved value, else config (0 there = off). */
    public static function visitDays(?int $branchId = null): int
    {
        [$rows] = self::resolveRows($branchId);

        return self::daysFrom($rows);
    }

    /** Days a reward earned at $branchId stays valid. The saved value, else config (0 = never expires). */
    public static function validDays(?int $branchId = null): int
    {
        [$rows] = self::resolveRows($branchId);

        return self::validDaysFrom($rows);
    }

    private static function daysFrom(array $rows): int
    {
        return self::positiveInt($rows[self::DAYS] ?? null)
            ?? max(0, (int) config('loyalty.free_dessert_visit_days', 0));
    }

    private static function validDaysFrom(array $rows): int
    {
        return self::positiveInt($rows[self::VALID_DAYS] ?? null)
            ?? max(0, (int) config('loyalty.free_dessert_valid_days', 0));
    }

    /**
     * Something owned by $ownerBranchId (null = every branch) may be the
     * reward of $branchId (null = the company default, used by EVERY branch,
     * so only an every-branch thing fits it). The NULL owner is tested on its
     * own first, so a null can never be cast into a match.
     */
    public static function fits(?int $ownerBranchId, ?int $branchId): bool
    {
        if ($ownerBranchId === null) {
            return true;
        }

        return $branchId !== null && $ownerBranchId === $branchId;
    }

    /**
     * Everything the admin card shows and grant() needs for $branchId
     * (null = the company default itself).
     *
     * 'configured' is false — nothing is granted — when no type was chosen,
     * the chosen item was archived or deleted, or the item or voucher terms
     * do not fit this branch ('problem' says which).
     */
    public static function current(?int $branchId = null): array
    {
        [$rows, $source] = self::resolveRows($branchId);

        $type = in_array($rows[self::TYPE] ?? null, self::TYPES, true) ? $rows[self::TYPE] : null;

        $menuItemId = self::positiveInt($rows[self::MENU_ITEM_ID] ?? null);
        // Archived items are hidden by MenuItem's global scope, so an archived
        // reward item reads as gone and nothing is granted until it is fixed.
        $menuItem = $type === self::TYPE_MENU_ITEM && $menuItemId ? MenuItem::with('branch')->find($menuItemId) : null;

        $voucher = $type === self::TYPE_VOUCHER ? self::decodeVoucher($rows[self::VOUCHER] ?? null) : null;

        $problem = match (true) {
            $type === self::TYPE_MENU_ITEM && $menuItem === null => self::PROBLEM_ITEM_GONE,
            $type === self::TYPE_MENU_ITEM && ! self::fits($menuItem->branch_id !== null ? (int) $menuItem->branch_id : null, $branchId) => self::PROBLEM_ITEM_OTHER_BRANCH,
            $type === self::TYPE_MENU_ITEM => null,
            $type === self::TYPE_VOUCHER && $voucher !== null && ! self::fits($voucher['branch_id'], $branchId) => self::PROBLEM_VOUCHER_OTHER_BRANCH,
            $type === self::TYPE_VOUCHER && $voucher !== null => null,
            default => self::PROBLEM_NOT_CHOSEN,
        };

        $configured = $problem === null;

        return [
            'branch_id'        => $branchId,
            'source'           => $source,
            'own'              => $branchId !== null && $source === self::SOURCE_BRANCH,
            'days'             => self::daysFrom($rows),
            'days_saved'       => self::positiveInt($rows[self::DAYS] ?? null) !== null,
            'valid_days'       => self::validDaysFrom($rows),
            'valid_days_saved' => self::positiveInt($rows[self::VALID_DAYS] ?? null) !== null,
            'type'             => $type,
            'menu_item_id'     => $menuItemId,
            'menu_item'        => $menuItem,
            'voucher'          => $voucher,
            'configured'       => $configured,
            'problem'          => $problem,
            // What was chosen, in words, even when it cannot be given here
            // (the admin warning names it).
            'chosen'           => ($menuItem || $voucher) ? self::describe(self::snapshotFrom($type, $menuItem, $voucher, $branchId)) : null,
            'description'      => $configured ? self::describe(self::snapshotFrom($type, $menuItem, $voucher, $branchId)) : null,
        ];
    }

    public static function isConfigured(?int $branchId = null): bool
    {
        return self::current($branchId)['configured'];
    }

    /** The voucher terms JSON, or null when it is missing or malformed. */
    private static function decodeVoucher(?string $json): ?array
    {
        $terms = $json ? json_decode($json, true) : null;

        if (! is_array($terms)
            || ! in_array($terms['discount_type'] ?? null, ['percent', 'fixed'], true)
            || ! is_numeric($terms['discount_value'] ?? null)
            || (float) $terms['discount_value'] <= 0) {
            return null;
        }

        return [
            'voucher_id'     => isset($terms['voucher_id']) ? (int) $terms['voucher_id'] : null,
            'code'           => (string) ($terms['code'] ?? ''),
            'description'    => $terms['description'] ?? null,
            'discount_type'  => $terms['discount_type'],
            'discount_value' => round((float) $terms['discount_value'], 2),
            'minimum_order'  => round(max(0, (float) ($terms['minimum_order'] ?? 0)), 2),
            'branch_id'      => isset($terms['branch_id']) ? (int) $terms['branch_id'] : null,
            'branch_name'    => $terms['branch_name'] ?? null,
            'copied_at'      => $terms['copied_at'] ?? null,
        ];
    }

    /**
     * The reward columns a reward earned at $branchId RIGHT NOW gets, or null
     * when that branch has no reward it can give. branch_id is the ISSUING
     * branch — the only branch the reward can be used at. Expiry and code are
     * added by FreeDesserts::grant().
     */
    public static function snapshotForNewReward(int $branchId): ?array
    {
        $current = self::current($branchId);

        return $current['configured']
            ? self::snapshotFrom($current['type'], $current['menu_item'], $current['voucher'], $branchId)
            : null;
    }

    private static function snapshotFrom(?string $type, ?MenuItem $menuItem, ?array $voucher, ?int $branchId): array
    {
        $empty = [
            'reward_type'    => $type,
            'menu_item_id'   => null,
            'item_name'      => null,
            'voucher_id'     => null,
            'voucher_code'   => null,
            'discount_type'  => null,
            'discount_value' => null,
            'minimum_order'  => null,
            'branch_id'      => $branchId,
        ];

        if ($type === self::TYPE_MENU_ITEM && $menuItem) {
            return array_merge($empty, [
                'menu_item_id' => $menuItem->id,
                'item_name'    => $menuItem->name,
            ]);
        }

        if ($type === self::TYPE_VOUCHER && $voucher) {
            return array_merge($empty, [
                'voucher_id'     => $voucher['voucher_id'],
                'voucher_code'   => $voucher['code'] !== '' ? $voucher['code'] : null,
                'discount_type'  => $voucher['discount_type'],
                'discount_value' => $voucher['discount_value'],
                'minimum_order'  => $voucher['minimum_order'],
            ]);
        }

        return $empty;
    }

    // ══════════ per-branch on/off ══════════

    /** A branch's own switch. A missing row is ON; only exactly '0' is off. */
    public static function branchEnabled(int $branchId): bool
    {
        return Setting::get(self::BRANCH_ENABLED, null, $branchId) !== '0';
    }

    public static function setBranchEnabled(int $branchId, bool $on, ?int $changedBy = null): void
    {
        Setting::updateOrCreate(
            ['key' => self::BRANCH_ENABLED, 'branch_id' => $branchId],
            ['value' => $on ? '1' : '0', 'group' => 'loyalty', 'label' => 'Streak reward stamp card at this branch', 'type' => 'text']
        );

        Log::info('Streak reward branch switch changed', ['branch_id' => $branchId, 'on' => $on, 'changed_by' => $changedBy]);
    }

    /**
     * One line per branch for the owner's overview: its switch, where its
     * reward comes from, and what it is.
     *
     * @return Collection<int, array{branch: Branch, enabled: bool, current: array}>
     */
    public static function overview(): Collection
    {
        return Branch::orderBy('id')->get()->map(fn (Branch $branch) => [
            'branch'  => $branch,
            'enabled' => self::branchEnabled($branch->id),
            'current' => self::current($branch->id),
        ]);
    }

    // ══════════ plain words ══════════

    /**
     * What a reward (or a snapshot array of reward columns) is, in a few
     * words: "Free Chocolate Cake", "10% off one order (min. order ₱200.00)".
     * Where it can be used is shown beside it (FreeDessertReward::branchName()).
     */
    public static function describe(FreeDessertReward|array $reward): string
    {
        $get = fn (string $key) => is_array($reward) ? ($reward[$key] ?? null) : $reward->{$key};

        if ($get('reward_type') === self::TYPE_MENU_ITEM) {
            return 'Free ' . ($get('item_name') ?: 'menu item');
        }

        if ($get('reward_type') === self::TYPE_VOUCHER) {
            $value = (float) $get('discount_value');
            $text = $get('discount_type') === 'percent'
                ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '% off one order'
                : '₱' . number_format($value, 2) . ' off one order';

            if ((float) $get('minimum_order') > 0) {
                $text .= ' (min. order ₱' . number_format((float) $get('minimum_order'), 2) . ')';
            }

            return $text;
        }

        return 'Reward';
    }

    /** "It stays valid for 30 days after it is earned: a reward earned today (...) can be used until the end of ...". */
    public static function validityText(int $validDays): string
    {
        if ($validDays <= 0) {
            return 'It never expires.';
        }

        $until = FreeDesserts::expiresAtFor(now(), $validDays);

        return 'It stays valid for ' . $validDays . ' ' . ($validDays === 1 ? 'day' : 'days')
            . ' after it is earned: a reward earned today (' . ShopDay::format(now(), 'M j, Y')
            . ') can be used until the end of ' . ShopDay::format($until, 'M j, Y') . '.';
    }

    /**
     * The whole rule in plain words, for the admin card. $branchName is the
     * branch $current belongs to; null for the company default.
     */
    public static function ruleText(array $current, ?string $branchName = null): string
    {
        $days = $current['days'];
        $dayWord = $days === 1 ? 'day' : 'days';

        if ($days <= 0) {
            return 'The stamp card is off (no number of days is set).';
        }

        if ($branchName === null) {
            $lead = 'Company default, used by every branch that has no settings of its own. At such a branch, a customer with an account who completes orders there on '
                . $days . ' different ' . $dayWord;
            $where = 'the branch where it was earned';
        } else {
            $lead = 'At ' . $branchName . ', a customer with an account who completes orders at ' . $branchName . ' on '
                . $days . ' different ' . $dayWord;
            $where = $branchName;
        }

        $lead .= ' (1 stamp per day; the days do not need to be in a row, and orders at other branches do not count) ';

        if (! $current['configured']) {
            return $lead . 'would earn a reward, but no reward can be given, so none is. Stamps keep counting, and once a reward is set every customer who reached '
                . $days . ' stamps gets it at their next completed order there.';
        }

        return $lead . 'earns one reward: ' . $current['description'] . '. Each reward gets its own one-time code and can be used only at '
            . $where . '. ' . self::validityText($current['valid_days']);
    }

    /** Why nothing is granted, in words for the admin card; null when a reward can be given. */
    public static function problemText(array $current, ?string $branchName = null): ?string
    {
        $here = $branchName ?? 'every branch';

        return match ($current['problem']) {
            null => null,
            self::PROBLEM_ITEM_GONE => 'The reward item is no longer on the menu, so no reward is being given. Choose another item.',
            self::PROBLEM_ITEM_OTHER_BRANCH => 'The reward item (' . ($current['menu_item']?->name ?? 'menu item') . ') is '
                . ($current['menu_item']?->branch?->name ? 'only on the menu at ' . $current['menu_item']->branch->name : 'not on the menu here')
                . ', so it cannot be given at ' . $here . ' and no reward is being given. Choose an item for ' . ($branchName ?? 'all branches') . '.',
            self::PROBLEM_VOUCHER_OTHER_BRANCH => 'The voucher terms (' . ($current['voucher']['code'] ?? 'voucher') . ') were copied from a voucher for '
                . ($current['voucher']['branch_name'] ?? 'one branch') . ' only, so they cannot be used at ' . $here
                . ' and no reward is being given. Choose a voucher for ' . ($branchName ? $branchName . ' or for all branches' : 'all branches') . '.',
            default => 'No reward is chosen yet, so customers who fill their stamp card ' . ($branchName ? 'at ' . $branchName . ' ' : '') . 'get nothing until one is.',
        };
    }

    // ══════════ saving (the controller decides who may save what) ══════════

    /** The terms copied from a template voucher, as stored in streak_reward_voucher. */
    public static function voucherTermsJson(Voucher $voucher): string
    {
        return json_encode([
            'voucher_id'     => $voucher->id,
            'code'           => $voucher->code,
            'description'    => $voucher->description,
            'discount_type'  => $voucher->discount_type,
            'discount_value' => (string) $voucher->discount_value,
            'minimum_order'  => (string) ($voucher->minimum_order ?? 0),
            'branch_id'      => $voucher->branch_id,
            'branch_name'    => $voucher->branch?->name,
            'copied_at'      => now()->toIso8601String(),
        ]);
    }

    /**
     * Save one reward set: a branch's own ($branchId), or the company default
     * (null). $voucher / $menuItem: the one that matches $type, already
     * validated by the controller for this scope; the other is cleared so the
     * page never shows a stale choice.
     *
     * $applyToAllBranches (company default only): also delete every branch's
     * own reward set, so every branch follows the default from now on. Each
     * branch's on/off switch is a separate key and is left alone.
     *
     * Rewards earned before this keep their own snapshot. The one exception
     * is a reward row with NO snapshot at all (reward_type NULL, earned
     * before rewards had snapshots) that is still available: it takes the
     * reward of the branch where it was earned, keeping its code and expiry.
     */
    public static function save(?int $branchId, int $days, string $type, ?Voucher $voucher, ?MenuItem $menuItem, int $validDays, ?int $savedBy = null, bool $applyToAllBranches = false): void
    {
        $voucherJson = $type === self::TYPE_VOUCHER && $voucher ? self::voucherTermsJson($voucher) : null;

        $values = [
            self::DAYS         => [(string) $days, 'number', 'Streak reward: days with a completed order per reward'],
            self::VALID_DAYS   => [(string) $validDays, 'number', 'Streak reward: days a reward stays valid'],
            self::TYPE         => [$type, 'text', 'Streak reward: type (voucher or menu_item)'],
            self::MENU_ITEM_ID => [$type === self::TYPE_MENU_ITEM && $menuItem ? (string) $menuItem->id : null, 'number', 'Streak reward: menu item given free'],
            self::VOUCHER      => [$voucherJson, 'text', 'Streak reward: voucher terms copied when saved'],
        ];

        $cleared = 0;

        DB::transaction(function () use ($values, $branchId, $applyToAllBranches, &$cleared) {
            foreach ($values as $key => [$value, $fieldType, $label]) {
                Setting::updateOrCreate(
                    ['key' => $key, 'branch_id' => $branchId],
                    ['value' => $value, 'group' => 'loyalty', 'label' => $label, 'type' => $fieldType]
                );
            }

            if ($branchId === null && $applyToAllBranches) {
                $cleared = Setting::whereNotNull('branch_id')->whereIn('key', self::KEYS)->delete();
            }

            self::adoptUnsetRewards();
        });

        Log::info('Streak reward settings saved', [
            'branch_id'     => $branchId,
            'saved_by'      => $savedBy,
            'days'          => $days,
            'type'          => $type,
            'voucher_id'    => $voucher?->id,
            'menu_item'     => $menuItem?->id,
            'valid_days'    => $validDays,
            'apply_to_all'  => $branchId === null && $applyToAllBranches,
            'rows_cleared'  => $cleared,
        ]);
    }

    /**
     * Drop a branch's own reward set so it follows the company default again.
     * Its on/off switch is kept. Returns false when it had none.
     */
    public static function useDefault(int $branchId, ?int $changedBy = null): bool
    {
        $deleted = DB::transaction(function () use ($branchId) {
            $deleted = Setting::where('branch_id', $branchId)->whereIn('key', self::KEYS)->delete();
            self::adoptUnsetRewards();

            return $deleted;
        });

        Log::info('Streak reward branch settings removed (company default from now on)', [
            'branch_id' => $branchId, 'changed_by' => $changedBy, 'rows' => $deleted,
        ]);

        return $deleted > 0;
    }

    /**
     * Rewards with no snapshot (reward_type NULL — earned before rewards had
     * snapshots) that are still available take the reward of the branch they
     * were earned at: their own branch_id, else the branch of the order whose
     * completion earned them. One whose branch is unknown takes the company
     * default, as long as it fits every branch, and stays usable anywhere.
     */
    private static function adoptUnsetRewards(): void
    {
        $unset = FreeDessertReward::with('loyaltyAward.order')
            ->whereNull('reward_type')
            ->where('status', FreeDessertReward::STATUS_AVAILABLE)
            ->get();

        foreach ($unset as $reward) {
            $earnedAt = $reward->branch_id ?? $reward->loyaltyAward?->order?->branch_id;

            if ($earnedAt !== null) {
                $snapshot = self::snapshotForNewReward((int) $earnedAt);
            } else {
                $default = self::current(null);
                $snapshot = $default['configured']
                    ? self::snapshotFrom($default['type'], $default['menu_item'], $default['voucher'], null)
                    : null;
            }

            if ($snapshot !== null) {
                $reward->forceFill($snapshot)->save();
            }
        }
    }
}
