<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AUTO-DEDUCTION, END TO END — the reported "coffee never goes down" bug.
 *
 * Existing coverage (MenuItemRecipeCostTest, OptionBranchAwareDeductionTest)
 * seeds an Order row straight into the database and then completes it. That
 * skips the half of the journey the bug actually lives in: the customer
 * CHECKOUT, which is what writes order_items / order_item_options in the
 * first place. These tests drive the whole real path instead —
 *
 *     POST /customer/place-order   ->  PUT /admin/orders/{id}/complete
 *
 * — and assert the pantry, the audit log and the two features that read the
 * pantry live (the "N left" badge and the checkout stock guard) all agree
 * afterwards.
 *
 * Every row created here carries the DEDE prefix and the whole file runs in
 * DatabaseTransactions against pomida_db_testing, so nothing survives a run.
 */
class InventoryDeductionEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'DEDE';
    private const BRANCH = 1;

    // ══════════════════════════ fixtures ══════════════════════════

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function inventory(string $name, float $qty, string $unit = 'g'): Inventory
    {
        return Inventory::create([
            'branch_id'       => self::BRANCH,
            'item_name'       => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'item_code'       => 'DEDE-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => $qty,
            'unit'            => $unit,
            'low_stock_alert' => 1,
            'unit_cost'       => 2,
            'is_active'       => true,
        ]);
    }

    /** @param array<int, array{0: Inventory, 1: float}> $recipe */
    private function menuItem(string $name, array $recipe, float $price = 60): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => self::BRANCH,
            'name'          => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'price'         => $price,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        foreach ($recipe as [$inv, $qty]) {
            MenuItemIngredient::create([
                'menu_item_id'  => $item->id,
                'inventory_id'  => $inv->id,
                'quantity_used' => $qty,
            ]);
        }

        return $item;
    }

    private function optionUsing(Inventory $inv, float $qty = 1): MenuOption
    {
        $option = MenuOption::create([
            'name'             => self::PREFIX . ' extra creamer ' . uniqid(),
            'additional_price' => 10,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $inv->id,
            'quantity_used'  => $qty,
        ]);

        return $option;
    }

    /**
     * One session-cart line in exactly the shape AuthController::addToCart()
     * writes and CartPricing::price() reads.
     *
     * @param  MenuOption[]  $options
     */
    private function cartLine(MenuItem $item, int $qty, array $options = []): array
    {
        $optionPayload = array_map(fn (MenuOption $o) => [
            'id'    => $o->id,
            'name'  => $o->name,
            'price' => (float) $o->additional_price,
        ], $options);

        $key = $options
            ? $item->id . '-' . implode('-', array_column($optionPayload, 'id'))
            : (string) $item->id;

        return [$key => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price + array_sum(array_column($optionPayload, 'price')),
            'base_price'   => (float) $item->price,
            'quantity'     => $qty,
            'image'        => $item->image,
            'options'      => $optionPayload,
        ]];
    }

    /** Place the cart through the real customer checkout. Returns the Order, or null if refused. */
    private function checkout(array $cart, array $items): ?Order
    {
        $before = (int) Order::max('id');

        $this->withSession([
            'cart'         => $cart,
            'branch_id'    => self::BRANCH,
            'order_type'   => 'dine_in',
            'table_number' => '9',
        ])->post('/customer/place-order', [
            'order_type'     => 'dine_in',
            'table_number'   => '9',
            'payment_method' => 'cash',
            'items'          => $items,
        ]);

        return Order::where('id', '>', $before)->orderByDesc('id')->first();
    }

    private function complete(Order $order): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/orders/' . $order->id . '/complete');
    }

    private function movementsFor(Order $order, Inventory $inv): array
    {
        return StockMovement::where('reference_id', $order->id)
            ->where('source', 'order')
            ->where('inventory_id', $inv->id)
            ->get()
            ->all();
    }

    // ══════════════════ the reported bug ══════════════════

    public function test_completing_a_coffee_order_walks_the_whole_recipe_and_deducts_every_ingredient(): void
    {
        $beans   = $this->inventory('Coffee Beans', 500);
        $sugar   = $this->inventory('White Sugar', 500);
        $creamer = $this->inventory('Creamer', 50, 'pcs');

        $coffee = $this->menuItem('Brewed Coffee', [[$beans, 15], [$sugar, 5]]);
        $extra  = $this->optionUsing($creamer, 1);

        $order = $this->checkout(
            $this->cartLine($coffee, 2, [$extra]),
            [['menu_item_id' => $coffee->id, 'quantity' => 2]]
        );

        $this->assertNotNull($order, 'checkout refused an order the pantry can clearly cover');

        // Placing must NOT deduct — stock is only committed until staff completes.
        $this->assertSame(500.0, (float) $beans->fresh()->quantity);

        $this->complete($order);

        $this->assertSame('completed', $order->fresh()->status, 'the order never reached completed');

        // 2 servings: 30 g beans, 10 g sugar, and 2 creamer from the add-on.
        $this->assertSame(470.0, (float) $beans->fresh()->quantity, '2 x 15 g of beans');
        $this->assertSame(490.0, (float) $sugar->fresh()->quantity, '2 x 5 g of sugar');
        $this->assertSame(48.0, (float) $creamer->fresh()->quantity, '2 x 1 creamer from the selected add-on');
    }

    public function test_a_second_recipe_item_in_the_same_order_is_deducted_too(): void
    {
        $dough  = $this->inventory('Dough', 100);
        $cheese = $this->inventory('Cheese', 100);
        $beans  = $this->inventory('Coffee Beans', 100);

        $coffee = $this->menuItem('Brewed Coffee', [[$beans, 15]]);
        $pizza  = $this->menuItem('Cheesy Pizza', [[$dough, 20], [$cheese, 30]]);

        $cart = $this->cartLine($coffee, 1) + $this->cartLine($pizza, 1);

        $order = $this->checkout($cart, [
            ['menu_item_id' => $coffee->id, 'quantity' => 1],
            ['menu_item_id' => $pizza->id, 'quantity' => 1],
        ]);

        $this->assertNotNull($order);
        $this->complete($order);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(85.0, (float) $beans->fresh()->quantity);
        $this->assertSame(80.0, (float) $dough->fresh()->quantity);
        $this->assertSame(70.0, (float) $cheese->fresh()->quantity);
    }

    // ══════════════════ feature 4: the audit log ══════════════════

    public function test_every_deduction_is_written_to_stock_movements(): void
    {
        $beans = $this->inventory('Coffee Beans', 500);
        $sugar = $this->inventory('White Sugar', 500);
        $coffee = $this->menuItem('Brewed Coffee', [[$beans, 15], [$sugar, 5]]);

        $order = $this->checkout(
            $this->cartLine($coffee, 3),
            [['menu_item_id' => $coffee->id, 'quantity' => 3]]
        );
        $this->assertNotNull($order);
        $this->complete($order);

        $beanMoves = $this->movementsFor($order, $beans);
        $this->assertCount(1, $beanMoves, 'one movement row per (order line x inventory item)');

        $move = $beanMoves[0];
        $this->assertSame('out', $move->movement_type);
        $this->assertSame(45.0, (float) $move->amount, '3 x 15 g');
        $this->assertSame(455.0, (float) $move->quantity_after, 'the running balance after the deduction');
        $this->assertSame($order->id, (int) $move->reference_id);
        $this->assertStringContainsString($order->order_number, $move->reason);

        $this->assertCount(1, $this->movementsFor($order, $sugar));
    }

    // ══════════════════ feature 1+2: badge and stock guard see it immediately ══════════════════

    public function test_the_low_stock_badge_and_the_checkout_guard_both_see_the_new_quantity(): void
    {
        // Exactly 4 servings on the shelf.
        $beans  = $this->inventory('Coffee Beans', 60);
        $coffee = $this->menuItem('Brewed Coffee', [[$beans, 15]]);

        $deduction = app(\App\Services\InventoryDeductionService::class);

        $this->assertSame(4, $deduction->unitsAvailableFor($coffee, self::BRANCH));

        $order = $this->checkout(
            $this->cartLine($coffee, 3),
            [['menu_item_id' => $coffee->id, 'quantity' => 3]]
        );
        $this->assertNotNull($order);

        // Placed but not completed: the pantry is untouched, yet only 1 is still
        // promisable because the open order has committed the other 3.
        $this->assertSame(60.0, (float) $beans->fresh()->quantity);
        $this->assertSame(1, $deduction->unitsAvailableFor($coffee->fresh(), self::BRANCH));

        $this->complete($order);

        // Completed: the 3 servings have left the pantry for real, and the count
        // must NOT drop again — the committed stock became spent stock.
        $this->assertSame(15.0, (float) $beans->fresh()->quantity);
        $this->assertSame(1, $deduction->unitsAvailableFor($coffee->fresh(), self::BRANCH));

        // And the customer-facing badge agrees, rendered, on the real page.
        $html = $this->withSession(['branch_id' => self::BRANCH, 'order_type' => 'pick_up'])
            ->get('/customer/items/' . $coffee->category_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '1 stocks left',
            $this->cardFor($html, $coffee->name),
            'the "N stocks left" badge did not follow the completed deduction'
        );
    }

    /**
     * The slice of the menu HTML around one item's own card. The category page
     * lists every item in the category, so asserting a badge against the whole
     * document would happily pass on some other item's badge.
     */
    private function cardFor(string $html, string $itemName): string
    {
        $at = strpos($html, e($itemName));
        $this->assertNotFalse($at, 'the item never appeared on the menu page at all');

        $from = max(0, $at - 1500);

        return substr($html, $from, 3000);
    }

    // ══════════════════ fractional recipes ══════════════════

    public function test_a_fractional_recipe_line_actually_moves_the_shelf(): void
    {
        // THE REPORTED SYMPTOM, reproduced. Recipes are stored to three
        // decimals (menu_item_ingredients.quantity_used is decimal(10,3)) but
        // inventory.quantity was decimal(10,2), so any recipe finer than a
        // hundredth was rounded away the moment it was written back: the
        // movement row said "0.004 L out", the shelf said the same number it
        // said before, and nothing anywhere reported a failure.
        $syrup = $this->inventory('Vanilla Syrup', 10, 'L');
        $latte = $this->menuItem('Vanilla Latte', [[$syrup, 0.004]]);

        $order = $this->checkout(
            $this->cartLine($latte, 1),
            [['menu_item_id' => $latte->id, 'quantity' => 1]]
        );
        $this->assertNotNull($order);
        $this->complete($order);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(
            9.996,
            (float) $syrup->fresh()->quantity,
            'a 0.004 L deduction must leave the shelf, not round back to where it started'
        );

        // The audit log and the shelf must tell the same story.
        $move = $this->movementsFor($order, $syrup)[0];
        $this->assertSame(0.004, (float) $move->amount);
        $this->assertSame(
            (float) $syrup->fresh()->quantity,
            (float) $move->quantity_after,
            'quantity_after must equal what the inventory row actually holds'
        );
    }

    // ══════════════════ feature 3: archived (soft-deleted) ingredients ══════════════════

    public function test_an_archived_ingredient_still_deducts_instead_of_crashing_the_completion(): void
    {
        // Archiving an ingredient takes it off the inventory LIST. It must not
        // take it out of the recipes that already point at it — the pantry is
        // still physically being consumed.
        $beans = $this->inventory('Coffee Beans', 100);
        $coffee = $this->menuItem('Brewed Coffee', [[$beans, 15]]);

        $order = $this->checkout(
            $this->cartLine($coffee, 1),
            [['menu_item_id' => $coffee->id, 'quantity' => 1]]
        );
        $this->assertNotNull($order);

        $beans->archive();
        $this->assertTrue($beans->fresh()->isArchived());

        $this->complete($order);

        $this->assertSame('completed', $order->fresh()->status, 'an archived ingredient must not block completion');
        $this->assertSame(85.0, (float) $beans->fresh()->quantity);
        $this->assertCount(1, $this->movementsFor($order, $beans));
    }

    // ══════════════════ feature 2: the guard must cover the counter too ══════════════════

    /**
     * The walk-in counter is the other door into the same pantry. It validated
     * against the RAW inventory.quantity — no committed stock subtracted, no
     * row lock, and outside the order transaction — while the customer
     * checkout had already been taught to do all three. So an online order
     * that had spoken for the last serving did not stop staff selling it again
     * at the counter, and because a manual order is written
     * payment_status = 'paid', the shortage only surfaced when staff tried to
     * complete an order the customer had already paid for.
     */
    public function test_a_walk_in_order_cannot_claim_stock_an_online_order_already_committed(): void
    {
        $beans  = $this->inventory('Coffee Beans', 15);   // exactly one serving
        $coffee = $this->menuItem('Brewed Coffee', [[$beans, 15]]);

        $online = $this->checkout(
            $this->cartLine($coffee, 1),
            [['menu_item_id' => $coffee->id, 'quantity' => 1]]
        );
        $this->assertNotNull($online, 'the online order takes the only serving');
        $this->assertSame('pending', $online->fresh()->status);

        // The pantry still physically reads 15 g — deduction happens at
        // completion — which is exactly what used to wave the counter through.
        $this->assertSame(15.0, (float) $beans->fresh()->quantity);

        $before = (int) Order::max('id');

        $response = $this->actingAs($this->admin(), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', [
                'branch_id'      => self::BRANCH,
                'order_type'     => 'pick_up',
                'table_number'   => '',
                'payment_method' => 'cash',
                'amount_paid'    => '1000',
                'items'          => [
                    $coffee->id => ['menu_item_id' => (string) $coffee->id, 'quantity' => '1'],
                ],
            ]);

        $walkIn = Order::where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNull(
            $walkIn,
            'the counter sold a serving the online order had already committed — this is the oversell'
        );
        $response->assertSessionHasErrors();
    }

    public function test_the_counter_still_sells_what_the_pantry_can_genuinely_cover(): void
    {
        $beans  = $this->inventory('Coffee Beans', 30);   // two servings
        $coffee = $this->menuItem('Brewed Coffee', [[$beans, 15]]);

        $online = $this->checkout(
            $this->cartLine($coffee, 1),
            [['menu_item_id' => $coffee->id, 'quantity' => 1]]
        );
        $this->assertNotNull($online);

        $before = (int) Order::max('id');

        $this->actingAs($this->admin(), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', [
                'branch_id'      => self::BRANCH,
                'order_type'     => 'pick_up',
                'table_number'   => '',
                'payment_method' => 'cash',
                'amount_paid'    => '1000',
                'items'          => [
                    $coffee->id => ['menu_item_id' => (string) $coffee->id, 'quantity' => '1'],
                ],
            ])->assertSessionHasNoErrors();

        $walkIn = Order::where('id', '>', $before)->orderByDesc('id')->first();
        $this->assertNotNull($walkIn, 'the second serving is genuinely there and must still be sellable');

        // Both orders complete, and the pantry ends at exactly zero.
        $this->complete($online->fresh());
        $this->complete($walkIn);

        $this->assertSame('completed', $walkIn->fresh()->status);
        $this->assertSame(0.0, (float) $beans->fresh()->quantity);
        $this->assertCount(1, $this->movementsFor($walkIn, $beans));
    }
}
