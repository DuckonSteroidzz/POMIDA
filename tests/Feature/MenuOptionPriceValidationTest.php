<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Menu Option price validation (2026-09-23).
 *
 * INVESTIGATION
 * -------------
 * storeMenuOption() and updateMenuOption() (App\Http\Controllers\Admin\
 * AdminController) both used 'price' => 'nullable|numeric|min:0' — the Add
 * New Option form (resources/views/admin/menu-options.blade.php) had no
 * `required` attribute and defaulted the input to value="0", so submitting
 * with the field blank OR left at 0 both saved successfully. Reproduced
 * before this change: POST with no 'price' key at all, and POST with
 * 'price' => 0, both redirected with no session errors and created a row
 * with additional_price = 0.00.
 *
 * additional_price is decimal(10,2) (migration
 * 2026_04_27_031130_create_menu_options_table), cast 'decimal:2' on the
 * model — hence max:99999999.99 alongside gt:0 on the tightened rule, so a
 * value the column cannot represent is rejected before it reaches the DB
 * rather than being silently truncated.
 *
 * These two routes are the ONLY server-side writers of additional_price from
 * user input (grepped resources/views and routes/web.php for
 * menu-options.post / menu-options.update — one Add form, one Edit form,
 * nothing else posts to either). MenuOption::create() called directly by
 * other tests/seeders bypasses this validation by construction, which is
 * exactly how pre-existing $0 options stay in the DB untouched by this
 * change (see test_an_existing_zero_priced_option_remains_visible_and_free_below).
 *
 * SCOPE: only the 'price' rule changed, from 'nullable|numeric|min:0' to
 * 'required|numeric|gt:0|max:99999999.99'. 'name' is untested here — see
 * MenuOptionEditTest for name-validation and assignment-safety coverage.
 */
class MenuOptionPriceValidationTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->first();
    }

    private function freshOption(string $name = 'Test Option', float $price = 5): MenuOption
    {
        return MenuOption::create([
            'name' => $name,
            'additional_price' => $price,
            'is_active' => true,
            'display_order' => 0,
        ]);
    }

    // ══════════ create: accepted ══════════

    /**
     * menu_options has no unique index on name (duplicates are normal — see
     * the option-branch-ui-clarity notes), and pomida_db_testing is never
     * wiped between runs, so looking a just-created row up by name risks
     * matching an unrelated pre-existing row instead. Every "create
     * succeeds" case below reads back the newest row by id instead.
     */
    private function newestOption(): MenuOption
    {
        return MenuOption::orderByDesc('id')->firstOrFail();
    }

    public function test_new_option_with_valid_positive_integer_price_succeeds(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', [
                'name' => 'Extra Rice',
                'price' => 10,
            ]);

        $response->assertRedirect(route('admin.menu-options'));
        $response->assertSessionHasNoErrors();

        $this->assertSame('10.00', (string) $this->newestOption()->additional_price);
    }

    public function test_new_option_with_valid_decimal_price_succeeds(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', [
                'name' => 'Unli Gravy',
                'price' => 10.50,
            ]);

        $response->assertRedirect(route('admin.menu-options'));
        $response->assertSessionHasNoErrors();

        $this->assertSame('10.50', (string) $this->newestOption()->additional_price);
    }

    public function test_new_option_with_price_ninety_nine_point_nine_nine_succeeds(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', [
                'name' => 'Premium Topping',
                'price' => 99.99,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('99.99', (string) $this->newestOption()->additional_price);
    }

    // ══════════ create: rejected ══════════

    public function test_new_option_with_empty_price_is_rejected(): void
    {
        $before = MenuOption::count();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', [
                'name' => 'No Price Given',
                'price' => '',
            ]);

        $response->assertSessionHasErrors('price');
        $this->assertSame($before, MenuOption::count(), 'no row should have been created');
    }

    public function test_new_option_with_missing_price_key_entirely_is_rejected(): void
    {
        // The 'price' field is left out of the payload altogether — not just
        // blank. Confirms 'required' is doing the work, not just 'numeric'.
        $before = MenuOption::count();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', [
                'name' => 'Price Key Missing',
            ]);

        $response->assertSessionHasErrors('price');
        $this->assertSame($before, MenuOption::count());
    }

    public function test_new_option_with_price_zero_is_rejected(): void
    {
        $before = MenuOption::count();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', [
                'name' => 'Zero Price',
                'price' => 0,
            ]);

        $response->assertSessionHasErrors('price');
        $this->assertSame($before, MenuOption::count());
    }

    public function test_new_option_with_price_zero_point_zero_zero_is_rejected(): void
    {
        $before = MenuOption::count();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', [
                'name' => 'Zero Point Zero Price',
                'price' => '0.00',
            ]);

        $response->assertSessionHasErrors('price');
        $this->assertSame($before, MenuOption::count());
    }

    public function test_new_option_with_negative_price_is_rejected(): void
    {
        $before = MenuOption::count();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', [
                'name' => 'Negative Price',
                'price' => -5,
            ]);

        $response->assertSessionHasErrors('price');
        $this->assertSame($before, MenuOption::count());
    }

    public function test_new_option_with_non_numeric_price_is_rejected(): void
    {
        $before = MenuOption::count();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', [
                'name' => 'Non Numeric Price',
                'price' => 'free',
            ]);

        $response->assertSessionHasErrors('price');
        $this->assertSame($before, MenuOption::count());
    }

    // ══════════ update: accepted ══════════

    public function test_existing_valid_option_still_updates_normally(): void
    {
        $option = $this->freshOption('Garlic Bread', 8);

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name' => 'Garlic Bread (Large)',
                'price' => 15.75,
            ]);

        $response->assertRedirect(route('admin.menu-options'));
        $response->assertSessionHasNoErrors();

        $option->refresh();
        $this->assertSame('Garlic Bread (Large)', $option->name);
        $this->assertSame('15.75', (string) $option->additional_price);
    }

    // ══════════ direct-endpoint bypass attempts (no browser/JS involved) ══════════

    /**
     * The Laravel test client posts straight to the route — there is no
     * browser, no `required`/`min` attribute, and no JS in this request path
     * at all. Every assertion in this class already proves the point, but
     * this test says so explicitly: an attacker (or curl) hitting the
     * endpoint directly, past whatever the form renders, still gets a
     * server-side 422/redirect-with-errors and never reaches the DB.
     */
    public function test_price_validation_cannot_be_bypassed_by_posting_directly_to_the_endpoint(): void
    {
        $admin = $this->admin();
        $before = MenuOption::count();

        $attempts = [
            ['name' => 'Bypass A', 'price' => 0],
            ['name' => 'Bypass B'],
            ['name' => 'Bypass C', 'price' => -1],
            ['name' => 'Bypass D', 'price' => 'DROP TABLE menu_options'],
        ];

        foreach ($attempts as $payload) {
            $this->actingAs($admin, 'admin')
                ->post('/admin/menu-options', $payload)
                ->assertSessionHasErrors('price');
        }

        $this->assertSame($before, MenuOption::count(), 'none of the bypass attempts should have created a row');

        // Same proof on the update path, against a real, currently-valid option.
        $option = $this->freshOption('Bypass Target', 20);

        $this->actingAs($admin, 'admin')
            ->put('/admin/menu-options/' . $option->id, ['name' => 'Bypass Target', 'price' => 0])
            ->assertSessionHasErrors('price');

        $this->assertSame('20.00', (string) $option->fresh()->additional_price);
    }

    // ══════════ existing zero-priced records ══════════

    public function test_an_existing_zero_priced_option_remains_visible_and_free_until_edited(): void
    {
        // Simulates a row that predates this change (created directly, the
        // way a seeder or the old validation would have allowed). Not
        // bulk-migrated, not deleted — it must keep showing up exactly as
        // before until someone chooses to edit it.
        $option = $this->freshOption('Legacy Free Sauce', 0);
        $this->assertSame('0.00', (string) $option->additional_price);

        $page = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/menu-options')
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Legacy Free Sauce', $page);

        // Untouched by an unrelated write to a different option.
        $this->freshOption('Unrelated Option', 5);
        $this->assertSame('0.00', (string) $option->fresh()->additional_price);

        // Editing it now requires a real price — the new rule reaches old
        // rows the moment they are saved again, without a bulk migration.
        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $option->id, ['name' => 'Legacy Free Sauce', 'price' => 0])
            ->assertSessionHasErrors('price');

        $this->assertSame('0.00', (string) $option->fresh()->additional_price, 'rejected edit must leave the stored price alone');
    }

    // ══════════ branch mapping / assignment behavior unchanged ══════════

    public function test_editing_price_leaves_branch_mapping_and_menu_item_assignment_untouched(): void
    {
        $option = $this->freshOption('Mapped Add-on', 12);

        $inventory = Inventory::create([
            'branch_id' => 1,
            'item_name' => 'Price-validation-test ingredient ' . uniqid(),
            'item_code' => 'PVT-' . strtoupper(substr(uniqid(), -9)),
            'category' => 'Price-validation-test',
            'quantity' => 50,
            'unit' => 'g',
            'low_stock_alert' => 1,
            'unit_cost' => 1,
            'is_active' => true,
        ]);

        $ingredient = MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id' => $inventory->id,
            'quantity_used' => 2,
        ]);

        $item = MenuItem::create([
            'category_id' => Category::value('id'),
            'branch_id' => 1,
            'name' => 'Price-validation-test item ' . uniqid(),
            'price' => 100,
            'is_available' => true,
            'display_order' => 0,
        ]);
        $option->menuItems()->attach($item->id);

        $this->assertTrue($option->fresh()->isMappedForBranch(1));

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name' => 'Mapped Add-on',
                'price' => 18,
            ]);

        $response->assertSessionHasNoErrors();

        $option->refresh();
        $this->assertSame('18.00', (string) $option->additional_price);

        // The ingredient link (branch mapping) is exactly the same row.
        $ingredient->refresh();
        $this->assertSame($inventory->id, $ingredient->inventory_id);
        $this->assertSame('2.000', (string) $ingredient->quantity_used);
        $this->assertTrue($option->isMappedForBranch(1));

        // The menu-item assignment pivot is untouched.
        $this->assertEqualsCanonicalizing(
            [$item->id],
            $option->menuItems->pluck('id')->all()
        );
    }
}
