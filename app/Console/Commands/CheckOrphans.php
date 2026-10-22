<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `php artisan pomida:check-orphans` — run BEFORE migration
 * 2026_10_22_000000_add_missing_foreign_keys_for_branches_and_vouchers.
 *
 * It prints the rows that migration cares about — users, orders and
 * free_dessert_rewards pointing at a branch or voucher that does not exist —
 * and says what the migration will do with them:
 *
 *   users / free_dessert_rewards orphans  -> the migration STOPS (exit 1 here)
 *   orders orphans                        -> the migration sets voucher_id NULL
 *                                            (the order keeps its discount)
 *
 * It is READ-ONLY. Every statement it sends is a SELECT or SHOW, and a
 * beforeExecuting hook refuses anything else while it runs, so even a future
 * edit that tried to write would fail instead of changing the live database.
 *
 * The orphan definitions are deliberately repeated here rather than shared
 * with the migration (migrations in this repo never depend on app code);
 * CheckOrphansCommandTest pins that both report the same ids.
 */
class CheckOrphans extends Command
{
    protected $signature = 'pomida:check-orphans';

    protected $description = 'Read-only: list users, orders and rewards that point at a missing branch or voucher (run before the foreign-key migration)';

    /** label, child table, column, parent table, blocking?, extra columns to print */
    private const CHECKS = [
        ['users.branch_id -> branches', 'users', 'branch_id', 'branches', true, ['role']],
        ['orders.voucher_id -> vouchers', 'orders', 'voucher_id', 'vouchers', false, ['order_number', 'discount_type', 'discount_amount']],
        ['free_dessert_rewards.branch_id -> branches', 'free_dessert_rewards', 'branch_id', 'branches', true, ['user_id', 'status']],
    ];

    public function handle(): int
    {
        $readOnly = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$readOnly) {
            if ($readOnly && ! preg_match('/^\s*(select|show)\b/i', $query)) {
                throw new \LogicException('pomida:check-orphans is read-only; refused: ' . $query);
            }
        });

        try {
            return $this->report();
        } finally {
            $readOnly = false;
        }
    }

    private function report(): int
    {
        $this->line('Orphan check on database "' . DB::connection()->getDatabaseName() . '" (read-only, nothing is changed).');
        $this->newLine();

        $blocking = 0;
        $detach = 0;

        foreach (self::CHECKS as [$label, $table, $column, $parent, $blocks, $extra]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $this->line($label . ': column not present yet (earlier migrations are still pending) - skipped.');
                continue;
            }

            $rows = $this->orphans($table, $column, $parent, $extra);
            $fk = $this->foreignKeyOn($table, $column);
            $this->line(sprintf(
                '%s: %d orphan(s); foreign key %s',
                $label,
                $rows->count(),
                $fk ? 'present (ON DELETE ' . strtoupper($fk['on_delete']) . ')' : 'not added yet'
            ));

            foreach ($rows as $row) {
                $fields = ['id ' . $row->id, $column . ' ' . $row->{$column}];
                foreach ($extra as $e) {
                    $fields[] = $e . ' ' . ($row->{$e} ?? 'NULL');
                }
                $this->line('    - ' . implode(', ', $fields));
            }

            if ($rows->isNotEmpty()) {
                $blocks ? $blocking += $rows->count() : $detach += $rows->count();
            }
        }

        $this->newLine();

        if ($blocking > 0) {
            $this->error('NOT READY: ' . $blocking . ' user/reward row(s) point at a missing branch. The migration will stop until each is given a real branch. Nothing was changed.');

            return self::FAILURE;
        }

        if ($detach > 0) {
            $this->info('READY: the migration will set voucher_id to NULL on the ' . $detach . ' order(s) above (their discount type and amounts are kept).');
        } else {
            $this->info('READY: no orphans. The migration can run.');
        }

        return self::SUCCESS;
    }

    private function orphans(string $table, string $column, string $parent, array $extra): Collection
    {
        return DB::table($table . ' as c')
            ->leftJoin($parent . ' as p', 'p.id', '=', 'c.' . $column)
            ->whereNotNull('c.' . $column)
            ->whereNull('p.id')
            ->orderBy('c.id')
            ->get(array_merge(['c.id', 'c.' . $column], array_map(fn ($e) => 'c.' . $e, $extra)));
    }

    private function foreignKeyOn(string $table, string $column): ?array
    {
        foreach (Schema::getForeignKeys($table) as $fk) {
            if ($fk['columns'] === [$column]) {
                return $fk;
            }
        }

        return null;
    }
}
