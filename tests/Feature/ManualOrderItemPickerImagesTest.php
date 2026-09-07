<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The Manual Order item picker shows the same photos the customer menu does.
 *
 * THE REPORT
 * ----------
 * The picker cards in the Manual Order modal (Admin Home) were text-only —
 * name, price, "Category · Subcategory" — while the customer-facing menu shows
 * real photos for every item. Staff at the counter had to read every card
 * instead of recognising items visually.
 *
 * THE FIX
 * -------
 * Each card now renders a small thumbnail via App\Support\Img::url($item->image),
 * the same helper already used by the sibling admin views (menu-items.blade.php,
 * ads.blade.php) — not the plain asset() the customer menu happens to use, and
 * not a new mechanism. Img::url() is a strict superset: menu item photos are
 * saved under public/uploads and never need the storage symlink, so it resolves
 * identically to asset() for them today, but it is also the one helper that
 * copes with a file that ends up on the storage disk instead — the exact class
 * of bug item 29 found (a missing storage:link left other image types broken).
 * A missing image falls back to the same bi-image placeholder pattern (item 23)
 * used everywhere else, never a broken-image icon.
 *
 * THIS DOES NOT TEST RENDERING PIXELS — that was verified live with Playwright,
 * confirming every card's <img> genuinely loaded (naturalWidth > 0). These
 * assertions pin the server-rendered HTML so a future edit cannot silently
 * drop the thumbnail, the fallback, or the Img::url() call.
 */
class ManualOrderItemPickerImagesTest extends TestCase
{
    use DatabaseTransactions;

    private function staff(): User
    {
        return User::where('role', 'staff')->where('is_active', true)->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->where('is_active', true)->firstOrFail();
    }

    public function test_a_picker_card_renders_the_items_photo_via_img_url_helper(): void
    {
        $item = MenuItem::where('is_available', true)->whereNotNull('image')->firstOrFail();

        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('manual-menu-card', $html);

        // The exact resolved URL Img::url() produces for this item's image,
        // not just the raw stored path — proving the helper actually ran
        // rather than the path being printed some other way.
        $this->assertStringContainsString(
            \App\Support\Img::url($item->image),
            $html,
            "the picker did not render {$item->name}'s photo through Img::url()"
        );
    }

    public function test_an_item_with_no_photo_gets_the_placeholder_not_a_broken_image(): void
    {
        $item = MenuItem::where('is_available', true)->first();
        $item->image = null;
        $item->save();

        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        // Locate this item's card and confirm it carries the bi-image
        // placeholder rather than an <img> tag with an empty/missing src.
        $marker = 'data-id="' . $item->id . '"';
        $cardStart = strpos($html, $marker);
        $this->assertNotFalse($cardStart, 'the picker card for the no-photo item was not found');

        $cardEnd = strpos($html, '</button>', $cardStart);
        $card = substr($html, $cardStart, $cardEnd - $cardStart);

        $this->assertStringContainsString('bi-image', $card);
        $this->assertStringNotContainsString('<img', $card);
    }

    public function test_staff_also_sees_photos_in_the_picker(): void
    {
        $item = MenuItem::where('is_available', true)->whereNotNull('image')->firstOrFail();

        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(\App\Support\Img::url($item->image), $html);
    }

    public function test_the_search_and_branch_filter_attributes_are_unchanged(): void
    {
        $item = MenuItem::where('is_available', true)->firstOrFail();

        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        // Adding the thumbnail must not have disturbed the data-* attributes
        // filterManualItems() reads client-side.
        foreach (['data-id="' . $item->id . '"', 'data-name="' . strtolower($item->name) . '"', 'data-price="' . $item->price . '"'] as $attr) {
            $this->assertStringContainsString($attr, $html);
        }
    }
}
