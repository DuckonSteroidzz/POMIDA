<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turn `games_played` into the real spin ledger.
 *
 * create_games_played_table only ever made `id` + timestamps. Nothing read or
 * wrote it, so the Spin & Win wheel had no server-side record of a spin at all
 * — which is exactly why the wheel was unbounded: with nothing counted, there
 * was nothing to cap.
 *
 * One row per accepted spin. The row is what makes the cap enforceable, and it
 * doubles as an audit trail for "where did these points come from?".
 *
 * WHY THE GUARDS
 * --------------
 * Same reason as add_ad_fields_to_ads_table: this project's live database has
 * repeatedly drifted from the migration history, so an additive migration that
 * assumes a clean slate can abort and block everything behind it. Each column
 * and index is therefore added only when genuinely missing, making this safe to
 * run against the live database as well as a fresh one.
 */
return new class extends Migration
{
    /**
     * Column definitions, applied only when the column is absent.
     * Keyed by column name so the guard and the definition cannot drift apart.
     */
    private function columns(): array
    {
        return [
            // The customer who spun. NULL for a guest — guests can play the
            // wheel (the route has no auth middleware and addPoints() has an
            // explicit guest branch), they just bank points in the session and
            // never earn a voucher. Guest rows are attributed through order_id
            // instead, matched against session('guest_order_id').
            'user_id' => fn (Blueprint $t) => $t->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete(),

            // The order whose "waiting window" this spin was spent on. This is
            // the whole point of the table: the 5-spin cap is counted per
            // ORDER, not per item and not per account.
            //
            // Not nullable — a spin with no order is exactly what the cap
            // exists to forbid, so the schema refuses to represent one.
            'order_id' => fn (Blueprint $t) => $t->foreignId('order_id')
                ->after('user_id')
                ->constrained('orders')
                ->cascadeOnDelete(),

            // 1..5 within the window. Stored rather than derived so the ad
            // trigger ("show the game ad on the last spin of the window") reads
            // one authoritative number instead of recounting and hoping the
            // browser agrees.
            'spin_number' => fn (Blueprint $t) => $t->unsignedTinyInteger('spin_number')
                ->after('order_id'),

            // What the wheel actually paid out, from the server-side allowlist
            // in AuthController::GAME_POINT_AWARDS. Audit trail only.
            'points_awarded' => fn (Blueprint $t) => $t->unsignedSmallInteger('points_awarded')
                ->default(0)
                ->after('spin_number'),
        ];
    }

    /**
     * Indexes, applied only when absent. Named explicitly so hasIndex() can
     * check them by name.
     */
    private function indexes(): array
    {
        return [
            // The hot query: "how many spins has this order already used?"
            'games_played_order_id_index' => ['order_id'],

            // The daily anti-farming ceiling: "how many spins has this account
            // used today?"
            'games_played_user_id_created_at_index' => ['user_id', 'created_at'],
        ];
    }

    public function up(): void
    {
        $missing = array_filter(
            $this->columns(),
            fn ($_, $name) => !Schema::hasColumn('games_played', $name),
            ARRAY_FILTER_USE_BOTH
        );

        if (!empty($missing)) {
            Schema::table('games_played', function (Blueprint $table) use ($missing) {
                foreach ($missing as $define) {
                    $define($table);
                }
            });
        }

        // constrained() already creates an index on the FK column, so only add
        // the ones that are genuinely absent.
        foreach ($this->indexes() as $name => $columns) {
            if (!$this->hasIndex($name)) {
                Schema::table('games_played', function (Blueprint $table) use ($name, $columns) {
                    $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes()) as $name) {
            if ($this->hasIndex($name)) {
                Schema::table('games_played', function (Blueprint $table) use ($name) {
                    $table->dropIndex($name);
                });
            }
        }

        foreach (['user_id', 'order_id'] as $fk) {
            if (Schema::hasColumn('games_played', $fk)) {
                Schema::table('games_played', function (Blueprint $table) use ($fk) {
                    $table->dropForeign(['games_played_' . $fk . '_foreign']);
                });
            }
        }

        $present = array_filter(
            array_keys($this->columns()),
            fn ($name) => Schema::hasColumn('games_played', $name)
        );

        if (!empty($present)) {
            Schema::table('games_played', function (Blueprint $table) use ($present) {
                $table->dropColumn(array_values($present));
            });
        }
    }

    /**
     * Schema::hasIndex() only exists from Laravel 11.15; this project pins an
     * older 11.x, so ask the connection directly. Works on MySQL/MariaDB, which
     * is what this app runs on.
     */
    private function hasIndex(string $name): bool
    {
        return collect(
            Schema::getConnection()->select(
                'SHOW INDEX FROM `games_played` WHERE Key_name = ?',
                [$name]
            )
        )->isNotEmpty();
    }
};
