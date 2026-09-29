<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A DATABASE ERROR INSIDE THE ORDER TRANSACTION MUST NEVER REACH THE USER.
 *
 * Illuminate\Database\QueryException extends PDOException, which extends
 * RuntimeException. Both order paths — customer checkout
 * (OrderController::placeOrder) and the walk-in counter
 * (AdminController::storeManualOrder) — caught RuntimeException to show their
 * own deliberate refusals verbatim, so a QueryException landed there too and
 * the raw SQLSTATE, the SQL text and the table names were shown on screen.
 * Proven before the fix: a customer saw "SQLSTATE[23000]: … 1452 … (SQL:
 * insert into `order_items` …".
 *
 * The realistic trigger since sold items can be permanently deleted: both
 * paths look the menu item up BEFORE their transaction, so a permanent delete
 * that commits in between makes the order line INSERT fail its foreign key
 * (1452 — SET NULL only acts on delete, an insert still needs the parent).
 * Simulated deterministically: the item is deleted from inside the
 * OrderItem "creating" event, the instant before that INSERT.
 *
 * Pinned: no order is created, the message is a plain sentence, nothing
 * about SQL/tables/constraints is shown, and the full exception is logged.
 */
class OrderPathDatabaseErrorLeakTest extends TestCase
{
    use DatabaseTransactions;

    private const LEAKS = ['SQLSTATE', 'Integrity constraint', 'foreign key', 'order_items', 'menu_items', 'insert into', 'Connection: mysql', 'pomida_db'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
    }

    private function sellableItem(): MenuItem
    {
        $inv = Inventory::create([
            'branch_id' => 1, 'item_name' => 'OPL Ingredient ' . uniqid(), 'item_code' => 'OPL-' . strtoupper(substr(uniqid(), -8)),
            'quantity' => 50, 'unit' => 'pc', 'low_stock_alert' => 1, 'unit_cost' => 1, 'is_active' => true,
        ]);

        $item = MenuItem::create([
            'category_id' => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id' => 1, 'name' => 'OPL Dish ' . uniqid(), 'price' => 60, 'cost' => 0,
            'is_available' => true, 'display_order' => 0,
        ]);

        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        return $item;
    }

    /** The permanent delete commits the instant before this item's line is inserted. */
    private function deleteJustBeforeTheLineInsert(MenuItem $item): void
    {
        OrderItem::creating(function (OrderItem $line) use ($item) {
            if ((int) $line->menu_item_id === (int) $item->id) {
                DB::table('menu_items')->where('id', $item->id)->delete();
            }
        });
    }

    /**
     * Any other database failure at the same point. Deliberately NOT a fake
     * deadlock: Laravel treats a deadlock message as "MySQL already rolled the
     * whole transaction back" and skips the savepoint rollback inside a nested
     * transaction (this test's own), which a simulated one never did.
     */
    private function failWithANonForeignKeyErrorAtTheLineInsert(MenuItem $item): void
    {
        OrderItem::creating(function (OrderItem $line) use ($item) {
            if ((int) $line->menu_item_id === (int) $item->id) {
                throw new QueryException('mysql', 'insert into `order_items` (`order_id`) values (?)', [1],
                    new \PDOException('SQLSTATE[22003]: Numeric value out of range: 1264 Out of range value for column'));
            }
        });
    }

    private function checkout(MenuItem $item)
    {
        return $this->withSession([
            'cart' => [(string) $item->id => [
                'menu_item_id' => (string) $item->id, 'name' => $item->name, 'price' => 60.0, 'base_price' => 60.0,
                'quantity' => 1, 'image' => null, 'options' => [],
            ]],
            'branch_id' => 1, 'order_type' => 'dine_in', 'table_number' => '7',
        ])->post('/customer/place-order', [
            'order_type' => 'dine_in', 'table_number' => '7', 'payment_method' => 'cash',
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ]);
    }

    private function counter(MenuItem $item)
    {
        $owner = User::create([
            'name' => 'OPL Owner', 'email' => 'opl-owner-' . uniqid() . '@example.test',
            'password' => 'Aa1!aaaaaa', 'role' => 'admin', 'branch_id' => null, 'is_active' => true,
        ]);

        return $this->actingAs($owner, 'admin')->from('/admin/home')->post('/admin/manual-order', [
            'branch_id' => 1, 'order_type' => 'pick_up', 'table_number' => '', 'payment_method' => 'cash',
            'amount_paid' => '500',
            'items' => [$item->id => ['menu_item_id' => (string) $item->id, 'quantity' => '1']],
        ]);
    }

    private function assertNoLeak(string $message): void
    {
        foreach (self::LEAKS as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $message, "the user must never see \"$leak\"");
        }
    }

    public function test_checkout_refuses_an_item_deleted_mid_checkout_without_showing_sql(): void
    {
        $item = $this->sellableItem();
        $this->deleteJustBeforeTheLineInsert($item);
        Log::spy();
        $before = (int) Order::max('id');

        $response = $this->checkout($item);

        $response->assertRedirect();
        $this->assertSame($before, (int) Order::max('id'), 'no order may be created');

        $errors = $response->getSession()->get('errors');
        $this->assertNotNull($errors);
        $message = implode(' | ', $errors->all());
        $this->assertStringContainsString('One of the items in your order is no longer available', $message);
        $this->assertStringContainsString('Nothing was charged', $message);
        $this->assertNoLeak($message);

        Log::shouldHaveReceived('error')->withArgs(
            fn ($msg, $context = []) => $msg === 'Order placement failed (database)' && ($context['exception'] ?? null) instanceof QueryException
        )->once();
    }

    public function test_checkout_shows_the_generic_line_for_any_other_database_error(): void
    {
        $item = $this->sellableItem();
        $this->failWithANonForeignKeyErrorAtTheLineInsert($item);
        $before = (int) Order::max('id');

        $message = implode(' | ', $this->checkout($item)->getSession()->get('errors')->all());

        $this->assertSame($before, (int) Order::max('id'));
        $this->assertStringContainsString('Sorry, we could not place your order just now', $message);
        $this->assertNoLeak($message);
        $this->assertStringNotContainsString('Out of range', $message);
    }

    public function test_the_counter_refuses_an_item_deleted_mid_order_without_showing_sql(): void
    {
        $item = $this->sellableItem();
        $this->deleteJustBeforeTheLineInsert($item);
        Log::spy();
        $before = (int) Order::max('id');

        $response = $this->counter($item);

        $response->assertRedirect('/admin/home');
        $this->assertSame($before, (int) Order::max('id'), 'no order may be created');

        $message = implode(' | ', $response->getSession()->get('errors')->all());
        $this->assertStringContainsString('One of the selected items is no longer available', $message);
        $this->assertStringContainsString('no stock was deducted', $message);
        $this->assertNoLeak($message);

        Log::shouldHaveReceived('error')->withArgs(
            fn ($msg, $context = []) => $msg === 'Manual order creation failed (database)' && ($context['exception'] ?? null) instanceof QueryException
        )->once();
    }

    public function test_the_counter_shows_the_generic_line_for_any_other_database_error(): void
    {
        $item = $this->sellableItem();
        $this->failWithANonForeignKeyErrorAtTheLineInsert($item);
        $before = (int) Order::max('id');

        $message = implode(' | ', $this->counter($item)->getSession()->get('errors')->all());

        $this->assertSame($before, (int) Order::max('id'));
        $this->assertStringContainsString('The manual order could not be saved', $message);
        $this->assertNoLeak($message);
        $this->assertStringNotContainsString('Out of range', $message);
    }

    /** Control: with no failure injected, both paths still place the order. */
    public function test_both_paths_still_place_an_order_when_nothing_goes_wrong(): void
    {
        $before = (int) Order::max('id');
        $this->checkout($this->sellableItem())->assertSessionHasNoErrors();
        $this->assertSame(1, Order::where('id', '>', $before)->count(), 'checkout control');

        $before = (int) Order::max('id');
        $this->counter($this->sellableItem())->assertSessionHasNoErrors();
        $this->assertSame(1, Order::where('id', '>', $before)->count(), 'counter control');
    }
}
