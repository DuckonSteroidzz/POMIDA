<?php

namespace Tests\Feature;

use App\Services\MenuItemSizes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\MenuItemSizeOrderFixtures;
use Tests\TestCase;

/**
 * Menu Item Sizes, Phase 2 — the walk-in counter (scenarios 13-14).
 *
 * The counter is held to exactly what customer checkout is: a size is
 * required and must be the item's own and sellable; the same size-aware
 * stock gate (counting what open orders hold); the same freeze at placement.
 * It writes payment_status = 'paid' on the spot, so a weaker path here would
 * be the worst place for one.
 */
class MenuItemSizesCounterTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    private function line($item, $size, int $qty = 1, array $options = []): array
    {
        $row = ['menu_item_id' => (string) $item->id, 'quantity' => (string) $qty];

        if ($size !== null) {
            $row['size_id'] = (string) $size->id;
        }

        if ($options) {
            $row['options'] = array_map(fn ($o) => (string) $o->id, $options);
        }

        return $row;
    }

    // ══════════ 13. A size is required at the counter too ══════════

    public function test_the_counter_requires_a_valid_sellable_size_for_a_sized_item(): void
    {
        $branch = $this->sizeBranch('A');
        $staff = $this->sizeStaff($branch->id);
        $f = $this->sizedCoffee($branch);
        $other = $this->sizedCoffee($branch, 100, 100, 'Other Coffee');
        $plain = $this->unsizedItem($branch, $this->sizeInventory($branch->id, 50));

        $this->counterOrder($staff, $branch, ['a' => $this->line($f['item'], null)])
            ->assertSessionHasErrors(['items' => 'Please choose a size (Regular or Large) for Test Coffee.']);

        $this->counterOrder($staff, $branch, ['a' => $this->line($f['item'], $other['large'])])
            ->assertSessionHasErrors(['items' => 'Sorry, that size of Test Coffee is not available.']);

        $this->counterOrder($staff, $branch, ['a' => $this->line($plain, $f['large'])])
            ->assertSessionHasErrors(['items' => 'Sorry, that size of Plain Tea is not available.']);

        app(MenuItemSizes::class)->archive($f['large']);
        $this->counterOrder($staff, $branch, ['a' => $this->line($f['item'], $f['large'])])
            ->assertSessionHasErrors(['items' => 'Sorry, Test Coffee (Large) is no longer available.']);

        $noRecipe = $this->sizeItem($branch->id, 'Bare Coffee');
        [, $bareLarge] = $this->enableSizes($noRecipe, 100, 150);
        $this->counterOrder($staff, $branch, ['a' => $this->line($noRecipe, $bareLarge)])
            ->assertSessionHasErrors(['items' => 'Sorry, Bare Coffee (Large) is unavailable right now — no recipe has been set for it yet.']);

        $this->assertCount(0, $this->ordersAt($branch), 'the counter recorded nothing');
    }

    // ══════════ 14. Same stock gate, same freeze ══════════

    public function test_the_counter_gets_the_same_size_aware_stock_gate(): void
    {
        $branch = $this->sizeBranch('A');
        $staff = $this->sizeStaff($branch->id);
        $f = $this->sizedCoffee($branch, 100, 2); // exactly 1 Large

        // An online order already holds the last Large.
        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], 1))
            ->assertRedirect(route('customer.orders'));

        $this->counterOrder($staff, $branch, ['a' => $this->line($f['item'], $f['large'])])
            ->assertSessionHasErrors(['items' => 'Sorry, Test Coffee (Large) is currently out of stock due to ingredient availability — please remove it from your order.']);
        $this->assertCount(1, $this->ordersAt($branch));

        // Regular, and the size-less base recipe's EMPTY shelf, are irrelevant to it.
        $this->counterOrder($staff, $branch, ['a' => $this->line($f['item'], $f['regular'], 3)])
            ->assertSessionHas('success');
        $this->assertCount(2, $this->ordersAt($branch));
    }

    public function test_a_counter_order_records_the_size_freezes_it_and_completes_from_the_freeze(): void
    {
        $branch = $this->sizeBranch('A');
        $staff = $this->sizeStaff($branch->id);
        $f = $this->sizedCoffee($branch);
        $shot = $this->addOn($f['item'], $branch, 20);

        $this->counterOrder($staff, $branch, [
            'a' => $this->line($f['item'], $f['large'], 2, [$shot]),
            'b' => $this->line($f['item'], $f['regular'], 1),
        ])->assertSessionHas('success');

        $order = $this->latestOrderAt($branch);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('440.00', (string) $order->subtotal, '2 x (150 + 20) + 100');

        $large = $order->items()->where('size_name', 'Large')->sole();
        $regular = $order->items()->where('size_name', 'Regular')->sole();
        $this->assertSame('170.00', (string) $large->item_price);
        $this->assertSame('100.00', (string) $regular->item_price);
        $this->assertSame($f['large']->id, (int) $large->menu_item_size_id);
        $this->assertSame([$f['largeInv']->id => '2.000'], $this->frozenRows($large->id));
        $this->assertSame([$f['regInv']->id => '1.000'], $this->frozenRows($regular->id));

        // Archive Large; the paid counter order still completes, from the freeze.
        app(MenuItemSizes::class)->archive($f['large']);
        $this->completeAs($staff, $order);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(96.0, $this->rawQty($f['largeInv']));
        $this->assertSame(99.0, $this->rawQty($f['regInv']));
        $this->assertSame(0.0, $this->rawQty($f['baseInv']));
    }

    // ══════════ The counter UI ══════════

    public function test_the_counter_card_offers_the_sizes_and_is_judged_by_them_not_the_base_recipe(): void
    {
        $branch = $this->sizeBranch('A');
        $staff = $this->sizeStaff($branch->id);

        // Sized, with NO base recipe at all: sellable by size.
        $item = $this->sizeItem($branch->id, 'Card Coffee');
        [$regular, $large] = $this->enableSizes($item, 100, 150);
        $this->sizeRecipeLine($regular, $this->sizeInventory($branch->id, 50), 1);

        // Sized with no size recipes: nothing can be sold.
        $bare = $this->sizeItem($branch->id, 'Bare Card Coffee');
        $this->enableSizes($bare, 80, 120);

        $html = $this->actingAs($staff, 'admin')->get('/admin/home')->assertOk()->getContent();

        $card = $this->cardFor($html, $item->id);
        $this->assertStringContainsString('data-sizes="', $card);
        $this->assertStringContainsString('&quot;id&quot;:' . $regular->id . ',&quot;name&quot;:&quot;Regular&quot;,&quot;price&quot;:100', $card);
        $this->assertStringContainsString('&quot;name&quot;:&quot;Large&quot;,&quot;price&quot;:150,&quot;orderable&quot;:false,&quot;label&quot;:&quot;No Recipe Set&quot;', $card);
        $this->assertStringContainsString('onclick="addManualItem(' . $item->id . ')"', $card, 'enabled: Regular can be sold');
        $this->assertStringContainsString('From', $card);

        $bareCard = $this->cardFor($html, $bare->id);
        $this->assertStringContainsString('disabled', $bareCard);
        $this->assertStringContainsString('No size of this item can be sold yet', $bareCard);

        // The modal the JS fills.
        $this->assertStringContainsString('id="manualSizesList"', $html);
        $this->assertStringContainsString('items[${safeKey}][size_id]', $html);
    }

    /** One counter menu card, from its data-id to its closing </button>. */
    private function cardFor(string $html, int $itemId): string
    {
        $start = strpos($html, 'data-id="' . $itemId . '"');
        $this->assertNotFalse($start, 'card rendered for item ' . $itemId);

        return substr($html, $start, (int) strpos($html, '</button>', $start) - $start);
    }
}
