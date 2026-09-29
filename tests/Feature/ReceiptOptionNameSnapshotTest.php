<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3b F9 — customer/receipt.blade.php:386 rendered the LIVE
 * `$option->name` for a past order's add-ons instead of the sale-time
 * snapshot already frozen in order_item_options.option_name (see the
 * migration, and OrderItem::options()'s withPivot('option_name', …)). The
 * item name beside it correctly used the item_name snapshot already — only
 * the add-on label did not.
 *
 * Renaming a menu option (AdminController::updateMenuOption()) therefore
 * rewrote the add-on label on every past receipt that used it, even though
 * order_item_options exists specifically to prevent that — flagged in-code at
 * AdminController.php:2007-2017 as "out of scope for this pass" when it was
 * found, closed here.
 *
 * AdminController::showReceipt() renders this SAME customer.receipt view (see
 * routes/web.php's admin.receipt -> AdminController::showReceipt(), which
 * passes isAdminView), so one fix closes the gap on both the customer- and
 * staff-facing receipt.
 */
class ReceiptOptionNameSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private function menuItemWithOption(): array
    {
        // A branch-1 item, so a branch-1 dine-in order can select the option.
        $item = MenuItem::where('is_available', true)
            ->where('branch_id', 1)
            ->orderBy('id')
            ->first()
            ?? MenuItem::where('is_available', true)->orderBy('id')->first();

        $option = MenuOption::create([
            'name'             => 'Extra Cheese ' . uniqid(),
            'additional_price' => 15,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        // MenuOption::isMappedForBranch() requires at least one recipe
        // ingredient whose inventory row belongs to the ordering branch, or
        // placeOrder() refuses the option outright as "not available for
        // your selected branch" — see OrderController.php's option_ids loop.
        $inventory = Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'F9 Test Cheese ' . uniqid(),
            'item_code'       => 'F9I-' . strtoupper(substr(uniqid(), -10)),
            'category'        => 'F9 Test',
            'quantity'        => 500,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);

        MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $inventory->id,
            'quantity_used'  => 1,
        ]);

        // Checkout only accepts an item's own add-ons (hardening pass F7).
        $item->options()->attach($option->id);

        return [$item, $option];
    }

    private function cartWithOption(MenuItem $item, MenuOption $option): array
    {
        return [$item->id . '_' . $option->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => $item->price,
            'quantity'     => 1,
            'image'        => $item->image,
            'options'      => [
                ['id' => $option->id],
            ],
        ]];
    }

    public function test_receipt_shows_the_option_name_recorded_at_order_time_not_the_current_one(): void
    {
        [$item, $option] = $this->menuItemWithOption();
        $originalName = $option->name;

        $this->withSession([
            'cart'         => $this->cartWithOption($item, $option),
            'branch_id'    => 1,
            'order_type'   => 'dine_in',
            'table_number' => '9',
        ])->post('/customer/place-order', [
            'order_type'     => 'dine_in',
            'table_number'   => '9',
            'payment_method' => 'cash',
            'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $order = Order::orderByDesc('id')->firstOrFail();

        // Control: the snapshot really was written at order time.
        $this->assertDatabaseHas('order_item_options', [
            'menu_option_id' => $option->id,
            'option_name'    => $originalName,
        ]);

        // The option is renamed AFTER the order was placed — the exact
        // sequence updateMenuOption() enables.
        $renamedName = 'Extra Mozzarella ' . uniqid();
        $option->update(['name' => $renamedName]);

        $html = $this->get('/customer/receipt/' . $order->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            $originalName,
            $html,
            'the receipt must still show the add-on name as it was AT ORDER TIME'
        );
        $this->assertStringNotContainsString(
            $renamedName,
            $html,
            'the receipt rewrote a past add-on label to reflect a LATER rename — the exact bug F9 closes'
        );
    }

    /**
     * AdminController::showReceipt() renders this exact same customer.receipt
     * view for the staff-facing copy (routes/web.php's admin.receipt), so the
     * fix must hold there too, not only for the customer's own page.
     */
    public function test_admin_receipt_view_also_shows_the_snapshot_not_the_renamed_option(): void
    {
        [$item, $option] = $this->menuItemWithOption();
        $originalName = $option->name;

        $this->withSession([
            'cart'         => $this->cartWithOption($item, $option),
            'branch_id'    => 1,
            'order_type'   => 'dine_in',
            'table_number' => '13',
        ])->post('/customer/place-order', [
            'order_type'     => 'dine_in',
            'table_number'   => '13',
            'payment_method' => 'cash',
            'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $order = Order::orderByDesc('id')->firstOrFail();

        $renamedName = 'Extra Mozzarella ' . uniqid();
        $option->update(['name' => $renamedName]);

        $staff = \App\Models\User::where('email', 'simon@peachy.com')->firstOrFail();
        $html = $this->actingAs($staff, 'admin')
            ->get('/admin/receipt/' . $order->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($originalName, $html);
        $this->assertStringNotContainsString($renamedName, $html);
    }
}
