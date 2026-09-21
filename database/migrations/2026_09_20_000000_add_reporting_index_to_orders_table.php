<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * INDEX ONLY. No column is added, dropped, widened or backfilled, and no
     * row is touched. Every report keeps reading exactly the rows it read
     * before; this only changes how MySQL finds them.
     *
     * ── What the reports actually ask for ──────────────────────────────────
     *
     * Analytics, Analytics Print, Summary and the sales CSV all resolve to the
     * same query shape, in AnalyticsService and ProfitCalculationService:
     *
     *      WHERE branch_id = ?              -- equality (branch-scoped views)
     *        AND status = 'completed'       -- equality (always)
     *        AND completed_at BETWEEN ? ?   -- range
     *      GROUP BY DATE(completed_at)      -- daily series only
     *
     * Before this migration `orders` carried only PRIMARY, the unique on
     * order_number, and four single-column foreign-key indexes. EXPLAIN on the
     * shape above chose `key=NULL, type=ALL, Using temporary; Using filesort` —
     * a full table scan plus a sort, even with branch_id pinned, because the
     * lone branch_id FK index is not selective enough on its own for the
     * optimiser to prefer it.
     *
     * ── Why this column order ──────────────────────────────────────────────
     *
     * branch_id, status, completed_at — equality columns first, range column
     * last. That ordering is not stylistic; it is what makes the index usable:
     *
     *  1. branch_id FIRST. A composite index can only be used from its
     *     leftmost column inward, so the first column has to be one that
     *     virtually every query pins. branch_id is pinned by every
     *     branch-scoped report, and is the most selective of the three the
     *     moment there is more than one branch.
     *
     *  2. status SECOND. Also an equality predicate, and present on every one
     *     of these queries. It sits after branch_id rather than before it
     *     because it is the LEAST selective column here: a five-value enum
     *     that will be overwhelmingly 'completed' as the table grows. Leading
     *     with it would mean the index's first level barely narrows anything.
     *
     *  3. completed_at LAST. This is the RANGE predicate, and MySQL can exploit
     *     an index for at most one range column — and only when that column is
     *     the last one it uses from the index. Put completed_at earlier and the
     *     index stops being useful at that point, taking status with it. Put it
     *     last and the two equality columns narrow to one contiguous block that
     *     is already ordered by completed_at, so the range is a bounded scan of
     *     that block instead of a scan of the table.
     *
     * ── Measured effect, and what it does NOT fix ──────────────────────────
     *
     * EXPLAIN on the branch-scoped shape, pomida_db_testing, before -> after:
     *
     *      before:  type=ALL  key=NULL  Using where; Using temporary; Using filesort
     *      after:   type=ref  key=orders_branch_id_status_completed_at_index
     *                         Using index condition; Using where;
     *                         Using temporary; Using filesort
     *
     * So the full table scan becomes a ref lookup — which is the win — but the
     * temporary table and filesort REMAIN, and no column order could have
     * removed them. The daily series groups by DATE(completed_at), a FUNCTION
     * of the column rather than the column itself, and an index can only supply
     * ordering for the bare column. MySQL therefore still materialises and
     * sorts the groups. That cost is now proportional to the rows the index
     * selected instead of to the whole table, which is the part that matters;
     * removing it outright would need a stored/generated date column, which is
     * a schema change this index-only migration deliberately does not make.
     *
     * ── What this deliberately does NOT do ─────────────────────────────────
     *
     *  - It does not drop orders_branch_id_foreign, which is now a redundant
     *    prefix of this index. That index exists to satisfy the foreign key
     *    constraint on branch_id; dropping it would leave the FK unbacked.
     *
     *  - It adds no second index for the "All Branches" scope. That scope does
     *    not filter branch_id, so it cannot use this index's leftmost column
     *    and still scans. A (status, completed_at) index would serve it, but it
     *    would duplicate most of this one for a case only the owner's
     *    consolidated view hits, on a table currently in the low hundreds of
     *    rows. Worth revisiting when the table is large enough for that scan to
     *    be felt; not worth carrying two overlapping indexes now.
     */
    public function up(): void
    {
        // Guarded so the migration is safe to re-run against a database that
        // already has it — the schema is shared with a live copy that may have
        // been patched by hand.
        if ($this->indexExists('orders_branch_id_status_completed_at_index')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->index(
                ['branch_id', 'status', 'completed_at'],
                'orders_branch_id_status_completed_at_index'
            );
        });
    }

    public function down(): void
    {
        if (!$this->indexExists('orders_branch_id_status_completed_at_index')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_branch_id_status_completed_at_index');
        });
    }

    private function indexExists(string $name): bool
    {
        return count(DB::select(
            'SHOW INDEX FROM `orders` WHERE Key_name = ?',
            [$name]
        )) > 0;
    }
};
