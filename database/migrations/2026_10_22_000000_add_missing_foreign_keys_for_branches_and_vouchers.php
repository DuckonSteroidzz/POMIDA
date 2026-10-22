<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The three relationships the ERD (docs/erd, Oct 2026) found declared in a
 * model but missing from the database: each column was an unsigned bigint with
 * no foreign key, so nothing stopped it from pointing at a row that is gone.
 *
 *   users.branch_id                -> branches   ON DELETE RESTRICT
 *   orders.voucher_id              -> vouchers   ON DELETE SET NULL
 *   free_dessert_rewards.branch_id -> branches   ON DELETE RESTRICT
 *
 * WHY EACH RULE
 * -------------
 * users.branch_id RESTRICT — a NULL branch_id means "unrestricted" for the
 * owner (admin) and "sees nothing" for staff/supervisor
 * (AdminOrderAccess::lockedBranchId() returns 0). SET NULL would therefore
 * silently strand every account of a deleted branch, and CASCADE would delete
 * the accounts. Branches are never deleted by the app (toggleBranch() closes
 * them), so RESTRICT only stops a hand-run DELETE while staff are assigned.
 *
 * orders.voucher_id SET NULL — an order keeps its own record of the discount:
 * discount_type = 'voucher', discount_amount, subtotal and total, and the
 * receipt label comes from discount_type (Order::discountLabel()). Nothing
 * reads the voucher back through orders.voucher_id after the order is placed
 * (used_count lives on the voucher). So the owner can still delete a voucher
 * exactly as before (deleteVoucher()), and its orders keep their history with
 * a NULL link instead of a dangling id. RESTRICT would newly block deleting any
 * voucher that was ever used.
 *
 * free_dessert_rewards.branch_id RESTRICT — NULL here means "usable at ANY
 * branch" (a reward from before rewards were per branch). That is why
 * 2026_10_12_000000 left this column without a foreign key: SET NULL would
 * silently widen a one-branch reward to every branch. RESTRICT has neither
 * problem — it never rewrites the value, and CASCADE would delete reward
 * history customers still hold.
 *
 * ORPHANS ARE HANDLED BEFORE ANY DDL
 * ----------------------------------
 * MySQL DDL is not transactional, so every check runs first:
 *
 *   - users / free_dessert_rewards rows that point at a missing branch: the
 *     migration REFUSES and lists the ids. Which branch they belong to is a
 *     business decision (a NULL would lock a staff account out, or open a
 *     reward to every branch), so it is never guessed here.
 *   - orders that point at a missing voucher: voucher_id is set to NULL. The id
 *     already points at nothing, and the order's own discount columns are
 *     untouched, so no history is lost. The ids are written to the log.
 *
 * Run `php artisan pomida:check-orphans` before migrating to see the same
 * counts and ids without changing anything.
 *
 * Every step is skipped when already in place, so a run that was interrupted
 * can simply be run again. down() drops only the foreign keys and indexes this
 * migration created, by name.
 */
return new class extends Migration
{
    /**
     * table => [column, parent table, on delete, index this migration adds, foreign key name].
     *
     * @var array<string, array{0:string,1:string,2:string,3:string,4:string}>
     */
    private const LINKS = [
        'users'                => ['branch_id', 'branches', 'restrict', 'users_branch_id_index', 'users_branch_id_foreign'],
        'orders'               => ['voucher_id', 'vouchers', 'set null', 'orders_voucher_id_index', 'orders_voucher_id_foreign'],
        'free_dessert_rewards' => ['branch_id', 'branches', 'restrict', 'free_dessert_rewards_branch_id_index', 'free_dessert_rewards_branch_id_foreign'],
    ];

    public function up(): void
    {
        $this->refuseUnresolvableOrphans();
        $this->detachOrdersFromMissingVouchers();

        foreach (self::LINKS as $table => [$column, $parent, $onDelete, $index, $foreign]) {
            if (! Schema::hasColumn($table, $column) || $this->foreignKeyOn($table, $column) !== null) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $parent, $onDelete, $index, $foreign) {
                if (! $this->hasIndexStartingWith($table, $column)) {
                    $blueprint->index($column, $index);
                }

                $blueprint->foreign($column, $foreign)->references('id')->on($parent)->onDelete($onDelete);
            });
        }
    }

    public function down(): void
    {
        foreach (self::LINKS as $table => [$column, , , $index, $foreign]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if ($this->foreignKeyNamed($table, $foreign)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign));
            }

            if (in_array($index, Schema::getIndexListing($table), true)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
            }
        }
    }

    /**
     * Users and rewards pointing at a branch that does not exist — refuse,
     * listing every id, before anything is changed.
     */
    public function refuseUnresolvableOrphans(): void
    {
        $problems = [];

        if (Schema::hasColumn('users', 'branch_id')) {
            $rows = $this->orphans('users', 'branch_id', 'branches', ['role']);
            if ($rows->isNotEmpty()) {
                $problems[] = $rows->count() . ' user(s) point at a branch that does not exist: '
                    . $rows->map(fn ($r) => 'user ' . $r->id . ' (' . $r->role . ', branch_id ' . $r->branch_id . ')')->implode(', ')
                    . '. Assign each one a real branch (or, for an admin or customer only, set branch_id to NULL).';
            }
        }

        if (Schema::hasColumn('free_dessert_rewards', 'branch_id')) {
            $rows = $this->orphans('free_dessert_rewards', 'branch_id', 'branches', ['user_id']);
            if ($rows->isNotEmpty()) {
                $problems[] = $rows->count() . ' free_dessert_rewards row(s) point at a branch that does not exist: '
                    . $rows->map(fn ($r) => 'reward ' . $r->id . ' (user ' . $r->user_id . ', branch_id ' . $r->branch_id . ')')->implode(', ')
                    . '. Set each one to the branch that issued it (NULL would make it usable at every branch).';
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'Refusing to add the missing foreign keys. ' . implode(' ', $problems)
                . ' Nothing was changed. Run php artisan pomida:check-orphans to see them, fix them, then migrate again.'
            );
        }
    }

    /**
     * Orders pointing at a voucher that no longer exists lose only that dead
     * link; their discount_type / discount_amount / total stay as they are.
     *
     * @return int how many orders were changed
     */
    public function detachOrdersFromMissingVouchers(): int
    {
        if (! Schema::hasColumn('orders', 'voucher_id')) {
            return 0;
        }

        $rows = $this->orphans('orders', 'voucher_id', 'vouchers');
        if ($rows->isEmpty()) {
            return 0;
        }

        $changed = DB::table('orders')->whereIn('id', $rows->pluck('id'))
            ->whereNotIn('voucher_id', DB::table('vouchers')->select('id'))
            ->update(['voucher_id' => null]);

        Log::warning('Migration add_missing_foreign_keys: set orders.voucher_id to NULL where the voucher no longer exists', [
            'orders' => $rows->map(fn ($r) => ['order_id' => $r->id, 'old_voucher_id' => $r->voucher_id])->all(),
        ]);

        return $changed;
    }

    /** Child rows whose non-null $column matches no $parent.id. */
    private function orphans(string $table, string $column, string $parent, array $extra = []): \Illuminate\Support\Collection
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

    private function foreignKeyNamed(string $table, string $name): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn ($fk) => $fk['name'] === $name);
    }

    private function hasIndexStartingWith(string $table, string $column): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($ix) => ($ix['columns'][0] ?? null) === $column);
    }
};
