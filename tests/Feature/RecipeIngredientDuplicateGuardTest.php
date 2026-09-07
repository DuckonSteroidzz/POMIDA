<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A duplicate ingredient is refused at the moment of adding, not at submit (2026-09-03).
 *
 * WHY
 * ----
 * The Recipe Ingredients editor let the same inventory row be added twice: two
 * "Coffee Beans" lines, and the cost preview counted both. The server already
 * rejected a duplicate — storeNewMenuItem() at submit, addIngredient() on a
 * direct POST, and a UNIQUE (menu_item_id, inventory_id) index underneath — but
 * only after the admin had filled in the whole form. The guard is now also at
 * the "+ Add" click:
 *   - ADD mode: the draft row is not appended and the preview does not move
 *     (client-side; the shared "+ Add" handler in menu-items.blade.php).
 *   - EDIT mode: the client refuses it before any request, and addIngredient()
 *     still refuses a direct POST with a readable message.
 *
 * This suite covers the server half (the client half has no JS test harness in
 * this project) plus the markup the client guard reads.
 *
 * CLEANUP
 * --------
 * Real writes to the live pomida_db, same as MenuItemAddWithIngredientsTest.
 * setUp() captures a high-water mark per table; tearDown() deletes only rows
 * above it AND carrying this suite's RIDG name prefix. No pre-existing row is
 * ever touched.
 */
class RecipeIngredientDuplicateGuardTest extends TestCase
{
    /** Tables this test inserts into, child-before-parent delete order. */
    private const BOUNDED_TABLES = ['menu_item_ingredients', 'menu_items', 'inventory'];

    /** @var array<string,int> */
    private array $marks = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::BOUNDED_TABLES as $table) {
            $this->marks[$table] = (int) (DB::table($table)->max('id') ?? 0);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanUpBoundedRows();

        parent::tearDown();
    }

    private function cleanUpBoundedRows(): void
    {
        $mine = DB::table('menu_items')
            ->where('id', '>', $this->marks['menu_items'])
            ->where('name', 'like', 'RIDG%')
            ->pluck('id');

        if ($mine->isNotEmpty()) {
            DB::table('menu_item_ingredients')
                ->where('id', '>', $this->marks['menu_item_ingredients'])
                ->whereIn('menu_item_id', $mine)
                ->delete();

            DB::table('menu_items')->whereIn('id', $mine)->delete();
        }

        DB::table('inventory')
            ->where('id', '>', $this->marks['inventory'])
            ->where('item_name', 'like', 'RIDG%')
            ->delete();
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->firstOrFail();
    }

    private function asAdmin(): self
    {
        $this->actingAs($this->admin(), 'admin')->withSession(['selected_branch_id' => 1]);

        return $this;
    }

    private function makeInventory(array $attrs = []): Inventory
    {
        return Inventory::create(array_merge([
            'branch_id'       => 1,
            'item_name'       => 'RIDG Coffee Beans ' . uniqid(),
            'item_code'       => 'RIDG-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => 1000,
            'unit'            => 'g',
            'low_stock_alert' => 10,
            'unit_cost'       => 2.00,
            'is_active'       => true,
        ], $attrs));
    }

    private function makeItem(): MenuItem
    {
        return MenuItem::create([
            'category_id'   => DB::table('categories')->min('id'),
            'branch_id'     => 1,
            'name'          => 'RIDG Recipe Item ' . uniqid(),
            'price'         => 120,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
            'total_sold'    => 0,
        ]);
    }

    private function addUrl(MenuItem $item): string
    {
        return route('admin.menu-items.ingredients.add', $item->id);
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT MODE — a direct POST of a duplicate is refused, readably
    |--------------------------------------------------------------------------
    */

    public function test_a_duplicate_json_post_to_add_ingredient_is_refused_and_leaves_one_row(): void
    {
        $ing  = $this->makeInventory();
        $item = $this->makeItem();
        MenuItemIngredient::create([
            'menu_item_id' => $item->id,
            'inventory_id' => $ing->id,
            'quantity_used' => 1,
        ]);

        $response = $this->asAdmin()->postJson($this->addUrl($item), [
            'inventory_id'  => $ing->id,
            'quantity_used' => 5,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $this->assertStringContainsString(
            'already added',
            (string) $response->json('message'),
            'the refusal must read like a sentence, not a raw error'
        );

        $rows = MenuItemIngredient::where('menu_item_id', $item->id)
            ->where('inventory_id', $ing->id)
            ->get();
        $this->assertCount(1, $rows, 'the duplicate POST must not create a second row');
        $this->assertSame(1.0, (float) $rows->first()->quantity_used, 'the existing row is untouched, not silently updated');
    }

    public function test_a_duplicate_form_post_to_add_ingredient_bounces_with_an_error_message(): void
    {
        $ing  = $this->makeInventory();
        $item = $this->makeItem();
        MenuItemIngredient::create([
            'menu_item_id' => $item->id,
            'inventory_id' => $ing->id,
            'quantity_used' => 2,
        ]);

        $response = $this->asAdmin()->post($this->addUrl($item), [
            'inventory_id'  => $ing->id,
            'quantity_used' => 9,
        ]);

        $response->assertSessionHasErrors('inventory_id');
        $this->assertStringContainsString(
            'already added',
            (string) session('errors')->first('inventory_id')
        );

        $this->assertSame(
            1,
            MenuItemIngredient::where('menu_item_id', $item->id)->where('inventory_id', $ing->id)->count()
        );
    }

    public function test_a_first_time_ingredient_still_adds_normally(): void
    {
        // The guard must only bite on a genuine repeat.
        $ing  = $this->makeInventory();
        $item = $this->makeItem();

        $response = $this->asAdmin()->postJson($this->addUrl($item), [
            'inventory_id'  => $ing->id,
            'quantity_used' => 4,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertSame((string) $ing->id, (string) $response->json('ingredient.inventory_id'));
        $this->assertSame(
            1,
            MenuItemIngredient::where('menu_item_id', $item->id)->where('inventory_id', $ing->id)->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | THE MARKUP THE CLIENT GUARD READS
    |--------------------------------------------------------------------------
    */

    public function test_saved_recipe_rows_carry_the_inventory_id_the_client_guard_matches_on(): void
    {
        $ing  = $this->makeInventory(['item_name' => 'RIDG Marker Beans']);
        $item = $this->makeItem();
        $item->update(['name' => 'RIDG Marker Item']);
        MenuItemIngredient::create([
            'menu_item_id' => $item->id,
            'inventory_id' => $ing->id,
            'quantity_used' => 3,
        ]);

        $html = $this->asAdmin()->get('/admin/menu-items')->getContent();

        // Isolated to this item's own recipe block, not the whole page — another
        // block loops the same inventory list.
        $start = strpos($html, 'class="recipe-block" id="recipe-' . $item->id . '"');
        $this->assertNotFalse($start);
        $end = strpos($html, 'class="recipe-block"', $start + 30);
        $block = substr($html, $start, ($end ?: strlen($html)) - $start);

        $this->assertStringContainsString('data-inventory-id="' . $ing->id . '"', $block);

        // And the "+ Add" handler carries the at-the-moment refusal wording.
        $this->assertStringContainsString('is already in the recipe.', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | THE SUBMIT-TIME GUARD IS UNCHANGED
    |--------------------------------------------------------------------------
    */

    public function test_add_mode_submit_still_rejects_two_rows_with_the_same_inventory_id(): void
    {
        // Not a new rule — this only pins that the submit-time guard the ADD
        // client mirrors is still in place and still says what is wrong.
        $ing = $this->makeInventory();

        $payload = [
            'category_id' => DB::table('categories')->min('id'),
            'name'        => 'RIDG Submit Dupe ' . uniqid(),
            'price'       => 150,
            'ingredients' => [
                ['inventory_id' => $ing->id, 'quantity_used' => 1],
                ['inventory_id' => $ing->id, 'quantity_used' => 2],
            ],
        ];

        $response = $this->asAdmin()->post('/admin/new-menu-item', $payload);

        $response->assertSessionHasErrors('ingredients');
        $this->assertNull(MenuItem::withArchived()->where('name', $payload['name'])->first());
    }

    /*
    |--------------------------------------------------------------------------
    | BOUNDED CLEANUP PROOF
    |--------------------------------------------------------------------------
    */

    public function test_nothing_leaks_above_the_high_water_marks(): void
    {
        $ing  = $this->makeInventory();
        $item = $this->makeItem();
        MenuItemIngredient::create([
            'menu_item_id' => $item->id,
            'inventory_id' => $ing->id,
            'quantity_used' => 1,
        ]);

        $this->cleanUpBoundedRows();

        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', 'RIDG%')->count());
        $this->assertSame(0, DB::table('inventory')->where('item_name', 'like', 'RIDG%')->count());
        $this->assertSame(
            0,
            DB::table('menu_item_ingredients')->where('id', '>', $this->marks['menu_item_ingredients'])->count(),
            'recipe rows are only ever created by this test, so none may survive'
        );
    }
}
