<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the real Ad fields on top of create_ads_table (which only makes
 * id + timestamps).
 *
 * WHY THE GUARDS
 * --------------
 * On the original development database these columns already exist even though
 * this migration was never recorded as run — they were created outside the
 * migration system, so `migrate` kept aborting here with
 * "Duplicate column name 'title'" and every later migration was blocked behind
 * it.
 *
 * Each column is therefore added only if it is genuinely missing. On a database
 * that already has them this migration is a harmless no-op that simply records
 * itself as run; on a fresh database it builds the table properly. Writing
 * additive migrations this way is the more resilient pattern generally.
 *
 * The definitions below deliberately match what the application actually
 * expects (see AdminController::storeAd validation), not the looser types the
 * first draft of this file used:
 *   - title      required        ('title' => 'required|string|max:255')
 *   - placement  enum of 4       ('placement' => 'required|in:game,menu,cart,orders')
 *   - description text           ('description' => 'nullable|string|max:500')
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
            'title'         => fn (Blueprint $t) => $t->string('title'),
            'description'   => fn (Blueprint $t) => $t->text('description')->nullable(),
            'image'         => fn (Blueprint $t) => $t->string('image')->nullable(),
            'link'          => fn (Blueprint $t) => $t->string('link')->nullable(),
            'placement'     => fn (Blueprint $t) => $t->enum('placement', ['game', 'menu', 'cart', 'orders'])->default('game'),
            'is_active'     => fn (Blueprint $t) => $t->boolean('is_active')->default(true),
            'display_order' => fn (Blueprint $t) => $t->integer('display_order')->default(0),
            'starts_at'     => fn (Blueprint $t) => $t->timestamp('starts_at')->nullable(),
            'ends_at'       => fn (Blueprint $t) => $t->timestamp('ends_at')->nullable(),
        ];
    }

    public function up(): void
    {
        $missing = array_filter(
            $this->columns(),
            fn ($_, $name) => !Schema::hasColumn('ads', $name),
            ARRAY_FILTER_USE_BOTH
        );

        if (empty($missing)) {
            // Everything is already in place — nothing to do.
            return;
        }

        Schema::table('ads', function (Blueprint $table) use ($missing) {
            foreach ($missing as $define) {
                $define($table);
            }
        });
    }

    /**
     * Only drop what is actually there, so a partially-applied state can still
     * be rolled back without erroring.
     */
    public function down(): void
    {
        $present = array_filter(
            array_keys($this->columns()),
            fn ($name) => Schema::hasColumn('ads', $name)
        );

        if (empty($present)) {
            return;
        }

        Schema::table('ads', function (Blueprint $table) use ($present) {
            $table->dropColumn(array_values($present));
        });
    }
};
