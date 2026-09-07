<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The desktop header is a 2-column CSS grid
 * (grid-cols-[minmax(0,1fr)_auto]): logo in column one, everything else in
 * column two. When the nav, the notification bell, and the mobile cart icon
 * are separate direct children of that grid instead of one wrapped unit,
 * grid auto-placement wraps the extras onto an implicit second row using the
 * same two-column template — landing the bell alone on its own line instead
 * of grouped next to Orders/Menu/Game/Cart. That happened on the Game and
 * Vouchers pages (2026-09-01) even though the markup "looked" fine read
 * top-to-bottom; only the DOM's actual grid placement showed the bug.
 *
 * The whole header-actions cluster now lives in ONE shared partial —
 * customer/partials/desktop-nav.blade.php — so the wrapper can only be right
 * or wrong in one place. These tests check (a) every page drops that partial
 * into its 2-column grid header, and (b) the partial keeps the nav, the bell
 * and the mobile cart inside a single flex wrapper.
 */
class HeaderBellPlacementTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function pagesWithADesktopHeader(): array
    {
        return [
            'orders'          => ['orders'],
            'cart'            => ['cart'],
            'game'            => ['game'],
            'vouchers'        => ['vouchers'],
            'menu'            => ['menu'],
            'more'            => ['more'],
            'item-details'    => ['item-details'],
        ];
    }

    /**
     * @dataProvider pagesWithADesktopHeader
     */
    public function test_the_page_includes_the_shared_header_actions_partial_in_its_grid_header(string $page): void
    {
        $source = file_get_contents(resource_path("views/customer/{$page}.blade.php"));

        $gridPos = strpos($source, 'grid-cols-[minmax(0,1fr)_auto]');
        $this->assertNotFalse(
            $gridPos,
            "customer/{$page}.blade.php no longer uses the 2-column header grid this test assumes"
        );

        $includePos = strpos($source, "@include('customer.partials.desktop-nav')", $gridPos);
        $this->assertNotFalse(
            $includePos,
            "customer/{$page}.blade.php does not include customer/partials/desktop-nav inside its header — "
            . 'the header-actions cluster must come from that shared partial so the grid-placement '
            . 'wrapper is defined in exactly one place'
        );
    }

    /**
     * The shared partial itself: the nav, the bell include and the mobile cart
     * link must all sit inside one "flex items-center" wrapper, in that order,
     * so CSS grid auto-placement in the caller cannot push the bell onto its
     * own row.
     */
    public function test_the_shared_partial_wraps_the_nav_bell_and_mobile_cart_together(): void
    {
        $source = file_get_contents(resource_path('views/customer/partials/desktop-nav.blade.php'));

        $wrapPos = strpos($source, 'flex items-center justify-end');
        $this->assertNotFalse($wrapPos, 'desktop-nav.blade.php is missing its flex wrapper');

        $navPos = strpos($source, '<nav class="hidden items-center gap-1 md:flex">', $wrapPos);
        $this->assertNotFalse($navPos, 'desktop-nav.blade.php has no desktop <nav>');

        $bellPos = strpos($source, "@include('customer.partials.notification-bell')", $navPos);
        $this->assertNotFalse($bellPos, 'desktop-nav.blade.php does not include the notification bell after its nav');

        $mobileCartPos = strpos($source, 'md:hidden', $bellPos);
        $this->assertNotFalse($mobileCartPos, 'desktop-nav.blade.php has no mobile cart shortcut after the bell');

        // The wrapper opens before all three.
        $this->assertLessThan($navPos, $wrapPos);
        $this->assertLessThan($bellPos, $navPos);
        $this->assertLessThan($mobileCartPos, $bellPos);
    }

    /**
     * All four primary links plus the Cart button are present in the shared nav.
     */
    public function test_the_shared_nav_carries_every_primary_link(): void
    {
        $source = file_get_contents(resource_path('views/customer/partials/desktop-nav.blade.php'));

        foreach (['customer.orders', 'customer.menu', 'customer.game', 'customer.more', 'customer.cart'] as $route) {
            $this->assertStringContainsString(
                "route('{$route}')",
                $source,
                "the shared desktop nav is missing a link to {$route}"
            );
        }
    }
}
