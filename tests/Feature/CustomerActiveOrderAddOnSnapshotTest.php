<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The receipt and order-history pages already read an add-on's name from
 * order_item_options.option_name — the sale-time snapshot — so renaming the
 * add-on later can't rewrite what a past order says was sold. The CURRENT
 * (in-flight) order card on customer/orders.blade.php was reading
 * $item->options->pluck('option_name') instead: 'option_name' is not a real
 * column on menu_options (it lives on the pivot only), so this actually
 * plucked nothing — renaming aside, the add-on line was blank. Fixed to read
 * $option->pivot->option_name, falling back to the live name only for a
 * legacy row with no snapshot.
 */
class CustomerActiveOrderAddOnSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'ADDONSNAP';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function menuItem(): MenuItem
    {
        return MenuItem::create([
            'category_id'   => \App\Models\Category::value('id'),
            'branch_id'     => 1,
            'name'          => self::PREFIX . ' Latte ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function menuOption(string $name): MenuOption
    {
        return MenuOption::create([
            'name'             => $name,
            'additional_price' => 15,
            'is_active'        => true,
            'display_order'    => 0,
        ]);
    }

    public function test_the_active_order_card_shows_the_add_ons_name_as_ordered_not_its_current_name(): void
    {
        $customer = $this->customer();
        $originalName = self::PREFIX . ' Extra Shot ' . uniqid();
        $option = $this->menuOption($originalName);
        $item = $this->menuItem();

        $order = Order::create([
            'order_number'   => self::PREFIX . '-' . strtoupper(substr(uniqid(), -8)),
            'user_id'        => $customer->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'preparing',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 115,
            'total'          => 115,
        ]);

        $orderItem = OrderItem::create([
            'order_id'    => $order->id,
            'menu_item_id' => $item->id,
            'item_name'   => $item->name,
            'item_price'  => 115,
            'quantity'    => 1,
            'subtotal'    => 115,
        ]);

        DB::table('order_item_options')->insert([
            'order_item_id'    => $orderItem->id,
            'menu_option_id'   => $option->id,
            'option_name'      => $originalName,
            'additional_price' => 15,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // The add-on is renamed while the order is still open.
        $option->update(['name' => self::PREFIX . ' RENAMED ' . uniqid()]);

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($originalName, $html, 'the active-order card must show the name as ordered');
        $this->assertStringNotContainsString($option->fresh()->name, $html, 'it must not show the add-on\'s new, live name');
    }

    // No test for a null-snapshot ("legacy row") fallback: order_item_options
    // .option_name is a NOT NULL string column since the table's creation
    // migration (confirmed against pomida_db_testing — inserting NULL raises
    // SQLSTATE 23000), so that branch is unreachable through any real order
    // row. The ?? fallback in the fix is kept anyway, matching the task's
    // instruction and costing nothing, but there is no legitimate row to
    // construct a test around.
}
