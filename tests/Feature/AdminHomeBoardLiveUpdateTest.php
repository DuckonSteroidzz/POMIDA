<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Admin Home order board updates in place, not by reloading the document
 * (2026-09-06).
 *
 * THE REPORT
 * ----------
 * "The admin screen flickers on navigation and on its own timer. Sidebar
 * icons blank out." The board's auto-refresh was `location.reload()` on a 10s
 * setInterval: every tick repainted the whole document — sidebar, header,
 * branch selector, notification bell — and re-ran bootstrap-icons.css's
 * font-display:block window, which is what made a plain repaint read as icons
 * specifically disappearing.
 *
 * The fix keeps the exact same markup and the exact same 10s cadence, but the
 * tick now fetches /admin/home in the background and swaps ONLY the innerHTML
 * of the two order-board containers (#pc-live-region and #pc-help-toasts-slot).
 * Nothing outside those is touched or re-armed.
 *
 * These assertions pin the shape that keeps the fix working: no full-page
 * reload timer survives on Home, the swap targets exist and are addressed by
 * id, the swap is idempotent (whole-innerHTML replace, never append), a failed
 * fetch is swallowed so the last board stays on screen, and the <html> canvas
 * carries an explicit background so navigation cannot flash white. The visual
 * "no flicker" itself is a browser-timing effect a PHPUnit test cannot see —
 * same situation as AdminSidebarIconFlickerTest — so this pins the wiring a
 * manual repro can't easily prove is still all present.
 */
class AdminHomeBoardLiveUpdateTest extends TestCase
{
    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function homeSource(): string
    {
        return file_get_contents(resource_path('views/admin/home.blade.php'));
    }

    private function layoutSource(): string
    {
        return file_get_contents(resource_path('views/admin/layout.blade.php'));
    }

    public function test_the_home_view_has_no_full_page_reload_timer(): void
    {
        $view = $this->homeSource();

        // Not in a handler, not in a comment — the token does not belong on
        // this page at all any more.
        $this->assertStringNotContainsString('location.reload(', $view);
        $this->assertStringNotContainsString('location.reload()', $view);
        $this->assertStringNotContainsString('window.location.reload', $view);
    }

    public function test_the_board_regions_are_wrapped_and_addressed_by_id(): void
    {
        $view = $this->homeSource();

        $this->assertStringContainsString('id="pc-live-region"', $view);
        $this->assertStringContainsString('id="pc-help-toasts-slot"', $view);

        // The rendered page must actually carry both containers.
        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="pc-live-region"', $html);
        $this->assertStringContainsString('id="pc-help-toasts-slot"', $html);

        // The Active Orders board is inside the swapped region.
        $liveStart = strpos($html, 'id="pc-live-region"');
        $activeOrders = strpos($html, 'Active Orders');
        $this->assertNotFalse($liveStart);
        $this->assertNotFalse($activeOrders);
        $this->assertGreaterThan($liveStart, $activeOrders);
    }

    public function test_the_timer_updates_the_board_in_the_background_on_the_same_cadence(): void
    {
        $view = $this->homeSource();

        // Background fetch, not a navigation.
        $this->assertStringContainsString('fetch(window.location.href', $view);
        // Unchanged 10s cadence.
        $this->assertStringContainsString('BOARD_REFRESH_MS = 10000', $view);
        $this->assertStringContainsString('setInterval(refreshBoard, BOARD_REFRESH_MS)', $view);
        // It parses the response and pulls the fresh regions out by id.
        $this->assertStringContainsString('DOMParser', $view);
        $this->assertStringContainsString("SWAP_IDS = ['pc-live-region', 'pc-help-toasts-slot']", $view);
    }

    public function test_the_swap_is_idempotent_and_never_appends(): void
    {
        $view = $this->homeSource();

        // Whole-innerHTML replace: cards cannot accumulate, dropped rows vanish.
        $this->assertStringContainsString('current.innerHTML = fresh.innerHTML', $view);
        $this->assertStringNotContainsString('insertAdjacentHTML', $view);
        $this->assertStringNotContainsString('appendChild(fresh', $view);
    }

    public function test_a_failed_refresh_keeps_the_last_board_on_screen(): void
    {
        $view = $this->homeSource();

        // A catch with no DOM writes in it — the board is left exactly as it is.
        $this->assertMatchesRegularExpression(
            '/\.catch\(function \(\) \{\s*\/\/ Keep the last known board visible on any failure\.\s*\}\)/',
            $view
        );

        // A non-board response (login/redirect) is also left alone.
        $this->assertStringContainsString(
            "if (!doc.getElementById('pc-live-region')) {",
            $view
        );

        // An in-flight guard stops overlapping ticks stacking up.
        $this->assertStringContainsString('if (inFlight) {', $view);
    }

    public function test_the_refresh_stands_down_for_the_manual_order_modal_and_a_hidden_tab(): void
    {
        $view = $this->homeSource();

        $this->assertStringContainsString("getElementById('manualOrderModal')", $view);
        $this->assertStringContainsString('if (document.hidden) {', $view);
    }

    public function test_the_html_canvas_has_an_explicit_background_so_navigation_cannot_flash_white(): void
    {
        $layout = $this->layoutSource();

        $this->assertMatchesRegularExpression(
            '/html\s*\{\s*background-color:\s*#[0-9A-Fa-f]{3,8};/',
            $layout,
            'the admin <html> element needs an explicit background colour or every navigation flashes white'
        );

        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/html\s*\{\s*background-color:/', $html);
    }
}
