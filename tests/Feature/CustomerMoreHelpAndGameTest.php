<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Two changes on the customer "More" surface, pinned here.
 *
 * 1. GAME REACHABILITY
 *    The mobile bottom bar (customer/partials/navbar.blade.php) now carries a
 *    dedicated "Spin & Win" slot, so a phone customer always has a route to the
 *    game. The redundant "Spin & Win" card that briefly lived on the More page
 *    (added when the bottom bar had no game slot) was removed in the Sept 2026
 *    Dine-In UI cleanup; this test now pins that the card is gone AND that the
 *    bottom-bar slot keeps the game reachable.
 *
 * 2. DEAD HELP MARKUP REMOVED
 *    menu.blade.php and orders.blade.php each shipped a full #helpModal block
 *    (modal markup + .help-option CSS + openHelpModal/closeHelpModal script) but
 *    NOTHING on either page ever called openHelpModal() - proven by grep before
 *    removal: the only references were closeHelpModal() from inside the modal
 *    and the backdrop click listener. account-settings.blade.php had a desktop
 *    "Help" button calling openHelpModal(), but that page defines neither the
 *    function nor the modal, so the button threw a ReferenceError.
 *
 *    The one working help path - the dine-in "Request Assistance" button on the
 *    More page - is untouched and is asserted here so a future cleanup cannot
 *    take it out by pattern-matching.
 */
class CustomerMoreHelpAndGameTest extends TestCase
{
    private function viewSource(string $page): string
    {
        return file_get_contents(resource_path("views/customer/{$page}.blade.php"));
    }

    public function test_more_page_no_longer_carries_the_redundant_spin_and_win_card(): void
    {
        $source = $this->viewSource('more');

        $this->assertStringNotContainsString(
            'Play while you wait',
            $source,
            'the redundant Spin & Win card should be gone from the More page'
        );
        $this->assertStringNotContainsString(
            "route('customer.game')",
            $source,
            'the More page should no longer link to the game route directly'
        );
    }

    public function test_the_shared_bottom_nav_keeps_the_game_reachable(): void
    {
        $nav = file_get_contents(resource_path('views/customer/partials/navbar.blade.php'));

        $this->assertStringContainsString("route('customer.game')", $nav);
        $this->assertStringContainsString('Spin &amp; Win', $nav);
    }

    public function test_more_page_keeps_its_working_dine_in_help_path(): void
    {
        $source = $this->viewSource('more');

        $this->assertStringContainsString('onclick="openHelpModal()"', $source, 'the More page lost its Request Assistance trigger');
        $this->assertStringContainsString('id="helpModal"', $source, 'the More page lost its help modal markup');
        $this->assertStringContainsString('function openHelpModal', $source, 'the More page lost its openHelpModal definition');
        $this->assertStringContainsString("route('customer.help-request')", $source);
    }

    /**
     * @dataProvider pagesWhoseHelpModalWasOrphaned
     */
    public function test_orphaned_help_modal_markup_is_gone(string $page): void
    {
        $source = $this->viewSource($page);

        $this->assertStringNotContainsString('id="helpModal"', $source, "{$page}.blade.php still ships orphaned help-modal markup");
        $this->assertStringNotContainsString('openHelpModal', $source, "{$page}.blade.php still references openHelpModal");
        $this->assertStringNotContainsString('help-option', $source, "{$page}.blade.php still ships the orphaned .help-option CSS");
    }

    public static function pagesWhoseHelpModalWasOrphaned(): array
    {
        return [
            'menu' => ['menu'],
            'orders' => ['orders'],
            'account-settings' => ['account-settings'],
        ];
    }

    /**
     * Sabotage guard: any customer view that CALLS openHelpModal() must also
     * define it and contain the modal it opens. Prevents re-introducing a dead
     * trigger (the account-settings bug) or deleting a live one's backing.
     *
     * @dataProvider everyCustomerView
     */
    public function test_no_customer_view_has_a_dead_help_trigger(string $file): void
    {
        $source = file_get_contents($file);

        if (! str_contains($source, 'openHelpModal()')) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertStringContainsString('function openHelpModal', $source, basename($file) . ' calls openHelpModal() but never defines it');
        $this->assertStringContainsString('id="helpModal"', $source, basename($file) . ' calls openHelpModal() but has no #helpModal to open');
    }

    public static function everyCustomerView(): array
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'customer';
        $cases = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $cases[str_replace($dir . DIRECTORY_SEPARATOR, '', $file->getPathname())] = [$file->getPathname()];
            }
        }

        return $cases;
    }
}
