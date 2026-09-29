<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration 2026_09_24_000000_nullable_menu_item_id_on_order_items.
 *
 * The schema it leaves behind, read from information_schema, and the one
 * thing about it that must never go wrong: down() refuses BEFORE touching
 * anything once a line has lost its link. MySQL DDL is not transactional —
 * proven on MariaDB 10.4 during the investigation, a MODIFY ... NOT NULL over
 * NULL rows fails AFTER dropForeign() has already run, leaving order_items
 * with no foreign key at all.
 *
 * down() is never allowed to reach real DDL in this test: a beforeExecuting
 * hook throws on any ALTER/DROP/CREATE, so even a broken guard cannot alter
 * the testing database — it fails the test instead.
 */
class OrderItemsMenuItemSetNullMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRATION = '2026_09_24_000000_nullable_menu_item_id_on_order_items.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
    }

    private function migration(): object
    {
        return require database_path('migrations/' . self::MIGRATION);
    }

    private function ddlTripwire(): void
    {
        DB::connection()->beforeExecuting(function (string $query) {
            if (preg_match('/^\s*(alter|drop|create|rename|truncate)\b/i', $query)) {
                throw new \LogicException('DDL reached the database: ' . $query);
            }
        });
    }

    private function line(?int $menuItemId, bool $estimated = false): OrderItem
    {
        $order = Order::create([
            'order_number' => 'OIM-' . strtoupper(substr(uniqid(), -8)), 'branch_id' => 1, 'type' => 'pick_up',
            'status' => 'completed', 'subtotal' => 10, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => 10,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);

        return OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $menuItemId, 'item_name' => 'OIM line', 'item_price' => 10,
            'quantity' => 1, 'subtotal' => 10, 'ingredient_cost' => $estimated ? 2.5 : 0,
            'ingredient_cost_estimated' => $estimated,
        ]);
    }

    public function test_menu_item_id_is_nullable_with_on_delete_set_null_on_the_same_index(): void
    {
        $db = DB::connection()->getDatabaseName();

        $column = DB::selectOne(
            "SELECT IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'menu_item_id'",
            [$db]
        );
        $this->assertSame('YES', $column->IS_NULLABLE);
        $this->assertSame('bigint(20) unsigned', $column->COLUMN_TYPE);

        $rule = DB::selectOne(
            "SELECT DELETE_RULE, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME = 'order_items_menu_item_id_foreign'",
            [$db]
        );
        $this->assertSame('SET NULL', $rule->DELETE_RULE);
        $this->assertSame('menu_items', $rule->REFERENCED_TABLE_NAME);

        $indexes = collect(DB::select(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'menu_item_id'",
            [$db]
        ))->pluck('INDEX_NAME')->all();
        $this->assertSame(['order_items_menu_item_id_foreign'], $indexes, 'the original index is reused, not duplicated');
    }

    public function test_the_estimated_flag_exists_and_defaults_to_false(): void
    {
        $flag = DB::selectOne(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'ingredient_cost_estimated'",
            [DB::connection()->getDatabaseName()]
        );
        $this->assertNotNull($flag);
        $this->assertSame('tinyint(1)', $flag->COLUMN_TYPE);
        $this->assertSame('NO', $flag->IS_NULLABLE);
        $this->assertSame('0', (string) $flag->COLUMN_DEFAULT);

        $order = Order::create([
            'order_number' => 'OIM-' . strtoupper(substr(uniqid(), -8)), 'branch_id' => 1, 'type' => 'pick_up',
            'status' => 'completed', 'subtotal' => 10, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => 10,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);
        $id = DB::table('order_items')->insertGetId([
            'order_id' => $order->id, 'menu_item_id' => null, 'item_name' => 'OIM default', 'item_price' => 10,
            'quantity' => 1, 'subtotal' => 10,
        ]);
        $this->assertSame(0, (int) DB::table('order_items')->where('id', $id)->value('ingredient_cost_estimated'));
    }

    public function test_the_guard_passes_when_no_line_has_lost_its_link(): void
    {
        $this->assertSame(0, DB::table('order_items')->whereNull('menu_item_id')->count(), 'setup: the testing DB holds no detached lines');
        $this->assertSame(0, DB::table('order_items')->where('ingredient_cost_estimated', true)->count(), 'setup: and no estimated ones');

        $this->migration()->refuseIfRollbackWouldLoseHistory();
        $this->addToAssertionCount(1);
    }

    public function test_down_refuses_before_any_ddl_while_a_line_has_lost_its_link(): void
    {
        $this->line(null);
        $migration = $this->migration();
        $this->ddlTripwire();

        try {
            $migration->down();
            $this->fail('down() must refuse while a detached line exists');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to roll back', $e->getMessage());
            $this->assertStringContainsString('1 order line(s) belong to permanently deleted menu items', $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        $this->assertSame(
            'SET NULL',
            DB::selectOne("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'order_items_menu_item_id_foreign'")->DELETE_RULE,
            'the foreign key is untouched'
        );
    }

    public function test_down_also_refuses_while_a_line_carries_an_estimated_cost(): void
    {
        $item = MenuItem::create([
            'category_id' => Category::value('id'), 'branch_id' => 1, 'name' => 'OIM item ' . uniqid(),
            'price' => 10, 'is_available' => true, 'display_order' => 0,
        ]);
        $this->line($item->id, true);
        $migration = $this->migration();
        $this->ddlTripwire();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('1 carry an estimated ingredient cost');

        $migration->down();
    }
}
