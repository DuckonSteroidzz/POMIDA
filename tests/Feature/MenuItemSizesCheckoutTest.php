<?php

namespace Tests\Feature;

use App\Models\MenuItemSize;
use App\Models\OrderItem;
use App\Services\InventoryDeductionService;
use App\Services\MenuItemSizes;
use App\Support\CartPricing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\MenuItemSizeOrderFixtures;
use Tests\TestCase;

/**
 * Menu Item Sizes, Phase 2 — customer size selection, cart and checkout.
 *
 * Required scenarios 1-7 and 21 of the Phase 2 brief, plus the refusals a
 * size that changes while it sits in a cart must get. Every fixture is
 * self-contained (see MenuItemSizeOrderFixtures); the base recipe of every
 * sized item sits on an EMPTY shelf, so a sized line that leaked onto the
 * base recipe would be refused or fail, not pass.
 */
class MenuItemSizesCheckoutTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    private function addToCart(int $branchId, array $payload)
    {
        return $this->withSession(['branch_id' => $branchId, 'order_type' => 'pick_up'])
            ->from('/customer/menu')
            ->post(route('customer.cart.add'), $payload + ['quantity' => 1]);
    }

    // ══════════ 1. A size is required, server-side ══════════

    public function test_a_sized_item_cannot_be_added_to_cart_without_a_size(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);

        $this->addToCart($branch->id, ['item_id' => $f['item']->id])
            ->assertRedirect('/customer/menu')
            ->assertSessionHas('error', 'Please choose a size for Test Coffee first.');
        $this->assertSame([], session('cart', []), 'nothing reached the cart');

        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => ''])
            ->assertSessionHas('error', 'Please choose a size for Test Coffee first.');
        $this->assertSame([], session('cart', []));
    }

    public function test_a_size_that_is_not_this_items_or_not_sellable_is_refused_at_add_to_cart(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $other = $this->sizedCoffee($branch, 100, 100, 'Other Coffee');
        $plainInv = $this->sizeInventory($branch->id, 50);
        $plain = $this->unsizedItem($branch, $plainInv);

        // Another item's size.
        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => $other['large']->id])
            ->assertSessionHas('error', 'Sorry, that size of Test Coffee is not available.');

        // A size posted for an item that has none — refused, not ignored.
        $this->addToCart($branch->id, ['item_id' => $plain->id, 'size_id' => $f['large']->id])
            ->assertSessionHas('error', 'Sorry, that size of Plain Tea is not available.');

        // Archived and inactive sizes, in the Phase 1 resolver's own words.
        app(MenuItemSizes::class)->archive($f['large']);
        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => $f['large']->id])
            ->assertSessionHas('error', 'Sorry, Test Coffee (Large) is no longer available.');

        app(MenuItemSizes::class)->update($f['regular'], 100, false);
        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => $f['regular']->id])
            ->assertSessionHas('error', 'Sorry, Test Coffee (Regular) is not available right now.');

        // A non-integer size is a validation error, not a guess.
        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => ['x']])
            ->assertSessionHasErrors('size_id');

        $this->assertSame([], session('cart', []), 'none of those reached the cart');
    }

    public function test_a_size_with_no_recipe_is_refused_at_add_to_cart_with_the_no_recipe_message(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular, $large] = $this->enableSizes($item, 100, 150);
        $this->sizeRecipeLine($regular, $this->sizeInventory($branch->id, 50), 1);

        $this->addToCart($branch->id, ['item_id' => $item->id, 'size_id' => $large->id])
            ->assertSessionHas('error', 'Sorry, Test Coffee (Large) is unavailable right now — no recipe has been set for it yet.');
        $this->assertSame([], session('cart', []));
    }

    // ══════════ 2. The chosen size's own price ══════════

    public function test_the_cart_line_is_priced_at_the_chosen_size_not_the_starting_price(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $this->assertSame('100.00', $this->rawItemPrice($f['item']->id), 'starting-from price');

        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => $f['large']->id, 'quantity' => 2])
            ->assertSessionHas('success');

        $key = $f['item']->id . '_s' . $f['large']->id;
        $cart = session('cart');
        $this->assertSame([$key], array_map('strval', array_keys($cart)), 'one line, keyed by size');
        $this->assertEquals(150.0, $cart[$key]['price']);
        $this->assertSame($f['large']->id, $cart[$key]['size_id']);
        $this->assertSame(2, $cart[$key]['quantity']);

        $priced = CartPricing::price($cart, $branch->id);
        $this->assertEquals(150.0, $priced['lines'][0]['unit_price']);
        $this->assertEquals(300.0, $priced['subtotal']);
        $this->assertSame('Test Coffee (Large)', $priced['lines'][0]['name']);

        $page = $this->withSession(['cart' => $cart, 'branch_id' => $branch->id, 'order_type' => 'pick_up'])
            ->get(route('customer.cart'))->assertOk();
        $page->assertSee('Test Coffee (Large)');
        $page->assertSee('₱150.00 each', false);
        $page->assertSee('₱300.00', false);

        $this->placePickUp($branch, $cart)->assertRedirect(route('customer.orders'));
        $line = $this->latestOrderAt($branch)->items()->sole();
        $this->assertSame('150.00', (string) $line->item_price);
        $this->assertSame('300.00', (string) $line->subtotal);
        $this->assertSame('300.00', (string) $this->latestOrderAt($branch)->subtotal);
    }

    public function test_regular_and_large_of_one_item_are_separate_lines_at_their_own_prices(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);

        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => $f['regular']->id]);
        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => $f['large']->id]);
        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => $f['large']->id]);

        $cart = session('cart');
        $this->assertCount(2, $cart);
        $this->assertSame(1, $cart[$f['item']->id . '_s' . $f['regular']->id]['quantity']);
        $this->assertSame(2, $cart[$f['item']->id . '_s' . $f['large']->id]['quantity']);
        $this->assertEquals(400.0, CartPricing::price($cart, $branch->id)['subtotal'], '100 + 2 x 150');
    }

    // ══════════ 3. Add-ons stay flat ══════════

    public function test_add_ons_stay_flat_priced_on_top_of_either_size(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $shot = $this->addOn($f['item'], $branch, 20);

        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => $f['regular']->id, 'options' => [$shot->id]]);
        $this->addToCart($branch->id, ['item_id' => $f['item']->id, 'size_id' => $f['large']->id, 'options' => [$shot->id]]);

        $cart = session('cart');
        $this->assertEquals(120.0, $cart[$f['item']->id . '_s' . $f['regular']->id . '_' . $shot->id]['price']);
        $this->assertEquals(170.0, $cart[$f['item']->id . '_s' . $f['large']->id . '_' . $shot->id]['price']);

        $this->placePickUp($branch, $cart)->assertRedirect(route('customer.orders'));

        $lines = $this->latestOrderAt($branch)->items()->with('options')->orderBy('id')->get();
        $this->assertSame(['120.00', '170.00'], $lines->pluck('item_price')->map(fn ($p) => (string) $p)->all());
        foreach ($lines as $line) {
            $this->assertSame('20.00', (string) $line->options->sole()->pivot->additional_price, 'the add-on price is the same on both sizes');
        }
    }

    // ══════════ 4. cartShortfalls() judges a sized line by ITS recipe ══════════

    public function test_cart_shortfalls_judges_a_sized_line_by_its_own_size_recipe(): void
    {
        $branch = $this->sizeBranch('A');
        // Large needs 2 per unit and has 3 on the shelf -> exactly 1 Large.
        // Regular has plenty. The base recipe's shelf is EMPTY.
        $f = $this->sizedCoffee($branch, 100, 3);
        $service = app(InventoryDeductionService::class);
        $large = MenuItemSize::with('ingredients.inventory')->find($f['large']->id);
        $regular = MenuItemSize::with('ingredients.inventory')->find($f['regular']->id);

        $this->assertSame(
            ['Only 1 Test Coffee (Large) left in stock — please adjust your order (you asked for 2).'],
            $service->cartShortfalls([['menu_item' => $f['item'], 'quantity' => 2, 'size' => $large]], $branch->id)
        );
        $this->assertSame([], $service->cartShortfalls([['menu_item' => $f['item'], 'quantity' => 1, 'size' => $large]], $branch->id));
        $this->assertSame([], $service->cartShortfalls([['menu_item' => $f['item'], 'quantity' => 50, 'size' => $regular]], $branch->id),
            'Regular is unaffected by Large\'s shelf and by the empty base recipe');

        // Control: the base recipe really is empty, so a leak onto it would show.
        $this->assertSame(
            ['Sorry, Test Coffee is currently out of stock due to ingredient availability — please remove it from your order.'],
            $service->cartShortfalls([['menu_item' => $f['item'], 'quantity' => 1]], $branch->id)
        );

        // And through checkout.
        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], 2))
            ->assertSessionHasErrors(['items' => 'Only 1 Test Coffee (Large) left in stock — please adjust your order (you asked for 2).']);
        $this->assertCount(0, $this->ordersAt($branch));

        $this->placePickUp($branch, $this->cartLine($f['item'], $f['regular'], 5))
            ->assertRedirect(route('customer.orders'));
        $this->assertCount(1, $this->ordersAt($branch));
    }

    // ══════════ 5. Unsellable size at checkout — the existing refusal pattern ══════════

    public function test_checkout_refuses_a_size_with_no_recipe_with_the_existing_refusal_pattern(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular, $large] = $this->enableSizes($item, 100, 150);
        $this->sizeRecipeLine($regular, $this->sizeInventory($branch->id, 50), 1);

        $this->placePickUp($branch, $this->cartLine($item, $large, 1))
            ->assertRedirect(route('customer.cart'))
            ->assertSessionHasErrors(['items' => 'Sorry, Test Coffee (Large) is unavailable right now — no recipe has been set for it yet.']);

        $this->assertCount(0, $this->ordersAt($branch), 'no order, no lines');
    }

    public function test_a_size_archived_or_deactivated_while_in_the_cart_is_refused_at_checkout(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $cart = $this->cartLine($f['item'], $f['large'], 1) + $this->cartLine($f['item'], $f['regular'], 1);

        app(MenuItemSizes::class)->archive($f['large']);
        $this->placePickUp($branch, $cart)
            ->assertSessionHasErrors(['items' => 'Sorry, Test Coffee (Large) is no longer available.']);

        app(MenuItemSizes::class)->restore($f['large']);
        app(MenuItemSizes::class)->update($f['regular'], 100, false);
        $this->placePickUp($branch, $cart)
            ->assertSessionHasErrors(['items' => 'Sorry, Test Coffee (Regular) is not available right now.']);

        $this->assertCount(0, $this->ordersAt($branch));

        // The cart page says the same thing and will not let it through.
        $page = $this->withSession(['cart' => $cart, 'branch_id' => $branch->id, 'order_type' => 'pick_up'])
            ->get(route('customer.cart'))->assertOk();
        $page->assertSee('Sorry, Test Coffee (Regular) is not available right now.');
        $page->assertSee('cannot be ordered in the size you chose');
    }

    public function test_a_line_carted_before_the_item_gained_sizes_must_be_re_added_with_a_size(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $stale = $this->cartLine($f['item'], null, 1); // no size_id

        $this->assertTrue(CartPricing::price($stale, $branch->id)['size_problem']);
        $this->assertEquals(0.0, CartPricing::price($stale, $branch->id)['subtotal'], 'menu_items.price is never charged for it');

        $this->placePickUp($branch, $stale)
            ->assertSessionHasErrors(['items' => 'Please choose a size for Test Coffee — remove it from your cart and add it again from the menu.']);
        $this->assertCount(0, $this->ordersAt($branch));

        $this->withSession(['cart' => $stale, 'branch_id' => $branch->id, 'order_type' => 'pick_up'])
            ->get(route('customer.cart'))
            ->assertOk()
            ->assertSee('Please choose a size for Test Coffee');

        // Nor can its quantity be raised.
        $this->withSession(['cart' => $stale, 'branch_id' => $branch->id, 'order_type' => 'pick_up'])
            ->putJson(route('customer.cart.update', array_key_first($stale)), ['quantity' => 3])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'max_quantity' => 0]);
    }

    public function test_the_quantity_control_measures_a_sized_line_against_its_own_size(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch, 100, 6); // 3 Large possible
        $cart = $this->cartLine($f['item'], $f['large'], 1);
        $key = array_key_first($cart);

        $this->withSession(['cart' => $cart, 'branch_id' => $branch->id, 'order_type' => 'pick_up'])
            ->putJson(route('customer.cart.update', $key), ['quantity' => 4])
            ->assertStatus(422)
            ->assertJson([
                'success'      => false,
                'max_quantity' => 3,
                'message'      => 'Only 3 Test Coffee (Large) left in stock — please adjust your order (you asked for 4).',
            ]);

        $this->withSession(['cart' => $cart, 'branch_id' => $branch->id, 'order_type' => 'pick_up'])
            ->putJson(route('customer.cart.update', $key), ['quantity' => 3])
            ->assertOk()
            ->assertJson(['success' => true, 'subtotal' => 450]);
    }

    // ══════════ 6. The last unit of a size goes to one order ══════════

    public function test_an_open_order_for_the_last_unit_of_a_size_blocks_the_next_checkout(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch, 100, 2); // exactly 1 Large

        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], 1))
            ->assertRedirect(route('customer.orders'));

        // A different guest, same Large. The first order has not been
        // completed, so nothing has left the shelf — only its FROZEN recipe
        // (committedQuantities()) says the unit is taken.
        $this->flushSession();
        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], 1))
            ->assertSessionHasErrors(['items' => 'Sorry, Test Coffee (Large) is currently out of stock due to ingredient availability — please remove it from your order.']);

        $this->assertCount(1, $this->ordersAt($branch), 'only one order got the last Large');
        $this->assertSame(2.0, $this->rawQty($f['largeInv']), 'still on the shelf until completion');

        // Regular is a different recipe and is untouched by it.
        $this->flushSession();
        $this->placePickUp($branch, $this->cartLine($f['item'], $f['regular'], 1))
            ->assertRedirect(route('customer.orders'));
        $this->assertCount(2, $this->ordersAt($branch));
    }

    public function test_control_with_ample_stock_both_orders_for_the_size_go_through(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch, 100, 4); // 2 Large

        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], 1))
            ->assertRedirect(route('customer.orders'));
        $this->flushSession();
        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], 1))
            ->assertRedirect(route('customer.orders'));

        $this->assertCount(2, $this->ordersAt($branch));
    }

    // ══════════ 7. An unsized item is unchanged ══════════

    public function test_an_unsized_items_cart_checkout_and_completion_are_unchanged(): void
    {
        $branch = $this->sizeBranch('A');
        $inv = $this->sizeInventory($branch->id, 10);
        $tea = $this->unsizedItem($branch, $inv, 60);

        $this->addToCart($branch->id, ['item_id' => $tea->id, 'quantity' => 2])->assertSessionHas('success');

        $cart = session('cart');
        $this->assertSame([(string) $tea->id], array_map('strval', array_keys($cart)), 'the plain "{id}" key');
        $this->assertSame(
            ['menu_item_id', 'name', 'price', 'base_price', 'quantity', 'image', 'options'],
            array_keys($cart[$tea->id]),
            'no size keys on an unsized line'
        );
        $this->assertEquals(60.0, $cart[$tea->id]['price']);
        $this->assertFalse(CartPricing::price($cart, $branch->id)['size_problem']);

        $this->placePickUp($branch, $cart)->assertRedirect(route('customer.orders'));

        $order = $this->latestOrderAt($branch);
        $line = $order->items()->sole();
        $this->assertNull($line->size_name);
        $this->assertNull($line->menu_item_size_id);
        $this->assertSame('Plain Tea', $line->displayName());
        $this->assertSame([], $this->frozenRows($line->id));

        $this->completeAs($this->sizeOwner(), $order);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(8.0, $this->rawQty($inv), 'base recipe deducted, 2 x 1');
    }

    // ══════════ 21. Starting price round trip (Phase 1 regression) ══════════

    /**
     * The exact sequence the Phase 2 brief asks for. Phase 1's
     * test_starting_price_follows_price_active_and_archive_changes archives
     * and restores LARGE after price edits; this archives and restores
     * REGULAR from the untouched 100 / 150 pair, through the real routes.
     */
    public function test_starting_price_round_trip_when_regular_is_archived_and_restored(): void
    {
        $item = $this->sizeItem($this->sizeBranch('A')->id);
        [$regular, $large] = $this->enableSizes($item, 100, 150);
        $owner = $this->sizeOwner();

        $this->assertSame('100.00', $this->rawItemPrice($item->id), 'Regular 100 / Large 150 -> 100');

        $this->actingAs($owner, 'admin')
            ->delete(route('admin.menu-items.sizes.archive', [$item->id, $regular->id]))
            ->assertSessionHas('success');
        $this->assertNotNull($this->rawSize($regular->id)->archived_at);
        $this->assertSame('150.00', $this->rawItemPrice($item->id), 'Regular archived -> Large is the only live size');

        $this->actingAs($owner, 'admin')
            ->post(route('admin.menu-items.sizes.restore', [$item->id, $regular->id]))
            ->assertSessionHas('success');
        $this->assertNull($this->rawSize($regular->id)->archived_at);
        $this->assertSame('100.00', $this->rawItemPrice($item->id), 'Regular restored -> 100 again');
    }
}
