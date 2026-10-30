<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * GCash, per branch (Defect Report 5, item 2 — Oct 2026).
 *
 * WHERE IT LIVES
 * --------------
 * In the existing `settings` table, which has always been branch-aware
 * (branch_id NULL = global, unique on branch_id + key). Three keys per branch:
 *
 *     gcash_enabled   '1' or '0'            the owner's on/off switch
 *     gcash_qr        path on the public disk, e.g. settings/gcash/…png
 *     gcash_phone     the number, stored as 11 digits: 09XXXXXXXXX
 *
 * gcash_qr and gcash_phone are the SAME key names the single global setting
 * used, so the data migration is a copy and nothing that already reads the
 * global row breaks. Those global rows are kept (never deleted): they are the
 * fallback for an order placed before its branch had values of its own.
 *
 * THE ONE RULE
 * ------------
 * A branch offers GCash only when it is switched on AND has both a QR and a
 * number (available()). Checkout, the counter's manual order and the server
 * check in front of both all ask this one question, so the option a customer
 * sees and the order the server accepts can never disagree.
 *
 * Writes are owner-only. The routes that reach save() already sit in the
 * owner-only route group; save() refuses anyone else with a 403 on its own,
 * so a future route change cannot quietly hand the money settings to staff.
 */
final class BranchGcash
{
    public const KEY_ENABLED = 'gcash_enabled';
    public const KEY_QR = 'gcash_qr';
    public const KEY_NUMBER = 'gcash_phone';

    public const QR_DIR = 'settings/gcash';
    public const QR_MIMES = 'jpg,jpeg,png,webp';
    public const QR_MAX_KB = 5120;

    /** Shown when a customer or the counter picks GCash for a branch that does not offer it. */
    public const UNAVAILABLE_MESSAGE = 'GCash is not available for this branch right now. Please choose Cash.';

    public const NUMBER_MESSAGE = 'Enter a valid PH mobile number: 11 digits starting with 09.';

    public const ENABLE_NEEDS_BOTH_MESSAGE = 'To turn GCash on, this branch needs both a QR code and a GCash number.';

    /**
     * The branch's GCash state.
     *
     * @return array{enabled: bool, qr: ?string, number: ?string, available: bool}
     */
    public static function for(Branch|int|null $branch): array
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        if (!$branchId) {
            return ['enabled' => false, 'qr' => null, 'number' => null, 'available' => false];
        }

        $rows = Setting::where('branch_id', $branchId)
            ->whereIn('key', [self::KEY_ENABLED, self::KEY_QR, self::KEY_NUMBER])
            ->pluck('value', 'key');

        $enabled = ($rows[self::KEY_ENABLED] ?? '0') === '1';
        $qr = filled($rows[self::KEY_QR] ?? null) ? $rows[self::KEY_QR] : null;
        $number = filled($rows[self::KEY_NUMBER] ?? null) ? $rows[self::KEY_NUMBER] : null;

        return [
            'enabled' => $enabled,
            'qr' => $qr,
            'number' => $number,
            'available' => $enabled && $qr !== null && $number !== null,
        ];
    }

    /** Does this branch offer GCash right now? */
    public static function available(Branch|int|null $branch): bool
    {
        return self::for($branch)['available'];
    }

    /**
     * The QR and number to show on an order's GCash payment page.
     *
     * The ORDER'S branch values, whether or not GCash is still switched on —
     * an order placed before the owner turned GCash off must still be payable
     * (the customer may already be mid-transfer). Only a branch that never had
     * values of its own falls back to the old global ones.
     *
     * @return array{qr: ?string, number: ?string}
     */
    public static function forPayment(?int $branchId): array
    {
        $branch = self::for($branchId);

        return [
            'qr' => $branch['qr'] ?? Setting::get(self::KEY_QR, null, null) ?: null,
            'number' => $branch['number'] ?? Setting::get(self::KEY_NUMBER, null, null) ?: null,
        ];
    }

    /**
     * The number as it is stored (11 digits, 09XXXXXXXXX), or null when it is
     * not a PH mobile number. Accepts what people actually type: spaces,
     * dashes, dots, brackets, the +63 / 63 country prefix, and the bare ten
     * digits after +63 (9XXXXXXXXX) that the Account Settings field takes
     * beside its fixed +63.
     */
    public static function normalizeNumber(?string $input): ?string
    {
        $digits = preg_replace('/[\s\-\.\(\)]/', '', (string) $input);

        if (preg_match('/^(?:\+?63)?(9\d{9})$/', $digits, $m)) {
            $digits = '0' . $m[1];
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
    }

    /** Validation rules for the QR file (server side; the page checks too). */
    public static function qrRules(): array
    {
        return ['nullable', 'file', 'image', 'mimes:' . self::QR_MIMES, 'max:' . self::QR_MAX_KB];
    }

    public static function qrMessages(string $field): array
    {
        return [
            "{$field}.image" => 'The GCash QR must be a JPG, JPEG, PNG, or WEBP image.',
            "{$field}.mimes" => 'The GCash QR must be a JPG, JPEG, PNG, or WEBP image.',
            "{$field}.max" => 'The GCash QR must be 5 MB or smaller.',
            "{$field}.file" => 'The GCash QR upload did not arrive. Please choose the image again.',
            "{$field}.uploaded" => 'The GCash QR could not be uploaded. It must be 5 MB or smaller.',
        ];
    }

    /**
     * Save one branch's GCash settings. Owner only (403 otherwise).
     *
     * $enabled null = leave the switch as it is (the Account Settings card has
     * no switch). The new QR is stored first, under a random name derived from
     * the file's content; the rows are written; and only then is the previous
     * file removed — and only if no other settings row (another branch, or the
     * old global row) still points at it, since the data migration gave every
     * branch the same file.
     *
     * Validation (number format, "on needs both") is the caller's job, done
     * before this is called, so the error lands on the right field.
     */
    public static function save(Branch $branch, ?bool $enabled, ?string $number, ?UploadedFile $qr): void
    {
        abort_unless(auth('admin')->user()?->role === 'admin', 403);

        $previousQr = self::for($branch)['qr'];
        $newPath = $qr ? $qr->storeAs(self::QR_DIR, UploadedImageName::for($qr, 'gcash_'), 'public') : null;

        try {
            DB::transaction(function () use ($branch, $enabled, $number, $newPath) {
                self::put($branch->id, self::KEY_NUMBER, $number ?? '', 'GCash Phone Number', 'text');

                if ($newPath !== null) {
                    self::put($branch->id, self::KEY_QR, $newPath, 'GCash QR Code', 'image');
                }

                if ($enabled !== null) {
                    self::put($branch->id, self::KEY_ENABLED, $enabled ? '1' : '0', 'GCash Enabled', 'boolean');
                }
            });
        } catch (\Throwable $e) {
            if ($newPath !== null) {
                Storage::disk('public')->delete($newPath);
            }

            throw $e;
        }

        if ($newPath !== null && $previousQr !== null && $previousQr !== $newPath) {
            self::deleteIfUnreferenced($previousQr);
        }
    }

    /** Remove a QR file from the public disk unless any settings row still uses it. */
    public static function deleteIfUnreferenced(string $path): void
    {
        if (Setting::where('key', self::KEY_QR)->where('value', $path)->exists()) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private static function put(int $branchId, string $key, string $value, string $label, string $type): void
    {
        Setting::updateOrCreate(
            ['branch_id' => $branchId, 'key' => $key],
            ['value' => $value, 'group' => 'payment', 'label' => $label, 'type' => $type]
        );
    }
}
