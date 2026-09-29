<?php

namespace Tests\Feature\Concerns;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\MenuItemSize;
use App\Models\MenuItemSizeIngredient;
use App\Models\User;
use App\Services\MenuItemSizes;
use Illuminate\Support\Facades\DB;

/**
 * Self-contained fixtures for the Menu Item Sizes (Phase 1) tests.
 *
 * Nothing here reads an existing row of pomida_db_testing: every branch,
 * category, inventory row, menu item and account is created by the test, inside
 * DatabaseTransactions, and looked up afterwards by id — never by name (menu
 * item and option names are not unique, and the testing DB is never wiped).
 * Branches are created fresh, so their ids are whatever AUTO_INCREMENT hands
 * out; no test depends on a particular branch id.
 */
trait MenuItemSizeFixtures
{
    /** Refuse to run anywhere but the testing database. */
    protected function assertOnTestingDatabase(): void
    {
        $this->assertSame(
            'pomida_db_testing',
            DB::selectOne('select database() as d')->d,
            'Menu Item Sizes tests must only ever run against pomida_db_testing.'
        );
    }

    protected function sizeBranch(string $label): Branch
    {
        return Branch::create([
            'name'      => 'MIS ' . $label . ' ' . uniqid(),
            'code'      => 'MIS' . strtoupper(substr(uniqid(), -7)),
            'address'   => 'MIS test address',
            'is_active' => true,
        ]);
    }

    protected function sizeCategory(): Category
    {
        return Category::create([
            'name'      => 'MIS Category ' . uniqid(),
            'is_active' => true,
        ]);
    }

    protected function sizeInventory(int $branchId, float $quantity = 1000, float $unitCost = 1, string $label = 'Ingredient', bool $active = true): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => 'MIS ' . $label . ' ' . uniqid(),
            'item_code'       => 'MIS-' . strtoupper(substr(uniqid(), -10)),
            'category'        => 'MIS',
            'quantity'        => $quantity,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => $unitCost,
            'is_active'       => $active,
        ]);
    }

    protected function sizeItem(?int $branchId, string $name = 'Test Coffee', float $price = 90): MenuItem
    {
        return MenuItem::create([
            'category_id'   => $this->sizeCategory()->id,
            'branch_id'     => $branchId,
            'name'          => $name,
            'price'         => $price,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    protected function baseRecipeLine(MenuItem $item, Inventory $inventory, float $quantity): MenuItemIngredient
    {
        return MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $inventory->id,
            'quantity_used' => $quantity,
        ]);
    }

    /** @return array{0: MenuItemSize, 1: MenuItemSize} [Regular, Large] */
    protected function enableSizes(MenuItem $item, float $regularPrice = 100, float $largePrice = 150): array
    {
        $sizes = app(MenuItemSizes::class)->enable($item, $regularPrice, $largePrice)->keyBy('name');

        return [$sizes[MenuItemSize::REGULAR], $sizes[MenuItemSize::LARGE]];
    }

    protected function sizeRecipeLine(MenuItemSize $size, Inventory $inventory, float $quantity): MenuItemSizeIngredient
    {
        return MenuItemSizeIngredient::create([
            'menu_item_size_id' => $size->id,
            'inventory_id'      => $inventory->id,
            'quantity'          => $quantity,
        ]);
    }

    protected function sizeAccount(string $role, ?int $branchId): User
    {
        return User::create([
            'name'      => 'MIS ' . ucfirst($role),
            'email'     => 'mis-' . $role . '-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => $role,
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    protected function sizeOwner(): User
    {
        return $this->sizeAccount('admin', null);
    }

    protected function sizeSupervisor(int $branchId): User
    {
        return $this->sizeAccount('supervisor', $branchId);
    }

    protected function sizeStaff(int $branchId): User
    {
        return $this->sizeAccount('staff', $branchId);
    }

    /** Raw DB read of a size row, archived or not — no model scopes, no caching. */
    protected function rawSize(int $sizeId): ?object
    {
        return DB::table('menu_item_sizes')->where('id', $sizeId)->first();
    }

    protected function rawItemPrice(int $menuItemId): string
    {
        return (string) DB::table('menu_items')->where('id', $menuItemId)->value('price');
    }
}
