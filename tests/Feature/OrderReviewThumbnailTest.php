<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The Confirm Your Order modal (cart.blade.php) shows an item thumbnail per
 * row, matching every other place the app lists order items.
 *
 * THE BUG
 * -------
 * Reported: the modal's item review (added in an earlier pass — see
 * OrderConfirmModalTest's history of this same block) was text-only: qty,
 * name, line total, no photo. The Orders page's "Items in this order" cards
 * already render one per item (orders.blade.php: .item-img, an <img> for
 * $item->menuItem->image or a bi-image icon when there is none), so the
 * modal was the odd one out.
 *
 * THE FIX
 * -------
 * No new image field, upload flow, or query. 'image' was already carried on
 * every cart entry, and CartPricing::price() already re-derives it from the
 * LIVE MenuItem the same way it re-derives price — 'image' => $menuItem->
 * image ?? ($cartItem['image'] ?? null) — so the cart's own stored value is
 * only ever the fallback for an item that has since been deleted; the row
 * usually reflects reality even if the photo was added or changed after the
 * item was added to the cart. The template simply was not rendering it.
 * Same fallback icon (bi-image) as the Orders page, styled to this modal's
 * smaller row height rather than copying its larger box — the requirement
 * was the same visual language, not identical pixels.
 *
 * WHY THE NO-IMAGE CASE IS TESTED BY NULLING A REAL ROW
 * -------------------------------------------------------
 * First draft of this test set a cart entry's 'image' to null directly and
 * assumed that would exercise the fallback branch — it did not. Every real
 * menu item in this database has a photo, and CartPricing's re-derivation
 * above means the LIVE item's image always wins over whatever the cart entry
 * says, so the override was silently discarded before the page ever
 * rendered (caught by dumping the actual response HTML rather than trusting
 * the setup). Genuinely exercising the fallback therefore means a real
 * MenuItem with image = null, produced here by nulling one inside the test's
 * own transaction and restored on rollback — not a session value that the
 * real code path would just overwrite anyway.
 *
 * These tests render the real page through a real request rather than
 * grepping the template source, because the thing that actually matters is
 * whether a genuine image path reaches the rendered HTML — a source-level
 * check could pass on a template that referenced the wrong variable.
 */
class OrderReviewThumbnailTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function itemWithImage(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->whereNotNull('image')
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();
    }

    private function cartLine(MenuItem $item, int $qty = 1): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => $qty,
            'image'        => $item->image,
            'options'      => [],
        ]];
    }

    public function test_an_item_with_a_photo_renders_its_thumbnail(): void
    {
        $item = $this->itemWithImage();
        $customer = $this->customer();

        session(['branch_id' => 1, 'cart' => $this->cartLine($item)]);

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();

        // Scoped to the review section specifically, not the cart page's own
        // item list above it — both exist on this page and both now show a
        // photo, but this test is about the modal.
        $review = $this->reviewSectionOf($html);

        $this->assertStringContainsString('order-review-img', $review);
        $this->assertStringContainsString(
            asset($item->image),
            $review,
            "the review row did not render this item's actual image path"
        );

        // No fallback icon should appear for an item that has a photo.
        $this->assertDoesNotMatchRegularExpression(
            '/order-review-img">\s*<i class="bi bi-image"/',
            $review
        );
    }

    public function test_an_item_with_no_photo_falls_back_to_the_placeholder_icon(): void
    {
        $item = $this->itemWithImage();
        $customer = $this->customer();

        // The real code path: CartPricing re-derives the image from the LIVE
        // MenuItem, so the fallback branch can only be genuinely exercised by
        // an item whose live image is actually null — not by overriding the
        // session cart entry, which would be silently discarded. Rolled back
        // with the rest of the test by DatabaseTransactions.
        $item->update(['image' => null]);

        session(['branch_id' => 1, 'cart' => $this->cartLine($item)]);

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();

        $review = $this->reviewSectionOf($html);

        // Same fallback the Orders page already uses, for visual consistency.
        $this->assertStringContainsString('bi-image', $review);
        $this->assertStringNotContainsString(
            '<img',
            $review,
            'an item with no image on file rendered a broken <img> tag instead of the fallback icon'
        );
    }

    public function test_every_line_in_a_multi_item_order_gets_its_own_thumbnail_slot(): void
    {
        $items = MenuItem::where('is_available', true)
            ->whereNotNull('image')
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->limit(2)
            ->get();

        $this->assertGreaterThanOrEqual(2, $items->count(), 'need two distinct menu items for this test');

        // Second item genuinely has no photo, for the same reason as above.
        $items[1]->update(['image' => null]);

        $cart = array_merge(
            $this->cartLine($items[0]),
            $this->cartLine($items[1])
        );

        session(['branch_id' => 1, 'cart' => $cart]);

        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();

        $review = $this->reviewSectionOf($html);

        // One thumbnail slot per line, mixed photo/no-photo, not just the
        // first item accidentally being the only one wired up.
        $this->assertSame(
            2,
            substr_count($review, 'order-review-img'),
            'a two-item cart should produce two thumbnail slots in the review'
        );
        $this->assertStringContainsString(asset($items[0]->image), $review);
        $this->assertStringContainsString('bi-image', $review);
    }

    /**
     * Isolate the #orderReview block from the rest of the page — the cart
     * page's own item rows (outside the modal) also carry images and would
     * otherwise make these assertions pass for the wrong section.
     */
    private function reviewSectionOf(string $html): string
    {
        $start = strpos($html, 'id="orderReview"');
        $this->assertNotFalse($start, 'the confirm modal\'s order review block is missing from the page');

        $end = strpos($html, 'order-review-totals', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
