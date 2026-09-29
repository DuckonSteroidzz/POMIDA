<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hardening pass F7 (2026-09-27): an add-on must be assigned to the menu item
 * it is ordered with, on the customer cart AND at checkout.
 *
 * WHAT WAS WRONG
 * --------------
 * AuthController::addToCart() loaded posted option ids with a bare
 * whereIn('id', ...) and OrderController::placeOrder() re-priced whatever
 * option ids the session cart held. Both checked only that the option was
 * mapped to the BRANCH (isMappedForBranch()), never that it belonged to the
 * ITEM. So any add-on assigned to any other item — a ₱25 "extra shot" on a
 * slice of cake — could be posted, carted, charged and sent to the kitchen,
 * with its ingredients deducted at completion. Reproduced before the fix:
 * the option went into the cart and checkout wrote the order (total 125.00,
 * one order_item_options row).
 *
 * The walk-in counter (AdminController::storeManualOrder()) already refused
 * this with $menuItem->options->firstWhere('id', ...); both customer doors now
 * run that same check, before the branch-mapping one, as the counter does.
 *
 * Fixture ids are asserted, never names — menu_options has no unique index on
 * name. Everything is rolled back by DatabaseTransactions.
 */
class OptionMustBelongToItemTest extends TestCase
{
    use DatabaseTransactions;

    private const BRANCH = 1;
    private const PREFIX = 'F7OPT';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ══════════ fixtures ══════════

    private function inventory(string $name): Inventory
    {
        return Inventory::create([
            'branch_id'       => self::BRANCH,
            'item_name'       => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'item_code'       => 'F7I-' . strtoupper(substr(uniqid(), -10)),
            'category'        => self::PREFIX,
            'quantity'        => 500,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);
    }

    /** A branch item with a base recipe, so only the add-on rule is under test. */
    private function item(string $name): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::query()->value('id'),
            'branch_id'     => self::BRANCH,
            'name'          => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $this->inventory($name . ' base')->id,
            'quantity_used' => 1,
        ]);

        return $item;
    }

    /** An add-on mapped to the branch (so isMappedForBranch() alone would pass it). */
    private function option(string $name, float $price): MenuOption
    {
        $option = MenuOption::create([
            'name'             => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'additional_price' => $price,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $this->inventory($name . ' ingredient')->id,
            'quantity_used'  => 1,
        ]);

        return $option;
    }

    /**
     * $cake has its own add-on; $shot is a real, branch-mapped add-on that
     * belongs to a DIFFERENT item ($coffee) only.
     *
     * @return array{0: MenuItem, 1: MenuOption, 2: MenuOption}
     */
    private function scenario(): array
    {
        $cake   = $this->item('Cake');
        $coffee = $this->item('Coffee');

        $candle = $this->option('Candle', 10);
        $shot   = $this->option('Extra Shot', 25);

        $cake->options()->attach($candle->id);
        $coffee->options()->attach($shot->id);

        // Setup checks: the foreign add-on passes the branch rule, fails only the item rule.
        $this->assertTrue($shot->fresh()->isMappedForBranch(self::BRANCH));
        $this->assertFalse($cake->options()->whereKey($shot->id)->exists());

        return [$cake, $candle, $shot];
    }

    private function addToCart(MenuItem $item, array $options)
    {
        return $this->withSession(['branch_id' => self::BRANCH, 'order_type' => 'pick_up'])
            ->from('/customer/item/' . $item->id)
            ->post('/customer/cart/add', [
                'item_id'  => $item->id,
                'quantity' => 1,
                'options'  => $options,
            ]);
    }

    /** One session-cart line in the exact shape addToCart() writes. */
    private function cartLine(MenuItem $item, array $options): array
    {
        $ids = array_map(fn (MenuOption $o) => $o->id, $options);
        sort($ids);
        $key = $ids ? $item->id . '_' . implode('_', $ids) : (string) $item->id;

        return [$key => [
            'menu_item_id' => $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price + array_sum(array_map(fn (MenuOption $o) => (float) $o->additional_price, $options)),
            'base_price'   => $item->price,
            'quantity'     => 1,
            'image'        => null,
            'options'      => array_map(fn (MenuOption $o) => [
                'id'    => $o->id,
                'name'  => $o->name,
                'price' => $o->additional_price,
            ], $options),
        ]];
    }

    /** Place $cart through the real customer checkout. Returns the new Order, or null if refused. */
    private function checkout(array $cart, MenuItem $item)
    {
        $before = (int) Order::max('id');

        $response = $this->withSession([
            'cart'         => $cart,
            'branch_id'    => self::BRANCH,
            'order_type'   => 'dine_in',
            'table_number' => '9',
        ])->from('/customer/cart')->post('/customer/place-order', [
            'order_type'     => 'dine_in',
            'table_number'   => '9',
            'payment_method' => 'cash',
            'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        return [$response, Order::where('id', '>', $before)->orderByDesc('id')->first()];
    }

    private function optionRowsFor(Order $order): array
    {
        return DB::table('order_item_options')
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->pluck('menu_option_id')
            ->map('intval')
            ->all();
    }

    // ══════════ add to cart ══════════

    public function test_add_to_cart_refuses_an_add_on_that_belongs_to_a_different_item(): void
    {
        [$cake, , $shot] = $this->scenario();

        $this->addToCart($cake, [$shot->id])->assertSessionHas('error');

        $this->assertSame([], session('cart', []), 'another item\'s add-on reached the cart');
    }

    public function test_add_to_cart_refuses_the_whole_line_when_one_of_several_add_ons_is_foreign(): void
    {
        [$cake, $candle, $shot] = $this->scenario();

        $this->addToCart($cake, [$candle->id, $shot->id])->assertSessionHas('error');

        $this->assertSame([], session('cart', []));
    }

    public function test_add_to_cart_refuses_an_add_on_id_that_does_not_exist(): void
    {
        [$cake] = $this->scenario();
        $missingId = (int) MenuOption::max('id') + 1000;

        $this->addToCart($cake, [$missingId])->assertSessionHas('error');

        $this->assertSame([], session('cart', []));
    }

    /** (int) of an array is 1 — a nested array must not turn into "option #1". */
    public function test_add_to_cart_refuses_a_non_integer_option_payload(): void
    {
        [$cake] = $this->scenario();

        $this->addToCart($cake, [['1']])->assertSessionHasErrors('options.0');

        $this->assertSame([], session('cart', []));
    }

    /** Pairing: the item's own add-on is carted exactly as before. */
    public function test_add_to_cart_still_accepts_the_items_own_add_on(): void
    {
        [$cake, $candle] = $this->scenario();

        $this->addToCart($cake, [$candle->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Added to cart!');

        $cart = session('cart', []);
        $key  = $cake->id . '_' . $candle->id;

        $this->assertArrayHasKey($key, $cart);
        $this->assertSame([$candle->id], array_column($cart[$key]['options'], 'id'));
        $this->assertEquals(110, $cart[$key]['price']);
    }

    /** Pairing: no add-ons at all is untouched. */
    public function test_add_to_cart_still_accepts_an_item_with_no_add_ons(): void
    {
        [$cake] = $this->scenario();

        $this->addToCart($cake, [])->assertSessionHas('success', 'Added to cart!');

        $this->assertArrayHasKey((string) $cake->id, session('cart', []));
    }

    // ══════════ checkout ══════════

    /** A cart crafted in the session (stale tab, hand-built request) skips addToCart entirely. */
    public function test_checkout_refuses_a_cart_line_carrying_another_items_add_on(): void
    {
        [$cake, , $shot] = $this->scenario();

        [$response, $order] = $this->checkout($this->cartLine($cake, [$shot]), $cake);

        $response->assertSessionHasErrors('items');
        $this->assertNull($order, 'checkout wrote an order with another item\'s add-on on it');
        $this->assertStringContainsString(
            $shot->name,
            (string) session('errors')->first('items'),
            'the refusal should name the add-on the customer has to remove'
        );
    }

    public function test_checkout_refuses_the_whole_order_when_only_one_line_is_wrong(): void
    {
        [$cake, $candle, $shot] = $this->scenario();

        $cart = $this->cartLine($cake, [$candle]) + $this->cartLine($cake, [$shot]);
        $this->assertCount(2, $cart, 'setup: two distinct cart lines');

        [$response, $order] = $this->checkout($cart, $cake);

        $response->assertSessionHasErrors('items');
        $this->assertNull($order);
    }

    /** Pairing: the item's own add-on checks out, is charged, and is recorded, as before. */
    public function test_checkout_still_accepts_the_items_own_add_on(): void
    {
        [$cake, $candle] = $this->scenario();

        [$response, $order] = $this->checkout($this->cartLine($cake, [$candle]), $cake);

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order, 'checkout refused a correctly assigned add-on');
        $this->assertEquals(110, (float) $order->total);
        $this->assertSame([$candle->id], $this->optionRowsFor($order));
    }

    /** Pairing: the existing branch rule still applies after the new item rule. */
    public function test_an_assigned_but_unmapped_add_on_is_still_refused_for_the_branch(): void
    {
        [$cake] = $this->scenario();

        $unmapped = MenuOption::create([
            'name'             => self::PREFIX . ' Unmapped ' . uniqid(),
            'additional_price' => 5,
            'is_active'        => true,
            'display_order'    => 0,
        ]);
        $cake->options()->attach($unmapped->id);

        $this->addToCart($cake, [$unmapped->id])->assertSessionHas('error');
        $this->assertSame([], session('cart', []));

        [$response, $order] = $this->checkout($this->cartLine($cake, [$unmapped]), $cake);
        $response->assertSessionHasErrors('items');
        $this->assertNull($order);
    }
}
