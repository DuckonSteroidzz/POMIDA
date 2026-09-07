<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Setting;

/**
 * StoreContact — the shop's real, stored contact details for a given branch.
 *
 * Why this exists
 * ---------------
 * The "Store Information" block on the customer More page resolves the shop's
 * name, address, phone and email from the branch row first, then the settings
 * table, then a last-resort default. A pick-up customer who paid by GCash and
 * has a problem (wrong order, refund question) needs those same details on the
 * page where the problem shows up — their order page and receipt — not only on
 * More. Rather than copy the resolution into three controllers, it lives here
 * once and every caller reads the same values.
 *
 * Nothing here is invented: every value comes from the branches table or the
 * settings table. The final fallbacks match what the More page has always
 * shown, so this class does not change any existing screen.
 */
class StoreContact
{
    /**
     * @return array{business_name:string,address:string,contact_number:string,email:string,facebook_url:string,instagram_url:string,tiktok_url:string,other_social_url:string}
     */
    public static function forBranch(int|string|null $branchId): array
    {
        $branchId = is_numeric($branchId) ? (int) $branchId : null;

        $branch = $branchId ? Branch::find($branchId) : null;

        if (!$branch) {
            $branch = Branch::where('is_main_branch', true)->first()
                ?: Branch::where('is_active', true)->orderBy('id')->first();
        }

        $getSetting = function (string $key, string $default = '') use ($branch) {
            if ($branch) {
                $value = Setting::get($key, null, $branch->id);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            return Setting::get($key, $default, null);
        };

        return [
            'business_name'    => $branch?->name ?? $getSetting('business_name', 'Peachy Cakes & Deli Cafe'),
            'address'          => $branch?->address ?? $getSetting('business_address', ''),
            'contact_number'   => $branch?->contact_number ?? $getSetting('business_contact', '0917 120 3627'),
            'email'            => $branch?->email ?? $getSetting('business_email', 'peachycakesdelicafe@gmail.com'),
            'facebook_url'     => $getSetting('facebook_url'),
            'instagram_url'    => $getSetting('instagram_url'),
            'tiktok_url'       => $getSetting('tiktok_url'),
            'other_social_url' => $getSetting('other_social_url'),
        ];
    }
}
