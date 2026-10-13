<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Customer navigation polish (Oct 2026, defence panel: "Improve the overall
 * customer interface for easier navigation").
 *
 * WHAT WAS WRONG
 * --------------
 *  - Pages that are not tabs themselves (a category, an item, the cart, a
 *    receipt, My Vouchers, Account) lit up no tab, or the wrong one: the bottom
 *    bar only matched its own four route names.
 *  - Item details, receipt and My Vouchers carried their own bottom-bar copies
 *    with the tabs in a different order on each (and a dead Help tab on dine-in
 *    item details), so tabs jumped position from page to page.
 *  - Only GCash had a back control in the header (top-right, under 44px);
 *    elsewhere the way back was an in-page link, a history.back() button, or
 *    nothing at all (item details, My Vouchers, Account).
 *  - A category's item list had no way to switch category except going back.
 *
 * WHAT IS PINNED HERE
 * -------------------
 * Rendered pages, not source text: which tab is aria-current on each page,
 * the same four tabs in the same order everywhere, the header back button's
 * destination on every inner page (and its absence on the four tab pages), and
 * the category jump strip.
 */
class CustomerNavigationTest extends TestCase
{
    use DatabaseTransactions;

    private const TABS = ['Menu', 'Orders', 'Spin &amp; Win', 'More'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function customer(): User
    {
        return User::create([
            'name'              => 'Nav Test',
            'email'             => 'navtest-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->fresh();
    }

    private function order(User $user, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'NAV-' . substr(uniqid(), -8),
            'user_id'        => $user->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ], $attrs));
    }

    /** An available item at branch 1 whose category is active — real menu data. */
    private function menuItem(): MenuItem
    {
        $item = MenuItem::where('is_available', true)
            ->whereNotNull('category_id')
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->first();

        $this->assertNotNull($item, 'setup: the testing DB has no available menu item at branch 1');

        return $item;
    }

    private function visit(User $user, string $url): string
    {
        return $this->actingAs($user, 'customer')
            ->withSession(['order_type' => 'pick_up', 'branch_id' => 1])
            ->get($url)
            ->assertOk()
            ->getContent();
    }

    /** The shared bottom bar's markup. */
    private function bottomNav(string $html): string
    {
        $this->assertSame(1, preg_match_all('/<nav\b[^>]*aria-label="Customer navigation".*?<\/nav>/s', $html, $m), 'expected exactly one shared bottom bar');

        return $m[0][0];
    }

    /** @return list<string> */
    private function tabLabels(string $nav): array
    {
        preg_match_all('/<span>([^<]+)<\/span>/', $nav, $m);

        return array_values(array_map('trim', $m[1]));
    }

    private function activeTab(string $nav): ?string
    {
        preg_match_all('/<a\b(?:(?!<\/a>).)*?aria-current="page"(?:(?!<\/a>).)*?<span>([^<]+)<\/span>/s', $nav, $m);

        $this->assertLessThanOrEqual(1, count($m[1]), 'more than one tab is marked current');

        return $m[1][0] ?? null;
    }

    private function headerBackHref(string $html): ?string
    {
        if (! preg_match('/<header\b.*?<\/header>/s', $html, $header)) {
            return null;
        }

        return preg_match('/<a href="([^"]+)"\s+data-header-back/', $header[0], $m) ? $m[1] : null;
    }

    // ══════════════════════════════════════════════════════════════════
    // The bottom bar: same tabs everywhere, the right one lit
    // ══════════════════════════════════════════════════════════════════

    public function test_every_page_shows_the_same_four_tabs_and_lights_the_section_it_belongs_to(): void
    {
        $user  = $this->customer();
        $item  = $this->menuItem();
        $order = $this->order($user);

        $pages = [
            'menu'         => [route('customer.menu'), 'Menu'],
            'category'     => [route('customer.items', $item->category_id), 'Menu'],
            'item details' => [route('customer.item', $item->id), 'Menu'],
            'cart'         => [route('customer.cart'), 'Menu'],
            'orders'       => [route('customer.orders'), 'Orders'],
            'receipt'      => [route('customer.receipt', $order->id), 'Orders'],
            'spin & win'   => [route('customer.game'), 'Spin &amp; Win'],
            'more'         => [route('customer.more'), 'More'],
            'help'         => [route('customer.help'), 'More'],
            'my vouchers'  => [route('customer.vouchers'), 'More'],
            'account'      => [route('customer.account'), 'More'],
        ];

        foreach ($pages as $name => [$url, $expected]) {
            $nav = $this->bottomNav($this->visit($user, $url));

            $this->assertSame(self::TABS, $this->tabLabels($nav), "{$name}: the bottom bar's tabs differ from every other page");
            $this->assertSame($expected, $this->activeTab($nav), "{$name}: the wrong tab (or none) is lit");
        }
    }

    public function test_dine_in_item_details_no_longer_has_a_dead_help_tab(): void
    {
        $user = $this->customer();
        $html = $this->actingAs($user, 'customer')
            ->withSession(['order_type' => 'dine_in', 'branch_id' => 1, 'table_number' => '5'])
            ->get(route('customer.item', $this->menuItem()->id))
            ->assertOk()
            ->getContent();

        $nav = $this->bottomNav($html);
        $this->assertSame(self::TABS, $this->tabLabels($nav));
        $this->assertStringNotContainsString('href="#"', $nav);
    }

    // ══════════════════════════════════════════════════════════════════
    // The header back button
    // ══════════════════════════════════════════════════════════════════

    public function test_inner_pages_have_a_header_back_button_to_their_parent(): void
    {
        $user  = $this->customer();
        $item  = $this->menuItem();
        $order = $this->order($user);
        $gcash = $this->order($user, ['payment_method' => 'gcash']);

        $pages = [
            'category'     => [route('customer.items', $item->category_id), route('customer.menu')],
            'item details' => [route('customer.item', $item->id), route('customer.items', $item->category_id)],
            'cart'         => [route('customer.cart'), route('customer.menu')],
            'receipt'      => [route('customer.receipt', $order->id), route('customer.orders')],
            'gcash'        => [route('customer.gcash-payment', $gcash->id), route('customer.orders')],
            'help'         => [route('customer.help'), route('customer.more')],
            'my vouchers'  => [route('customer.vouchers'), route('customer.more')],
            'account'      => [route('customer.account'), route('customer.more')],
        ];

        foreach ($pages as $name => [$url, $parent]) {
            $this->assertSame($parent, $this->headerBackHref($this->visit($user, $url)), "{$name}: header back button missing or pointing elsewhere");
        }
    }

    public function test_the_four_tab_pages_have_no_header_back_button(): void
    {
        $user = $this->customer();

        foreach (['customer.menu', 'customer.orders', 'customer.game', 'customer.more'] as $route) {
            $this->assertNull($this->headerBackHref($this->visit($user, route($route))), "{$route} is a top-level tab and should not show a back button");
        }
    }

    public function test_the_header_back_button_is_a_44px_labelled_control(): void
    {
        // Markup only: the partial's own doc comment explains why it avoids history.back().
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(resource_path('views/customer/partials/header-back.blade.php')));

        $this->assertStringContainsString('h-11 min-w-11', $markup, 'the back button must keep a 44px tap target');
        $this->assertStringContainsString('aria-label="Back to {{ $label }}"', $markup);
        $this->assertStringNotContainsString('history.back', $markup, 'back must go to the parent page, not browser history');
    }

    public function test_item_details_breadcrumb_includes_the_category(): void
    {
        $user = $this->customer();
        $item = $this->menuItem();
        $html = $this->visit($user, route('customer.item', $item->id));

        $this->assertMatchesRegularExpression(
            '/href="' . preg_quote(route('customer.items', $item->category_id), '/') . '"[^>]*>\s*' . preg_quote(e($item->category->name), '/') . '\s*</',
            $html,
            'the breadcrumb should read Menu › Category › Item'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // The category jump strip
    // ══════════════════════════════════════════════════════════════════

    public function test_a_category_page_offers_every_menu_category_and_marks_the_current_one(): void
    {
        $user = $this->customer();
        $item = $this->menuItem();
        $html = $this->visit($user, route('customer.items', $item->category_id));

        $this->assertSame(1, preg_match('/<nav data-category-jump.*?<\/nav>/s', $html, $strip), 'the category page has no category strip');
        $this->assertGreaterThan(strpos($html, '<header'), strpos($html, 'data-category-jump'), 'the strip must live in the sticky header');
        $this->assertLessThan(strpos($html, '</header>'), strpos($html, 'data-category-jump'), 'the strip must live in the sticky header');

        // Same list and order as the Main Menu grid.
        $grid = $this->visit($user, route('customer.menu'));
        preg_match_all('/customer\/items\/(\d+)/', $grid, $gridIds);
        preg_match_all('/customer\/items\/(\d+)/', $strip[0], $stripIds);
        $this->assertSame(array_values(array_unique($gridIds[1])), $stripIds[1], 'the strip and the menu grid disagree about the categories');

        // Exactly one chip is current, and it is this category.
        $this->assertSame(1, substr_count($strip[0], 'aria-current="page"'));
        $this->assertMatchesRegularExpression(
            '/href="' . preg_quote(route('customer.items', $item->category_id), '/') . '"\s+aria-current="page"/',
            $strip[0]
        );
    }

    public function test_the_main_menu_grid_has_no_category_strip(): void
    {
        $this->assertStringNotContainsString('data-category-jump', $this->visit($this->customer(), route('customer.menu')));
    }

    public function test_an_inactive_category_is_not_offered_in_the_strip(): void
    {
        $user   = $this->customer();
        $item   = $this->menuItem();
        $hidden = Category::create(['name' => 'Nav Hidden ' . Str::random(6), 'is_active' => false]);

        MenuItem::whereKey($item->id)->first()->replicate(['category_id'])->forceFill([
            'name' => 'Nav hidden item', 'category_id' => $hidden->id,
        ])->save();

        $html = $this->visit($user, route('customer.items', $item->category_id));

        $this->assertStringNotContainsString(route('customer.items', $hidden->id) . '"', $html);
    }
}
