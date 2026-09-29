<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every PWD / Senior Citizen ID listed on one order (September 2026).
 *
 * A group order can include more than one PWD or Senior Citizen, and each of
 * them is recorded by ID number and full name for the compliance record and
 * the receipt. Before this table an order could hold exactly one
 * (orders.discount_beneficiary_name / _card_number), so a second or third
 * eligible diner had nowhere to go.
 *
 * THESE ROWS ARE A RECORD, NEVER A MULTIPLIER. The discount is still
 * Order::pwdSeniorDiscountFor($subtotal), computed exactly once per order;
 * nothing that prices an order reads this table.
 *
 * Shape follows order_item_options / order_item_size_ingredients: a plain
 * child table hung off its parent, ON DELETE CASCADE so the rows go with the
 * order. `position` keeps the order the IDs were listed in (row 0 is also
 * mirrored into the two legacy orders columns, which every existing screen,
 * report and older order still reads). unique(order_id, position) doubles as
 * the order_id index.
 *
 * No image column, deliberately: ID photos are no longer collected at
 * checkout — staff check the physical ID in person, as the counter always has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_discount_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedSmallInteger('position');
            $table->string('full_name', 100);
            $table->string('id_number', 100);
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');

            $table->unique(['order_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_discount_beneficiaries');
    }
};
