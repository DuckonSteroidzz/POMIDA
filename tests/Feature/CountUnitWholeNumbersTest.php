<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\MenuItemSizeFixtures;
use Tests\TestCase;

/**
 * Count units are whole numbers; measured units may carry decimals (Defect
 * Report 6, item 3: inventory showing "Egg 1.5 pc").
 *
 * The rule lives in ONE place, App\Models\Inventory (COUNT_UNITS,
 * quantityError(), formatQuantity()); these tests pin it at every entry point
 * an ingredient quantity can be typed, plus the display and the warning for
 * rows that already hold a fractional count.
 *
 * Fixtures are minted per test, in their own branch (MenuItemSizeFixtures).
 */
class CountUnitWholeNumbersTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    private function inv(int $branchId, string $unit, float $quantity = 100, string $name = 'CUWN Item'): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => $name . ' ' . uniqid(),
            'item_code'       => 'CUWN-' . strtoupper(substr(uniqid(), -10)),
            'quantity'        => $quantity,
            'unit'            => $unit,
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);
    }

    private function asOwner(int $branchId)
    {
        return $this->actingAs($this->sizeOwner(), 'admin')->withSession(['selected_branch_id' => $branchId]);
    }

    // ══════════ the rule itself ══════════

    public function test_count_and_measured_units_are_told_apart_in_one_place(): void
    {
        foreach (['pc', 'pcs', 'pack', 'bottle', 'can', 'PC', ' Can ', 'piece', 'box', 'tray'] as $unit) {
            $this->assertTrue(Inventory::isCountUnit($unit), "$unit should be a count unit");
            $this->assertSame('1', Inventory::quantityStep($unit));
        }
        foreach (['g', 'kg', 'ml', 'L', 'oz', 'lb', 'grams', '', null] as $unit) {
            $this->assertFalse(Inventory::isCountUnit($unit), var_export($unit, true) . ' should be measured');
            $this->assertSame('0.001', Inventory::quantityStep($unit));
        }

        // Every unit the dropdown offers is classified one way or the other.
        $this->assertSame(
            ['pc', 'pcs', 'pack', 'bottle', 'can'],
            array_values(array_filter(Inventory::ALLOWED_UNITS, fn ($u) => Inventory::isCountUnit($u)))
        );
    }

    public function test_quantity_error_messages(): void
    {
        $this->assertNull(Inventory::quantityError('pc', 6));
        $this->assertNull(Inventory::quantityError('pc', '6.000'));
        $this->assertNull(Inventory::quantityError('pc', '6.0'));
        $this->assertNotNull(Inventory::quantityError('pc', 1.5));
        $this->assertNotNull(Inventory::quantityError('can', '0.001'));
        $this->assertStringContainsString('whole number', Inventory::quantityError('pc', 1.5));

        $this->assertNull(Inventory::quantityError('g', 1.5));
        $this->assertNull(Inventory::quantityError('kg', '0.001'));
        $this->assertNotNull(Inventory::quantityError('g', 1.2345), 'more than 3 decimals');
        $this->assertStringContainsString('3 decimal', Inventory::quantityError('g', 1.2345));
    }

    public function test_format_quantity_drops_trailing_zeros(): void
    {
        $this->assertSame('6', Inventory::formatQuantity('6.000'));
        $this->assertSame('1.5', Inventory::formatQuantity('1.500'));
        $this->assertSame('0.25', Inventory::formatQuantity(0.25));
        $this->assertSame('0', Inventory::formatQuantity('0.000'));
    }

    // ══════════ add / edit inventory item ══════════

    private function itemPayload(string $unit, $quantity, array $extra = []): array
    {
        return array_merge([
            'item_name'       => 'CUWN New ' . uniqid(),
            'item_code'       => 'CUWN-' . strtoupper(substr(uniqid(), -9)),
            'quantity'        => $quantity,
            'unit'            => $unit,
            'low_stock_alert' => 2,
        ], $extra);
    }

    public function test_adding_an_item_in_a_count_unit_refuses_a_fraction_and_creates_nothing(): void
    {
        $branch = $this->sizeBranch('A');
        $payload = $this->itemPayload('pc', '1.5');

        $this->asOwner($branch->id)->from(route('admin.inventory'))
            ->post(route('admin.inventory.store'), $payload)
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseMissing('inventory', ['item_code' => $payload['item_code']]);
    }

    public function test_adding_an_item_accepts_whole_counts_and_measured_decimals(): void
    {
        $branch = $this->sizeBranch('B');
        $eggs = $this->itemPayload('pc', '30');
        $flour = $this->itemPayload('kg', '2.5');

        $this->asOwner($branch->id)->post(route('admin.inventory.store'), $eggs)->assertSessionHasNoErrors();
        $this->asOwner($branch->id)->post(route('admin.inventory.store'), $flour)->assertSessionHasNoErrors();

        $this->assertEquals(30, Inventory::where('item_code', $eggs['item_code'])->value('quantity'));
        $this->assertEquals(2.5, Inventory::where('item_code', $flour['item_code'])->value('quantity'));
    }

    public function test_a_fractional_low_stock_alert_on_a_count_unit_is_refused(): void
    {
        $branch = $this->sizeBranch('C');
        $payload = $this->itemPayload('can', '24', ['low_stock_alert' => '2.5']);

        $this->asOwner($branch->id)->from(route('admin.inventory'))
            ->post(route('admin.inventory.store'), $payload)
            ->assertSessionHasErrors('low_stock_alert');
    }

    public function test_editing_is_judged_against_the_unit_being_saved(): void
    {
        $branch = $this->sizeBranch('D');
        $item = $this->inv($branch->id, 'g', 100);

        $base = [
            'item_name' => $item->item_name, 'item_code' => $item->item_code,
            'low_stock_alert' => 1,
        ];

        // g 1.5 is fine; switching the same row to pc with 1.5 is not.
        $this->asOwner($branch->id)->put(route('admin.inventory.update', $item->id), $base + ['unit' => 'g', 'quantity' => '1.5'])
            ->assertSessionHasNoErrors();
        $this->assertEquals(1.5, $item->fresh()->quantity);

        $this->asOwner($branch->id)->from(route('admin.inventory'))
            ->put(route('admin.inventory.update', $item->id), $base + ['unit' => 'pc', 'quantity' => '1.5'])
            ->assertSessionHasErrors('quantity');
        $this->assertSame('g', $item->fresh()->unit, 'the refused edit changed the row');
    }

    // ══════════ stock in / stock out ══════════

    public function test_stock_in_and_out_in_a_count_unit_take_whole_numbers_only(): void
    {
        $branch = $this->sizeBranch('E');
        $eggs = $this->inv($branch->id, 'pc', 10);

        $this->asOwner($branch->id)->from(route('admin.inventory'))
            ->post(route('admin.inventory.stock-in', $eggs->id), ['amount' => '1.5'])
            ->assertSessionHasErrors('amount');
        $this->asOwner($branch->id)->from(route('admin.inventory'))
            ->post(route('admin.inventory.stock-out', $eggs->id), ['amount' => '0.5'])
            ->assertSessionHasErrors('amount');
        $this->assertEquals(10, $eggs->fresh()->quantity);
        $this->assertDatabaseMissing('stock_movements', ['inventory_id' => $eggs->id]);

        $this->asOwner($branch->id)->post(route('admin.inventory.stock-in', $eggs->id), ['amount' => '6'])
            ->assertSessionHasNoErrors();
        $this->assertEquals(16, $eggs->fresh()->quantity);
    }

    public function test_the_stock_message_reads_without_trailing_zeros(): void
    {
        $branch = $this->sizeBranch('F');
        $eggs = $this->inv($branch->id, 'pc', 10);

        $this->asOwner($branch->id)->post(route('admin.inventory.stock-in', $eggs->id), ['amount' => '6'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '+6 pc') && str_contains($m, 'New stock: 16') && ! str_contains($m, '16.000'));
    }

    public function test_stock_in_and_out_in_a_measured_unit_keep_their_decimals(): void
    {
        $branch = $this->sizeBranch('G');
        $milk = $this->inv($branch->id, 'L', 10);

        $this->asOwner($branch->id)->post(route('admin.inventory.stock-in', $milk->id), ['amount' => '0.25'])->assertSessionHasNoErrors();
        $this->asOwner($branch->id)->post(route('admin.inventory.stock-out', $milk->id), ['amount' => '0.125'])->assertSessionHasNoErrors();
        $this->assertEquals(10.125, $milk->fresh()->quantity);

        $this->asOwner($branch->id)->from(route('admin.inventory'))
            ->post(route('admin.inventory.stock-in', $milk->id), ['amount' => '0.12345'])
            ->assertSessionHasErrors('amount');
    }

    // ══════════ recipe quantities ══════════

    public function test_menu_item_recipe_line_in_a_count_unit_must_be_whole(): void
    {
        $branch = $this->sizeBranch('H');
        $item = $this->sizeItem($branch->id, 'CUWN Omelette');
        $egg = $this->inv($branch->id, 'pc');
        $flour = $this->inv($branch->id, 'g');
        $url = route('admin.menu-items.ingredients.add', $item->id);

        $this->asOwner($branch->id)->postJson($url, ['inventory_id' => $egg->id, 'quantity_used' => '0.5'])
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(0, DB::table('menu_item_ingredients')->where('menu_item_id', $item->id)->count());

        $this->asOwner($branch->id)->postJson($url, ['inventory_id' => $egg->id, 'quantity_used' => '2'])->assertOk();
        $this->asOwner($branch->id)->postJson($url, ['inventory_id' => $flour->id, 'quantity_used' => '37.5'])->assertOk();
        $this->assertSame(2, DB::table('menu_item_ingredients')->where('menu_item_id', $item->id)->count());
    }

    public function test_size_recipe_line_in_a_count_unit_must_be_whole(): void
    {
        $branch = $this->sizeBranch('I');
        $item = $this->sizeItem($branch->id, 'CUWN Sized');
        [$regular] = $this->enableSizes($item);
        $egg = $this->inv($branch->id, 'pc');
        $url = route('admin.menu-items.sizes.ingredients.add', [$item->id, $regular->id]);

        $this->asOwner($branch->id)->postJson($url, ['inventory_id' => $egg->id, 'quantity_used' => '1.5'])
            ->assertStatus(422);
        $this->assertSame(0, DB::table('menu_item_size_ingredients')->where('menu_item_size_id', $regular->id)->count());

        $this->asOwner($branch->id)->postJson($url, ['inventory_id' => $egg->id, 'quantity_used' => '1'])->assertOk();
    }

    public function test_add_on_option_recipe_line_in_a_count_unit_must_be_whole(): void
    {
        $branch = $this->sizeBranch('J');
        $option = MenuOption::create(['name' => 'CUWN Option ' . uniqid(), 'additional_price' => 5, 'is_active' => true, 'display_order' => 0]);
        $egg = $this->inv($branch->id, 'pc');
        $url = route('admin.menu-options.ingredients.add', $option->id);

        $this->asOwner($branch->id)->postJson($url, ['inventory_id' => $egg->id, 'quantity_used' => '0.5'])
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(0, DB::table('menu_option_ingredients')->where('menu_option_id', $option->id)->count());

        $this->asOwner($branch->id)->postJson($url, ['inventory_id' => $egg->id, 'quantity_used' => '1'])->assertOk();
    }

    public function test_add_form_recipe_rows_are_checked_and_nothing_is_saved_on_a_fraction(): void
    {
        $branch = $this->sizeBranch('K');
        $egg = $this->inv($branch->id, 'pc');
        $name = 'CUWN Cake ' . uniqid();

        $post = fn (string $qty) => $this->asOwner($branch->id)->from(route('admin.menu-items'))
            ->post(route('admin.new-menu-item.post'), [
                'category_id' => $this->sizeCategory()->id,
                'name' => $name,
                'price' => 50,
                'ingredients' => [['inventory_id' => $egg->id, 'quantity_used' => $qty]],
            ]);

        $post('0.5')->assertSessionHasErrors('ingredients');
        $this->assertSame(0, MenuItem::where('name', $name)->count(), 'a refused add left an item behind');

        $post('3')->assertSessionHasNoErrors();
        $this->assertSame(1, MenuItem::where('name', $name)->count());
    }

    // ══════════ display ══════════

    public function test_the_inventory_page_shows_counts_without_decimals_and_drives_the_input_step(): void
    {
        $branch = $this->sizeBranch('L');
        $egg = $this->inv($branch->id, 'pc', 6, 'CUWN Egg');
        $flour = $this->inv($branch->id, 'kg', 2.5, 'CUWN Flour');

        $html = $this->asOwner($branch->id)->get(route('admin.inventory'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/6\.000/', $html, 'a count printed with trailing zeros');
        $this->assertMatchesRegularExpression('/iv-num[^>]*>\s*6\s*(<|\n)/', $html);
        $this->assertStringContainsString('2.5', $html);
        $this->assertStringContainsString('POMIDA_COUNT_UNITS', $html);
        $this->assertStringContainsString('"pc"', $html);
        $this->assertStringContainsString('id="stockAmount"', $html);
    }

    public function test_a_row_that_already_holds_a_fraction_gets_a_warning_and_is_left_as_stored(): void
    {
        $branch = $this->sizeBranch('M');
        $bad = $this->inv($branch->id, 'pc', 1.5, 'CUWN BadEgg');
        $okCount = $this->inv($branch->id, 'pc', 6, 'CUWN OkEgg');
        $okMeasured = $this->inv($branch->id, 'g', 1.5, 'CUWN OkFlour');

        $html = $this->asOwner($branch->id)->get(route('admin.inventory'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'iv-frac-warn"'), 'exactly one row should carry the warning');
        $this->assertStringContainsString('Not a whole number', $html);
        $this->assertMatchesRegularExpression('/' . preg_quote($bad->item_name, '/') . '.*?iv-frac-warn/s', $html);

        // Viewing it never rewrites it.
        $this->assertSame('1.500', DB::table('inventory')->where('id', $bad->id)->value('quantity'));

        $this->assertTrue($bad->hasFractionalCountQuantity());
        $this->assertFalse($okCount->hasFractionalCountQuantity());
        $this->assertFalse($okMeasured->hasFractionalCountQuantity());
    }

    public function test_an_unrelated_edit_of_an_old_fractional_legacy_link_is_not_blocked(): void
    {
        $branch = $this->sizeBranch('N');
        $egg = $this->inv($branch->id, 'pc');
        $item = $this->sizeItem($branch->id, 'CUWN Legacy');
        $item->forceFill(['inventory_item_id' => $egg->id, 'inventory_amount_used' => 0.5])->save();

        $payload = [
            'category_id' => $item->category_id, 'name' => 'CUWN Legacy renamed', 'price' => 90,
            'inventory_item_id' => $egg->id, 'inventory_amount_used' => '0.5',
        ];
        $this->asOwner($branch->id)->put(route('admin.menu-items.update', $item->id), $payload)->assertSessionHasNoErrors();
        $this->assertSame('CUWN Legacy renamed', $item->fresh()->name);

        // CHANGING it to another fraction is refused.
        $this->asOwner($branch->id)->from(route('admin.menu-items'))
            ->put(route('admin.menu-items.update', $item->id), array_merge($payload, ['inventory_amount_used' => '1.5']))
            ->assertSessionHasErrors('inventory_amount_used');
    }
}
