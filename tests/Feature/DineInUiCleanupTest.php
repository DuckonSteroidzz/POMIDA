<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Dine-In customer UI cleanup (Sept 2026). Scope of this pass:
 *
 *   1. the Cart tab is gone from the mobile bottom navigation on every
 *      customer surface that carries one (the shared partial plus the three
 *      pages with an inline copy: item-details, receipt, vouchers);
 *   2. on the Main Menu the table/branch banner sits ABOVE the search bar,
 *      rendered as a compact pill, and only on the categories view;
 *   3. the per-category item view has no banner, and its item-count badge +
 *      "Back to Categories" button sit directly above the category title;
 *   4. the "Spin & Win" card is gone from the More page (the bottom-nav tab
 *      stays);
 *   5. the item-details quantity control is an editable <input type="number">.
 *
 * These are markup-level assertions scoped to the elements under test.
 */
class DineInUiCleanupTest extends TestCase
{
    private function viewSrc(string $relative): string
    {
        return file_get_contents(resource_path("views/customer/{$relative}"));
    }

    /** The last <nav> … </nav> block in a source string (the bottom bar). */
    private function bottomNav(string $source): string
    {
        $this->assertMatchesRegularExpression('/<nav\b.*<\/nav>/s', $source);
        preg_match_all('/<nav\b.*?<\/nav>/s', $source, $m);

        return end($m[0]);
    }

    // ─────────────── 1. no Cart tab in any bottom bar ───────────────

    /** @dataProvider bottomNavBlades */
    public function test_bottom_nav_has_no_cart_tab(string $relative): void
    {
        $nav = $this->bottomNav($this->viewSrc($relative));

        $this->assertStringNotContainsString(
            "route('customer.cart')",
            $nav,
            "{$relative}: the mobile bottom nav must not carry a Cart tab"
        );
        $this->assertDoesNotMatchRegularExpression(
            '/>\s*Cart\s*</',
            $nav,
            "{$relative}: the mobile bottom nav must not carry a Cart label"
        );
    }

    public static function bottomNavBlades(): array
    {
        return [
            'shared partial' => ['partials/navbar.blade.php'],
            'item-details'   => ['item-details.blade.php'],
            'receipt'        => ['receipt.blade.php'],
            'vouchers'       => ['vouchers.blade.php'],
        ];
    }

    public function test_shared_bottom_nav_keeps_its_four_primary_tabs(): void
    {
        $nav = $this->bottomNav($this->viewSrc('partials/navbar.blade.php'));

        foreach (['customer.menu', 'customer.orders', 'customer.game', 'customer.more'] as $route) {
            $this->assertStringContainsString("route('{$route}')", $nav, "the bottom nav lost its {$route} tab");
        }
    }

    public function test_mobile_still_has_a_cart_shortcut_in_the_header(): void
    {
        // Removing the bottom-bar Cart tab is only safe because the header
        // keeps a mobile (md:hidden) cart button.
        $partial = $this->viewSrc('partials/desktop-nav.blade.php');

        $this->assertStringContainsString('Mobile cart shortcut', $partial);
        $this->assertMatchesRegularExpression(
            '/Mobile cart shortcut.*?route\(\'customer\.cart\'\).*?md:hidden/s',
            $partial,
            'the shared header partial must keep a md:hidden cart shortcut'
        );
    }

    // ─────────────── 2. Main Menu: pill above the search bar ───────────────

    public function test_main_menu_banner_is_a_pill_above_the_search_bar(): void
    {
        $menu = $this->viewSrc('menu.blade.php');

        $bannerPos = strpos($menu, 'Dine-in • Table');
        $searchPos = strpos($menu, 'id="searchInput"');

        $this->assertNotFalse($bannerPos, 'the Main Menu lost its table/branch banner');
        $this->assertNotFalse($searchPos);
        $this->assertLessThan($searchPos, $bannerPos, 'the table/branch banner must sit ABOVE the search bar');

        // Compact pill, reusing the existing peach-soft token — not the old
        // full card-surface section.
        $pill = substr($menu, $bannerPos - 800, 800);
        $this->assertStringContainsString('rounded-full', $pill);
        $this->assertStringContainsString('bg-peach-soft', $pill);
        $this->assertStringContainsString('text-xs', $pill);
    }

    public function test_main_menu_banner_is_hidden_on_the_category_items_view(): void
    {
        $menu = $this->viewSrc('menu.blade.php');

        $bannerPos = strpos($menu, 'Dine-in • Table');
        $guard = substr($menu, $bannerPos - 800, 800);

        $this->assertStringContainsString('!isset($items)', $guard, 'the banner must not render on the per-category item view');

        // And the old in-content card banner is gone entirely.
        $this->assertStringNotContainsString('Dine-in at:', $menu, 'the old full-width dine-in card banner should be removed');
    }

    // ─────────────── 3. category header order ───────────────

    public function test_category_view_puts_count_and_back_above_the_title(): void
    {
        $menu = $this->viewSrc('menu.blade.php');

        $backPos  = strpos($menu, 'Back to Categories');
        $countPos = strpos($menu, "count(\$items) === 1 ? 'item' : 'items'");
        $titlePos = strpos($menu, "\$category->name ?? 'Items'");

        $this->assertNotFalse($backPos);
        $this->assertNotFalse($countPos);
        $this->assertNotFalse($titlePos);

        $this->assertLessThan($titlePos, $backPos, '"Back to Categories" must come before the category title');
        $this->assertLessThan($titlePos, $countPos, 'the item-count badge must come before the category title');
    }

    // ─────────────── 4. More page ───────────────

    public function test_more_page_has_no_spin_and_win_card(): void
    {
        $this->assertStringNotContainsString('Play while you wait', $this->viewSrc('more.blade.php'));
    }

    // ─────────────── 5. editable quantity on item-details ───────────────

    public function test_item_details_quantity_is_an_editable_number_input(): void
    {
        $src = $this->viewSrc('item-details.blade.php');

        foreach (['quantityDesktop', 'quantityMobile'] as $id) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*type="number"[^>]*id="' . $id . '"[^>]*>/s',
                $src,
                "#{$id} must be an <input type=\"number\">"
            );
        }

        // The static spans between +/- are gone.
        $this->assertDoesNotMatchRegularExpression(
            '/<span[^>]*id="quantity(Desktop|Mobile)"/',
            $src,
            'the old static quantity <span> must be replaced'
        );

        // Client ceiling + graceful reset on blur/submit.
        $this->assertStringContainsString('max="99"', $src);
        $this->assertStringContainsString('function normalizeQuantity', $src);
        $this->assertStringContainsString("addEventListener('blur'", $src);
        $this->assertStringContainsString("addToCartForm.addEventListener('submit'", $src);

        // The +/- buttons still drive the same value.
        $this->assertStringContainsString('onclick="changeQuantity(-1)"', $src);
        $this->assertStringContainsString('onclick="changeQuantity(1)"', $src);
    }
}
