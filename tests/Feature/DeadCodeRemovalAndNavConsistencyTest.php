<?php

namespace Tests\Feature;

use App\Services\InventoryDeductionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sept 2026 cleanup pass:
 *   - three proven-dead targets removed (customer/payment, admin/order-detail,
 *     InventoryDeductionService::deductForOrder);
 *   - the "All Staff" and "Add New Option" admin forms no longer push their
 *     page sideways on a phone;
 *   - the Spin & Win feature is called "Spin & Win" on every customer nav
 *     surface (was "Game" in four of them);
 *   - the shared customer bottom bar has a fifth slot for Spin & Win.
 *
 * These assertions are deliberately scoped to the element under test.
 */
class DeadCodeRemovalAndNavConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    /** @return string The <nav aria-label="Customer navigation"> markup only. */
    private function sharedBottomNav(string $path): string
    {
        $html = $this->get($path)->assertOk()->getContent();

        $start = strpos($html, '<nav');
        while ($start !== false) {
            $tagEnd = strpos($html, '>', $start);
            $openTag = substr($html, $start, $tagEnd - $start);
            if (str_contains($openTag, 'aria-label="Customer navigation"')) {
                $end = strpos($html, '</nav>', $start);
                return substr($html, $start, $end - $start + 6);
            }
            $start = strpos($html, '<nav', $start + 4);
        }

        $this->fail("No shared customer navigation on {$path}");
    }

    // ─────────────── dead code is gone ───────────────

    public function test_dead_routes_no_longer_exist(): void
    {
        $routes = Route::getRoutes();

        $this->assertNull($routes->getByName('customer.payment'), 'GET customer/payment should be removed');
        $this->assertNull($routes->getByName('customer.payment.post'), 'POST customer/payment should be removed');
        $this->assertNull($routes->getByName('admin.order-detail'), 'admin/order-detail should be removed');
    }

    public function test_dead_paths_are_not_routable(): void
    {
        $this->get('/customer/payment')->assertNotFound();
        $this->post('/customer/payment')->assertNotFound();
        $this->get('/admin/order-detail/1')->assertNotFound();
    }

    public function test_orphaned_payment_view_is_deleted(): void
    {
        $this->assertFalse(
            view()->exists('customer.payment'),
            'resources/views/customer/payment.blade.php should be deleted'
        );
    }

    public function test_deprecated_deduct_for_order_method_is_removed(): void
    {
        $this->assertFalse(
            method_exists(InventoryDeductionService::class, 'deductForOrder'),
            'InventoryDeductionService::deductForOrder() had zero call sites and should be removed'
        );
    }

    /**
     * Sweep every registered route: a controller route must point at a method
     * that actually exists. This is what makes the three deletions above safe
     * to keep — nothing else in the app quietly depends on a missing target.
     */
    public function test_every_route_resolves_to_a_real_controller_action(): void
    {
        $broken = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                $broken[] = $route->methods()[0] . ' ' . $route->uri() . ' → ' . $action;
            }
        }

        $this->assertSame([], $broken, "Routes pointing at a missing action:\n" . implode("\n", $broken));
    }

    // ─────────────── the game route itself is untouched ───────────────

    public function test_spin_and_win_route_still_resolves(): void
    {
        $routes = Route::getRoutes();

        $this->assertNotNull($routes->getByName('customer.game'), 'the game route name must not change');
        $this->assertSame('customer/game', $routes->getByName('customer.game')->uri());
    }

    // ─────────────── one name for one feature ───────────────

    /**
     * @dataProvider customerNavPages
     */
    public function test_customer_nav_surfaces_say_spin_and_win_not_game(string $path): void
    {
        $nav = $this->sharedBottomNav($path);

        $this->assertStringContainsString('Spin &amp; Win', $nav, "{$path} bottom nav should label the feature 'Spin & Win'");
        $this->assertStringNotContainsString('>Game<', $nav, "{$path} bottom nav should not still say 'Game'");
    }

    public static function customerNavPages(): array
    {
        return [
            'menu'   => ['/customer/menu'],
            'game'   => ['/customer/game'],
            'more'   => ['/customer/more'],
            'orders' => ['/customer/orders'],
        ];
    }

    /**
     * No nav link to the game route, on any customer surface, may still be
     * labelled "Game" — the feature is "Spin & Win" everywhere now. Scans the
     * blades that carry the link inside a <nav>.
     */
    public function test_no_customer_nav_link_to_the_game_route_is_labelled_game(): void
    {
        $blades = [
            'resources/views/customer/partials/navbar.blade.php',
            'resources/views/customer/partials/desktop-nav.blade.php',
            'resources/views/customer/account-settings.blade.php',
            'resources/views/customer/receipt.blade.php',
            'resources/views/customer/vouchers.blade.php',
        ];

        foreach ($blades as $blade) {
            $src = file_get_contents(base_path($blade));

            // Only anchors that sit inside a <nav> … </nav>.
            preg_match_all('/<nav\b.*?<\/nav>/s', $src, $navs);

            foreach ($navs[0] as $nav) {
                foreach (preg_split('/(?=<a\b)/', $nav) as $anchor) {
                    if (! str_contains($anchor, "route('customer.game')")) {
                        continue;
                    }

                    $anchor = substr($anchor, 0, strpos($anchor, '</a>') ?: null);

                    $this->assertDoesNotMatchRegularExpression(
                        '/>\s*Game\s*</',
                        $anchor,
                        "{$blade}: a nav link to the game route is still labelled 'Game'"
                    );
                    $this->assertStringContainsString(
                        'Spin &amp; Win',
                        $anchor,
                        "{$blade}: the nav game link should read 'Spin & Win'"
                    );
                }
            }
        }
    }

    // ─────────────── the fifth bottom-bar slot ───────────────

    public function test_shared_bottom_bar_has_five_slots_in_order(): void
    {
        $nav = $this->sharedBottomNav('/customer/menu');

        preg_match_all('/<span>([^<]+)<\/span>/', $nav, $m);
        $labels = array_values(array_filter(array_map('trim', $m[1]), fn ($l) => $l !== ''));

        $this->assertSame(
            ['Menu', 'Orders', 'Spin &amp; Win', 'More', 'Cart'],
            $labels,
            'shared bottom bar order must be Menu · Orders · Spin & Win · More · Cart'
        );
    }

    public function test_shared_bottom_bar_marks_the_active_slot_per_route(): void
    {
        // On the game page the Spin & Win slot is the active one …
        $gameNav = $this->sharedBottomNav('/customer/game');
        $this->assertMatchesRegularExpression(
            '/customer\/game[^>]*bg-peach-soft text-peach-red/s',
            $gameNav,
            'Spin & Win slot should carry the active pill on /customer/game'
        );

        // … and not on the menu page.
        $menuNav = $this->sharedBottomNav('/customer/menu');
        $this->assertDoesNotMatchRegularExpression(
            '/customer\/game[^>]*bg-peach-soft text-peach-red/s',
            $menuNav,
            'Spin & Win slot should be inactive on /customer/menu'
        );
        $this->assertMatchesRegularExpression(
            '/customer\/menu[^>]*bg-peach-soft text-peach-red/s',
            $menuNav,
            'Menu slot should carry the active pill on /customer/menu'
        );
    }

    // ─────────────── admin tables that already had wrappers keep them ───────────────

    public function test_admin_users_page_keeps_its_mobile_overflow_guards(): void
    {
        $src = file_get_contents(base_path('resources/views/admin/users.blade.php'));

        // The "All Staff" table's own horizontal-scroll wrapper (added earlier).
        $this->assertMatchesRegularExpression('/overflow-x:\s*auto/', $src, 'the staff table scroll wrapper must stay');

        // The page-level two-column grid must collapse to ONE shrinkable track
        // on a phone — a bare "1fr" is not shrinkable and reintroduces the
        // +341px horizontal overflow this pass fixed.
        $this->assertStringContainsString('.staff-cols', $src);
        $this->assertMatchesRegularExpression(
            '/@media[^{]*\(max-width:\s*820px\)\s*\{[^}]*\.staff-cols\s*\{\s*grid-template-columns:\s*minmax\(0/s',
            $src,
            'the .staff-cols grid must collapse to minmax(0, 1fr) on narrow screens'
        );
    }

    public function test_existing_admin_scroll_wrappers_are_intact(): void
    {
        foreach ([
            'resources/views/admin/analytics.blade.php',
            'resources/views/admin/menu-items.blade.php',
            'resources/views/admin/completed-orders.blade.php',
            'resources/views/admin/inventory.blade.php',
        ] as $file) {
            $this->assertMatchesRegularExpression(
                '/overflow-x:\s*auto/',
                file_get_contents(base_path($file)),
                "{$file} should still have its horizontal-scroll wrapper"
            );
        }
    }
}
