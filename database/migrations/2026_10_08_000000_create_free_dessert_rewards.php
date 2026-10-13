<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free dessert for a 7-day ordering streak (Oct 2026).
 *
 * menu_items.free_dessert_eligible
 * --------------------------------
 * Which menu items a reward can be spent on. Default false, so nothing is
 * eligible until a manager ticks it on the Add/Edit menu item form. Branch and
 * stock rules are NOT stored here: an eligible item is offered only where, and
 * while, it could be ordered normally (App\Services\FreeDesserts::choicesFor).
 *
 * order_items.is_free_dessert
 * ---------------------------
 * Marks the ONE line on an order that was given free (item_price and subtotal
 * 0). Staff screens, receipts and the dessert rating read it.
 *
 * free_dessert_rewards
 * --------------------
 * One row per reward earned. loyalty_award_id is UNIQUE and points at the
 * loyalty_awards ledger row (type free_dessert, key "<days>:<streak start>"),
 * so the ledger's own UNIQUE(user_id, type, award_key) is what makes earning
 * happen once per streak, exactly like the streak point bonuses.
 *
 *   status     available -> used (in the order transaction)
 *              used -> available (order cancelled / GCash rejected before completion)
 *              available -> expired (expires_at passed)
 *   order_id / order_item_id   set while used; SET NULL if the order is deleted
 *
 * free_dessert_ratings
 * --------------------
 * The customer's separate rating + comment for the free dessert on a
 * completed order, next to (never inside) order_ratings. UNIQUE(order_id): one
 * per order. Nothing is ever awarded for rating.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('menu_items', 'free_dessert_eligible')) {
            Schema::table('menu_items', function (Blueprint $table) {
                $table->boolean('free_dessert_eligible')->default(false)->after('is_featured');
            });
        }

        if (! Schema::hasColumn('order_items', 'is_free_dessert')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->boolean('is_free_dessert')->default(false)->after('subtotal');
            });
        }

        if (! Schema::hasTable('free_dessert_rewards')) {
            Schema::create('free_dessert_rewards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('loyalty_award_id')->unique()->constrained('loyalty_awards')->cascadeOnDelete();
                $table->string('status', 16)->default('available');
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }

        if (! Schema::hasTable('free_dessert_ratings')) {
            Schema::create('free_dessert_ratings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
                $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->unsignedTinyInteger('rating');
                $table->text('comment')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('free_dessert_ratings');
        Schema::dropIfExists('free_dessert_rewards');

        if (Schema::hasColumn('order_items', 'is_free_dessert')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('is_free_dessert');
            });
        }

        if (Schema::hasColumn('menu_items', 'free_dessert_eligible')) {
            Schema::table('menu_items', function (Blueprint $table) {
                $table->dropColumn('free_dessert_eligible');
            });
        }
    }
};
