<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GCash per branch (Defect Report 5, item 2): give every existing branch the
 * QR and number the shop has been using, so nothing changes for customers on
 * the day this is deployed.
 *
 * No schema change. The `settings` table is already branch-aware (branch_id
 * NULL = global, unique on branch_id + key); this only writes rows into it.
 *
 * For each branch:
 *   - gcash_qr    ← the global gcash_qr path   (only if the branch has none yet)
 *   - gcash_phone ← the global gcash_phone     (only if the branch has none yet)
 *   - gcash_enabled = '1' when the branch ends up with BOTH a QR and a number,
 *     '0' otherwise (only if the branch has no switch row yet)
 *
 * Every branch points at the SAME QR file — nothing is copied on disk. That is
 * safe because App\Support\BranchGcash::save() only deletes an old QR once no
 * settings row references it, and the global row (kept, see below) always does.
 *
 * The global rows are NOT deleted. They stay as the fallback for an order whose
 * branch has no values of its own (BranchGcash::forPayment()).
 *
 * Idempotent: rows a branch already has are never overwritten, so running it
 * twice, or after an owner has started editing branches, changes nothing.
 */
return new class extends Migration
{
    private const LABELS = [
        'gcash_qr' => ['GCash QR Code', 'image'],
        'gcash_phone' => ['GCash Phone Number', 'text'],
        'gcash_enabled' => ['GCash Enabled', 'boolean'],
    ];

    public function up(): void
    {
        $global = DB::table('settings')
            ->whereNull('branch_id')
            ->whereIn('key', ['gcash_qr', 'gcash_phone'])
            ->pluck('value', 'key');

        $globalQr = trim((string) ($global['gcash_qr'] ?? ''));
        $globalNumber = trim((string) ($global['gcash_phone'] ?? ''));
        $now = now();

        foreach (DB::table('branches')->pluck('id') as $branchId) {
            $existing = DB::table('settings')
                ->where('branch_id', $branchId)
                ->whereIn('key', array_keys(self::LABELS))
                ->pluck('value', 'key');

            $qr = $existing->has('gcash_qr') ? trim((string) $existing['gcash_qr']) : $globalQr;
            $number = $existing->has('gcash_phone') ? trim((string) $existing['gcash_phone']) : $globalNumber;

            $rows = [];

            if (!$existing->has('gcash_qr') && $globalQr !== '') {
                $rows['gcash_qr'] = $globalQr;
            }

            if (!$existing->has('gcash_phone') && $globalNumber !== '') {
                $rows['gcash_phone'] = $globalNumber;
            }

            if (!$existing->has('gcash_enabled')) {
                $rows['gcash_enabled'] = ($qr !== '' && $number !== '') ? '1' : '0';
            }

            foreach ($rows as $key => $value) {
                [$label, $type] = self::LABELS[$key];

                DB::table('settings')->insert([
                    'branch_id' => $branchId,
                    'key' => $key,
                    'value' => $value,
                    'group' => 'payment',
                    'label' => $label,
                    'description' => null,
                    'type' => $type,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Removes only what up() could have written and nobody has changed since:
     * every branch switch row, and a branch's QR / number only where it still
     * equals the global value. A QR or number an owner set for a branch after
     * the update is kept, so rolling back never throws away real settings.
     */
    public function down(): void
    {
        $global = DB::table('settings')
            ->whereNull('branch_id')
            ->whereIn('key', ['gcash_qr', 'gcash_phone'])
            ->pluck('value', 'key');

        DB::table('settings')->whereNotNull('branch_id')->where('key', 'gcash_enabled')->delete();

        foreach (['gcash_qr', 'gcash_phone'] as $key) {
            if (!isset($global[$key])) {
                continue;
            }

            DB::table('settings')
                ->whereNotNull('branch_id')
                ->where('key', $key)
                ->where('value', $global[$key])
                ->delete();
        }
    }
};
