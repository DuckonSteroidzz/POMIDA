<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch parity audit B10 (2026-09-27). categories.branch_id has existed
     * since the table's original migration as a "null = global, set = this
     * branch only" column, but nothing ever wrote or read it — every live
     * and testing row has it NULL (confirmed by querying both databases
     * directly), Category::$fillable listed it but no controller ever
     * validated or assigned a branch_id for a category, and no query
     * anywhere filtered categories by branch. Categories have in fact always
     * been shared across every branch (see updateCategory()'s route comment
     * in web.php), so this column never described anything real. Dropped
     * along with its now-pointless foreign key and the model's dead
     * branch()/branch_id references.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreign('branch_id')
                  ->references('id')
                  ->on('branches')
                  ->onDelete('set null');
        });
    }
};
