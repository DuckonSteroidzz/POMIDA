<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\User;
use App\Support\InputLimits;
use App\Support\StatFigure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * QA Nov 5, both concerns.
 *
 * (1) A big peso amount must stay inside its stat card at every width: the
 *     pages hand the figure its character count and a full-value tooltip, and
 *     the layout's .stat-fit / .stat-fig rule does the fitting.
 * (2) Every number and ID box has a realistic maximum, server-side (this file)
 *     and as the HTML max/maxlength on the same input (InputLimits is the one
 *     source for both), so a 47-digit value is a message on the box, not a 500.
 *
 * Every row is created inside DatabaseTransactions and carries the NIL prefix.
 */
class NumericInputLimitsAndStatCardsTest extends TestCase
{
    use DatabaseTransactions;

    private const FORTY_SEVEN = '11111111111111111111111111111111111111111111111';

    // ── helpers ─────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::create([
            'name'      => 'NIL Admin ' . uniqid(),
            'email'     => 'nil-admin-' . uniqid() . '@example.test',
            'password'  => bcrypt('AdminReal!Pass1'),
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function branch(): Branch
    {
        return Branch::create([
            'name'      => 'NIL Branch ' . uniqid(),
            'code'      => 'NL-' . strtoupper(substr(uniqid(), -6)),
            'address'   => '123 Test Street',
            'is_active' => true,
        ]);
    }

    private function inventory(int $branchId, float $quantity = 10, float $unitCost = 1): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => 'NIL Ingredient ' . uniqid(),
            'item_code'       => 'NIL-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => $quantity,
            'unit'            => 'pc',
            'low_stock_alert' => 1,
            'unit_cost'       => $unitCost,
            'is_active'       => true,
        ]);
    }

    private function menuItem(int $branchId): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => 'NIL Item ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function asAdmin(int $branchId)
    {
        return $this->actingAs($this->admin(), 'admin')->withSession(['selected_branch_id' => $branchId]);
    }

    // ── Concern 1: stat cards ───────────────────────────────────────────

    public function test_stat_figure_gives_the_full_value_as_a_tooltip_and_the_character_count(): void
    {
        $attrs = StatFigure::attrs('₱999,999,999,999.00');

        $this->assertStringContainsString('title="₱999,999,999,999.00"', $attrs);
        $this->assertStringContainsString('style="--n:19"', $attrs, 'mb_strlen, so the peso sign counts as one');
        $this->assertStringContainsString('title="&lt;b&gt;"', StatFigure::attrs('<b>'), 'the tooltip is escaped');
    }

    public function test_the_layout_ships_the_font_fit_rule_every_stat_card_relies_on(): void
    {
        $html = $this->asAdmin($this->branch()->id)->get(route('admin.inventory'))->assertOk()->getContent();

        $this->assertStringContainsString('.stat-fit { container-type: inline-size;', $html);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $html);
        $this->assertStringContainsString('calc(100cqw / (var(--n, 6) * 0.64))', $html);
    }

    public function test_inventory_total_stock_value_is_fitted_and_carries_the_full_value(): void
    {
        $branch = $this->branch();
        // 9,999 x ₱99,999,999.00 is a 12-digit peso figure on the Inventory page.
        $this->inventory($branch->id, 9999, 99999999);

        $total = number_format(9999 * 99999999, 2);
        $html = $this->asAdmin($branch->id)->get(route('admin.inventory'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'class="iv-stat-num stat-fig" title="₱' . $total . '" style="--n:' . mb_strlen('₱' . $total) . '">₱' . $total . '</span>',
            $html
        );
        $this->assertSame(5, substr_count($html, 'class="iv-stat stat-fit'), 'all five tiles are size containers');
    }

    public function test_every_other_admin_stat_page_uses_the_shared_fitting(): void
    {
        $branch = $this->branch();

        foreach ([
            'admin.summary'   => 9,
            'admin.analytics' => 6,
            'admin.vouchers'  => 4,
            'admin.ads'       => 4,
            'admin.branches'  => 3,
        ] as $routeName => $tiles) {
            $html = $this->asAdmin($branch->id)->get(route($routeName))->assertOk()->getContent();

            $this->assertGreaterThanOrEqual($tiles, substr_count($html, 'stat-fit'), "{$routeName}: every tile is a size container");
            $this->assertGreaterThanOrEqual($tiles, substr_count($html, 'stat-fig"'), "{$routeName}: every figure is fitted");
        }
    }

    public function test_money_tiles_on_summary_and_analytics_carry_the_full_value(): void
    {
        $branch = $this->branch();

        $summary = $this->asAdmin($branch->id)->get(route('admin.summary'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/class="kpi-value stat-fig" title="₱[\d,]+\.\d\d" style="--n:\d+">/', $summary);

        $analytics = $this->asAdmin($branch->id)->get(route('admin.analytics'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/class="an-kpi-num stat-fig" title="₱[\d,]+\.\d\d" style="--n:\d+">/', $analytics);
        $this->assertStringNotContainsString('font-size:1.3rem; font-weight:700; color:#333;', $analytics, 'no tile keeps a fixed font size');
    }

    // ── Concern 2: limits ───────────────────────────────────────────────

    public function test_inventory_numbers_over_the_limit_are_a_message_not_a_500(): void
    {
        $branch = $this->branch();

        $this->asAdmin($branch->id)
            ->post(route('admin.inventory.store'), [
                'item_name' => 'NIL Flour', 'item_code' => 'NIL-' . strtoupper(substr(uniqid(), -8)), 'unit' => 'kg',
                'quantity' => self::FORTY_SEVEN, 'low_stock_alert' => self::FORTY_SEVEN, 'unit_cost' => self::FORTY_SEVEN,
            ])
            ->assertSessionHasErrors(['quantity', 'low_stock_alert', 'unit_cost']);

        // One past each limit.
        $this->asAdmin($branch->id)
            ->post(route('admin.inventory.store'), [
                'item_name' => 'NIL Flour', 'item_code' => 'NIL-' . strtoupper(substr(uniqid(), -8)), 'unit' => 'kg',
                'quantity' => '10000000', 'low_stock_alert' => '10000000', 'unit_cost' => '1000000',
            ])
            ->assertSessionHasErrors(['quantity', 'low_stock_alert', 'unit_cost']);

        // Exactly the limit is accepted.
        $this->asAdmin($branch->id)
            ->post(route('admin.inventory.store'), [
                'item_name' => 'NIL Flour', 'item_code' => 'NIL-' . strtoupper(substr(uniqid(), -8)), 'unit' => 'kg',
                'quantity' => InputLimits::STOCK_MAX, 'low_stock_alert' => InputLimits::STOCK_MAX, 'unit_cost' => InputLimits::UNIT_COST_MAX,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_stock_in_and_out_amounts_have_a_limit(): void
    {
        $branch = $this->branch();
        $inv = $this->inventory($branch->id);

        foreach (['admin.inventory.stock-in', 'admin.inventory.stock-out'] as $routeName) {
            $this->asAdmin($branch->id)
                ->post(route($routeName, ['id' => $inv->id]), ['amount' => self::FORTY_SEVEN])
                ->assertSessionHasErrors('amount');
        }
    }

    public function test_menu_item_price_and_cost_have_a_limit(): void
    {
        $branch = $this->branch();
        $item = $this->menuItem($branch->id);

        $this->asAdmin($branch->id)
            ->put(route('admin.menu-items.update', ['id' => $item->id]), [
                'category_id' => $item->category_id, 'name' => $item->name,
                'price' => self::FORTY_SEVEN, 'cost' => self::FORTY_SEVEN,
            ])
            ->assertSessionHasErrors(['price', 'cost']);

        $this->asAdmin($branch->id)
            ->put(route('admin.menu-items.update', ['id' => $item->id]), [
                'category_id' => $item->category_id, 'name' => $item->name,
                'price' => '100000', 'cost' => '100000',
            ])
            ->assertSessionHasErrors(['price', 'cost']);

        $this->asAdmin($branch->id)
            ->put(route('admin.menu-items.update', ['id' => $item->id]), [
                'category_id' => $item->category_id, 'name' => $item->name,
                'price' => InputLimits::PRICE_MAX, 'cost' => InputLimits::PRICE_MAX,
            ])
            ->assertSessionDoesntHaveErrors(['price', 'cost']);
    }

    public function test_recipe_line_quantity_has_a_limit(): void
    {
        $branch = $this->branch();
        $item = $this->menuItem($branch->id);
        $inv = $this->inventory($branch->id);

        $this->asAdmin($branch->id)
            ->post(route('admin.menu-items.ingredients.add', ['menuItem' => $item->id]), [
                'inventory_id' => $inv->id, 'quantity_used' => self::FORTY_SEVEN,
            ])
            ->assertSessionHasErrors('quantity_used');
    }

    public function test_voucher_values_have_a_limit_and_a_percent_voucher_cannot_exceed_100(): void
    {
        $branch = $this->branch();
        $base = ['description' => 'NIL', 'max_uses' => 10, 'minimum_order' => 0, 'points_required' => 0];

        $this->asAdmin($branch->id)
            ->post(route('admin.vouchers.store'), $base + ['code' => 'NIL' . strtoupper(substr(uniqid(), -6)), 'discount_type' => 'percent', 'discount_value' => 150])
            ->assertSessionHasErrors('discount_value');

        $this->asAdmin($branch->id)
            ->post(route('admin.vouchers.store'), $base + ['code' => 'NIL' . strtoupper(substr(uniqid(), -6)), 'discount_type' => 'percent', 'discount_value' => 100])
            ->assertSessionDoesntHaveErrors('discount_value');

        $this->asAdmin($branch->id)
            ->post(route('admin.vouchers.store'), $base + ['code' => 'NIL' . strtoupper(substr(uniqid(), -6)), 'discount_type' => 'fixed', 'discount_value' => self::FORTY_SEVEN])
            ->assertSessionHasErrors('discount_value');

        $this->asAdmin($branch->id)
            ->post(route('admin.vouchers.store'), [
                'code' => 'NIL' . strtoupper(substr(uniqid(), -6)), 'discount_type' => 'fixed', 'discount_value' => 50,
                'max_uses' => '100001', 'minimum_order' => '100000', 'points_required' => '100001',
            ])
            ->assertSessionHasErrors(['max_uses', 'minimum_order', 'points_required']);
    }

    public function test_counter_order_amounts_quantities_and_id_numbers_have_a_limit(): void
    {
        $branch = $this->branch();

        $this->asAdmin($branch->id)
            ->post(route('admin.manual-order.store'), [
                'order_type' => 'dine_in', 'payment_method' => 'cash',
                'table_number' => '12345678901',
                'amount_paid' => self::FORTY_SEVEN,
                'items' => [['menu_item_id' => 1, 'quantity' => 100]],
                'discount_type' => 'pwd',
                'discount_beneficiary_id' => str_repeat('1', InputLimits::ID_NUMBER_MAX + 1),
            ])
            ->assertSessionHasErrors(['table_number', 'amount_paid', 'items.0.quantity', 'discount_beneficiary_id']);

        $this->asAdmin($branch->id)
            ->post(route('admin.manual-order.store'), ['payment_method' => 'cash', 'amount_paid' => '1000000'])
            ->assertSessionHasErrors('amount_paid');
    }

    public function test_voucher_preview_subtotal_has_a_limit(): void
    {
        $this->asAdmin($this->branch()->id)
            ->postJson(route('admin.manual-order.voucher-preview'), ['code' => 'ANY', 'subtotal' => self::FORTY_SEVEN])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subtotal');
    }

    public function test_staff_contact_number_is_capped_at_the_column_width(): void
    {
        $branch = $this->branch();
        $staff = User::create([
            'name' => 'NIL Staff ' . uniqid(), 'email' => 'nil-staff-' . uniqid() . '@example.test',
            'password' => bcrypt('StaffReal!Pass1'), 'role' => 'staff', 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $payload = ['name' => $staff->name, 'email' => $staff->email, 'branch_id' => $branch->id, 'role' => 'staff'];

        // users.contact_number is varchar(20); the old max:30 let 21-30
        // characters through validation and failed at the INSERT.
        $this->asAdmin($branch->id)
            ->put(route('admin.users.update', ['id' => $staff->id]), $payload + ['contact_number' => str_repeat('9', 21)])
            ->assertSessionHasErrors('contact_number');

        $this->asAdmin($branch->id)
            ->put(route('admin.users.update', ['id' => $staff->id]), $payload + ['contact_number' => str_repeat('9', 20)])
            ->assertSessionDoesntHaveErrors('contact_number');
    }

    public function test_the_cart_caps_one_line_at_the_same_99_as_the_item_page(): void
    {
        $branch = $this->branch();
        $item = $this->menuItem($branch->id);
        $inv = $this->inventory($branch->id, 100000);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        $key = (string) $item->id;
        $session = [
            'cart' => [$key => ['menu_item_id' => $key, 'name' => $item->name, 'price' => 100.0, 'quantity' => 1, 'options' => []]],
            'branch_id' => $branch->id, 'order_type' => 'pick_up',
        ];

        $this->withSession($session)
            ->putJson('/customer/cart/update/' . $key, ['quantity' => 5000])
            ->assertOk()
            ->assertJsonPath('lines.' . $key . '.quantity', InputLimits::ORDER_QTY_MAX);

        $this->assertSame(InputLimits::ORDER_QTY_MAX, (int) session('cart')[$key]['quantity']);
    }

    public function test_the_branches_table_scrolls_inside_its_own_frame_not_the_page(): void
    {
        $html = $this->asAdmin($this->branch()->id)->get(route('admin.branches'))->assertOk()->getContent();

        // .bm-sr (the visually hidden "Actions" header) is position:absolute;
        // unless its scroll frame is the containing block it escapes the frame
        // and widens the whole page at 768-1100px.
        $this->assertStringContainsString('.bm .bm-sr{position:absolute;', $html);
        $this->assertStringContainsString('.bm .bm-table-wrap{overflow-x:auto;position:relative}', $html);
    }

    public function test_the_html_inputs_carry_the_same_limits_as_the_server(): void
    {
        $branch = $this->branch();

        $inventory = $this->asAdmin($branch->id)->get(route('admin.inventory'))->assertOk()->getContent();
        $this->assertStringContainsString('name="quantity" id="itemQuantity" class="iv-input" step="0.001" min="0" max="' . InputLimits::STOCK_MAX . '"', $inventory);
        $this->assertStringContainsString('name="unit_cost" id="itemCost" class="iv-input" step="0.01" min="0" max="' . InputLimits::UNIT_COST_MAX . '"', $inventory);
        $this->assertStringContainsString('id="stockAmount" class="iv-input" step="0.001" min="0.001" max="' . InputLimits::STOCK_MAX . '"', $inventory);

        $vouchers = $this->asAdmin($branch->id)->get(route('admin.vouchers'))->assertOk()->getContent();
        $this->assertStringContainsString('id="vchMax" name="max_uses" class="form-control-custom" min="1" max="' . InputLimits::VOUCHER_COUNT_MAX . '"', $vouchers);
        $this->assertStringContainsString('id="vchValue" name="discount_value" class="form-control-custom" min="1" max="' . InputLimits::VOUCHER_FIXED_MAX . '"', $vouchers);

        $menu = $this->asAdmin($branch->id)->get(route('admin.menu-items'))->assertOk()->getContent();
        $this->assertStringContainsString('name="price" id="itemPrice" class="form-control-custom" step="0.01" min="0" max="' . InputLimits::PRICE_MAX . '"', $menu);
        $this->assertStringNotContainsString('max="99999999.99"', $menu, 'the old 8-digit price ceiling is gone');
    }
}
