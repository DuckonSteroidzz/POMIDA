<?php

namespace Tests\Feature\Concerns;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemSize;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Menu Item Sizes, Phase 2 — self-contained order-flow fixtures.
 *
 * Builds on MenuItemSizeFixtures (Phase 1): every branch, item, size,
 * inventory row, add-on and account is created by the test inside
 * DatabaseTransactions and found again by id, never by name. No real menu
 * content is read or touched.
 *
 * sizedCoffee() deliberately gives the item a BASE recipe whose only
 * ingredient holds ZERO stock. Any path that wrongly reads the base recipe
 * for a sized line therefore fails loudly — an "out of stock" refusal at
 * checkout or a "Not enough" at completion — instead of passing quietly.
 */
trait MenuItemSizeOrderFixtures
{
    use MenuItemSizeFixtures;

    /**
     * A sized item at $branch: Regular ₱100 (1 of $regInv per unit, unit cost
     * ₱2), Large ₱150 (2 of $largeInv per unit, unit cost ₱3), base recipe on
     * an EMPTY $baseInv. menu_items.price is therefore ₱100 ("starting from").
     *
     * @return array{item: MenuItem, regular: MenuItemSize, large: MenuItemSize,
     *               regInv: Inventory, largeInv: Inventory, baseInv: Inventory}
     */
    protected function sizedCoffee(Branch $branch, float $regStock = 100, float $largeStock = 100, string $name = 'Test Coffee'): array
    {
        $item = $this->sizeItem($branch->id, $name, 90);

        $baseInv = $this->sizeInventory($branch->id, 0, 1, 'Base');
        $this->baseRecipeLine($item, $baseInv, 1);

        [$regular, $large] = $this->enableSizes($item, 100, 150);

        $regInv = $this->sizeInventory($branch->id, $regStock, 2, 'Reg');
        $largeInv = $this->sizeInventory($branch->id, $largeStock, 3, 'Large');

        $this->sizeRecipeLine($regular, $regInv, 1);
        $this->sizeRecipeLine($large, $largeInv, 2);

        return [
            'item'     => $item->fresh(),
            'regular'  => $regular->fresh(),
            'large'    => $large->fresh(),
            'regInv'   => $regInv,
            'largeInv' => $largeInv,
            'baseInv'  => $baseInv,
        ];
    }

    /** An ordinary unsized item whose base recipe needs 1 of $inv per unit. */
    protected function unsizedItem(Branch $branch, Inventory $inv, float $price = 60, string $name = 'Plain Tea'): MenuItem
    {
        $item = $this->sizeItem($branch->id, $name, $price);
        $this->baseRecipeLine($item, $inv, 1);

        return $item->fresh();
    }

    /** A ₱20 add-on assigned to $item and mapped to $branch through its own ingredient. */
    protected function addOn(MenuItem $item, Branch $branch, float $price = 20, string $name = 'Extra Shot'): MenuOption
    {
        $option = MenuOption::create([
            'name'             => 'MIS ' . $name . ' ' . uniqid(),
            'additional_price' => $price,
            'display_order'    => 0,
            'is_active'        => true,
        ]);

        MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $this->sizeInventory($branch->id, 100, 1, 'AddOn')->id,
            'quantity_used'  => 1,
        ]);

        $item->options()->attach($option->id);

        return $option;
    }

    /**
     * One session-cart line exactly as AuthController::addToCart() writes it:
     * keyed "{item}" / "{item}_s{size}" (+ "_{option}…"), size_id on a sized line.
     */
    protected function cartLine(MenuItem $item, ?MenuItemSize $size, int $qty, array $options = []): array
    {
        $key = (string) $item->id . ($size !== null ? '_s' . $size->id : '');

        $optionDetails = [];
        $optionIds = collect($options)->map(fn (MenuOption $o) => $o->id)->sort()->values()->all();
        foreach ($options as $option) {
            $optionDetails[] = ['id' => $option->id, 'name' => $option->name, 'price' => (float) $option->additional_price];
        }
        if ($optionIds) {
            $key .= '_' . implode('_', $optionIds);
        }

        $base = (float) ($size !== null ? $size->price : $item->price);

        $line = [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => $base + array_sum(array_column($optionDetails, 'price')),
            'base_price'   => $base,
            'quantity'     => $qty,
            'image'        => null,
            'options'      => $optionDetails,
        ];

        if ($size !== null) {
            $line['size_id'] = $size->id;
            $line['size_name'] = $size->name;
        }

        return [$key => $line];
    }

    /** Customer checkout of $cart as a guest pick-up at $branch. */
    protected function placePickUp(Branch $branch, array $cart): TestResponse
    {
        return $this->withSession([
                'cart'       => $cart,
                'branch_id'  => $branch->id,
                'order_type' => 'pick_up',
            ])
            ->from(route('customer.cart'))
            ->post(route('customer.place-order'), [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => collect($cart)->values()->map(fn (array $line) => [
                    'menu_item_id' => $line['menu_item_id'],
                    'quantity'     => $line['quantity'],
                ])->all(),
            ]);
    }

    protected function ordersAt(Branch $branch)
    {
        return Order::where('branch_id', $branch->id)->orderBy('id')->get();
    }

    protected function latestOrderAt(Branch $branch): ?Order
    {
        return Order::where('branch_id', $branch->id)->latest('id')->first();
    }

    protected function completeAs(User $actor, Order $order): TestResponse
    {
        return $this->actingAs($actor, 'admin')
            ->from('/admin/home')
            ->put(route('admin.orders.complete', $order->id));
    }

    /** The walk-in counter's POST, as the modal builds it. */
    protected function counterOrder(User $actor, Branch $branch, array $items): TestResponse
    {
        return $this->actingAs($actor, 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', [
                'branch_id'      => $branch->id,
                'order_type'     => 'pick_up',
                'table_number'   => '',
                'payment_method' => 'cash',
                'amount_paid'    => '99999',
                'items'          => $items,
            ]);
    }

    protected function rawQty(Inventory $inventory): float
    {
        return (float) DB::table('inventory')->where('id', $inventory->id)->value('quantity');
    }

    /** @return array<int, string> [inventory_id => quantity] of a line's frozen recipe */
    protected function frozenRows(int $orderItemId): array
    {
        return DB::table('order_item_size_ingredients')
            ->where('order_item_id', $orderItemId)
            ->orderBy('inventory_id')
            ->pluck('quantity', 'inventory_id')
            ->all();
    }
}
