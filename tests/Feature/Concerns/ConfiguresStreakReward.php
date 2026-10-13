<?php

namespace Tests\Feature\Concerns;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\Voucher;
use App\Services\StreakRewardSettings;

/**
 * Choosing the stamp card reward in a test (Oct 2026 Streak Rewards).
 *
 * Nothing is granted while no reward is chosen, so any test that earns a
 * reward chooses one first. chooseRewardItem()/chooseRewardVoucher() write
 * the COMPANY DEFAULT (global rows) — which every branch without settings of
 * its own uses — and ONLY the type and the item / voucher rows: the days and
 * validity rows are left absent, so they keep falling back to
 * config('loyalty.free_dessert_visit_days') and
 * config('loyalty.free_dessert_valid_days'), which the tests pin and change.
 * chooseBranchReward() gives one branch its own full set.
 *
 * Every write is inside the test's DatabaseTransactions and rolls back. Call
 * clearStreakRewardSettings() in setUp so a row left in the testing database
 * by a browser session — global OR per branch, including a branch switched
 * off — can never decide a test.
 */
trait ConfiguresStreakReward
{
    protected function clearStreakRewardSettings(): void
    {
        Setting::whereIn('key', array_merge(StreakRewardSettings::KEYS, [StreakRewardSettings::BRANCH_ENABLED]))->delete();
    }

    private function putStreakRewardSetting(string $key, ?string $value, ?int $branchId = null): void
    {
        Setting::updateOrCreate(
            ['key' => $key, 'branch_id' => $branchId],
            ['value' => $value, 'group' => 'loyalty', 'type' => 'text']
        );
    }

    /** The company default reward is $item, free (the rewards earned from now on). */
    protected function chooseRewardItem(MenuItem $item): MenuItem
    {
        $this->putStreakRewardSetting(StreakRewardSettings::TYPE, StreakRewardSettings::TYPE_MENU_ITEM);
        $this->putStreakRewardSetting(StreakRewardSettings::MENU_ITEM_ID, (string) $item->id);
        $this->putStreakRewardSetting(StreakRewardSettings::VOUCHER, null);

        return $item;
    }

    /** The company default reward is $voucher's terms, copied now. */
    protected function chooseRewardVoucher(Voucher $voucher): Voucher
    {
        $this->putStreakRewardSetting(StreakRewardSettings::TYPE, StreakRewardSettings::TYPE_VOUCHER);
        $this->putStreakRewardSetting(StreakRewardSettings::MENU_ITEM_ID, null);
        $this->putStreakRewardSetting(StreakRewardSettings::VOUCHER, StreakRewardSettings::voucherTermsJson($voucher->fresh('branch')));

        return $voucher;
    }

    /**
     * Branch $branchId gets its OWN full set: $days stamps for $reward (a
     * menu item or a voucher's terms), valid $validDays days.
     */
    protected function chooseBranchReward(int $branchId, MenuItem|Voucher $reward, int $days, int $validDays = 30): void
    {
        $isItem = $reward instanceof MenuItem;

        $this->putStreakRewardSetting(StreakRewardSettings::DAYS, (string) $days, $branchId);
        $this->putStreakRewardSetting(StreakRewardSettings::VALID_DAYS, (string) $validDays, $branchId);
        $this->putStreakRewardSetting(StreakRewardSettings::TYPE, $isItem ? StreakRewardSettings::TYPE_MENU_ITEM : StreakRewardSettings::TYPE_VOUCHER, $branchId);
        $this->putStreakRewardSetting(StreakRewardSettings::MENU_ITEM_ID, $isItem ? (string) $reward->id : null, $branchId);
        $this->putStreakRewardSetting(StreakRewardSettings::VOUCHER, $isItem ? null : StreakRewardSettings::voucherTermsJson($reward->fresh('branch')), $branchId);
    }

    /** One branch's own on/off switch. */
    protected function setBranchSwitch(int $branchId, bool $on): void
    {
        $this->putStreakRewardSetting(StreakRewardSettings::BRANCH_ENABLED, $on ? '1' : '0', $branchId);
    }

    /** A plain menu item to use as the reward when the test does not care which. */
    protected function plainRewardItem(array $attrs = []): MenuItem
    {
        return MenuItem::create(array_merge([
            'category_id'   => Category::value('id'),
            'branch_id'     => null,
            'name'          => 'Reward Cake ' . uniqid(),
            'price'         => 120,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ], $attrs))->fresh();
    }
}
