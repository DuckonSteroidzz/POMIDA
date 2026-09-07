<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications for both sides of the app.
 *
 * One table serves customers and staff; the `audience` column decides which
 * side a row belongs to. That keeps the read paths simple (one model, two
 * scopes) instead of two near-identical tables.
 *
 * Ownership is deliberately NOT stored as a token or anything the browser
 * sends back. A customer row is matched by user_id (logged in) or by its
 * order_id against the session's own guest_order_id (guest) — the same rule
 * OrderController::resolveOwnedOrder() already enforces for orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // Customer-audience rows: set for a logged-in customer, NULL for a
            // guest order (guest rows are matched via order_id + session).
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // The order this notification is about. Nullable so the table can
            // carry non-order notifications later without a schema change.
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->cascadeOnDelete();

            // Staff-audience scoping. Staff are locked to their own branch and
            // admins filter by session('selected_branch_id') — same rule as
            // AdminController::getSelectedBranch().
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->string('audience', 20);   // 'customer' | 'staff'
            $table->string('type', 50);       // order_status_changed, gcash_approved, ...
            $table->string('title', 120);
            $table->string('message', 500);

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The three real read patterns:
            //  - a logged-in customer's unread list
            //  - a guest's list for one order
            //  - a staff/admin branch feed
            $table->index(['audience', 'user_id', 'read_at']);
            $table->index(['audience', 'order_id']);
            $table->index(['audience', 'branch_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
