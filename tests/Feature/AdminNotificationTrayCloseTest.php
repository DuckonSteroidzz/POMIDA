<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The admin/staff notification tray (resources/views/admin/partials/
 * notification-bell.blade.php) must offer an explicit close (X) affordance in
 * its header, reusing the shared bi-x-lg icon and the borderless button
 * treatment the rest of the admin UI uses, and it must be wired to the same
 * close path already used by outside-click / Escape.
 *
 * The scrollable list container already carries max-height + overflow-y:auto
 * (70vh on the panel, 55vh on the list) — that half is asserted here only to
 * lock it in place, not because it was added in this pass.
 */
class AdminNotificationTrayCloseTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(
            resource_path('views/admin/partials/notification-bell.blade.php')
        );
    }

    public function test_the_tray_head_has_a_close_button_using_the_shared_x_icon(): void
    {
        $src = $this->source();

        $headPos = strpos($src, 'class="pc-notif-head"');
        $this->assertNotFalse($headPos, 'the notification tray head is missing');

        $listPos = strpos($src, 'data-anotif-list');
        $this->assertNotFalse($listPos);

        // A close button, inside the head (before the list starts), carrying the
        // bi-x-lg icon and an accessible label.
        $closePos = strpos($src, 'data-anotif-close');
        $this->assertNotFalse($closePos, 'the tray has no close (X) button');
        $this->assertGreaterThan($headPos, $closePos, 'the close button must sit in the tray head');
        $this->assertLessThan($listPos, $closePos, 'the close button must sit in the tray head');

        $closeMarkup = substr($src, $closePos - 40, 140);
        $this->assertStringContainsString('aria-label="Close"', $closeMarkup);
        $this->assertStringContainsString('bi bi-x-lg', $closeMarkup);
    }

    public function test_the_close_button_reuses_the_borderless_admin_button_style(): void
    {
        $src = $this->source();

        // .pc-notif-x mirrors .pc-notif-mark / .pc-toast-x: no border, transparent bg.
        $this->assertMatchesRegularExpression(
            '/\.pc-notif-x\s*\{[^}]*border:\s*0[^}]*background:\s*transparent/s',
            $src,
            '.pc-notif-x must be a borderless, transparent button like the other admin close/link buttons'
        );
    }

    public function test_the_close_button_is_wired_to_the_shared_close_path(): void
    {
        $src = $this->source();

        // One closePanel() helper, reused by the X button and by outside-click / Escape.
        $this->assertStringContainsString('function closePanel()', $src);
        $this->assertMatchesRegularExpression(
            '/\[data-anotif-close\][\s\S]{0,200}closePanel\(\)/',
            $src,
            'the close button must call the same closePanel() helper as outside-click / Escape'
        );
        $this->assertSame(
            3,
            substr_count($src, 'closePanel();'),
            'closePanel() should be invoked by the X button, outside-click and Escape — one shared path'
        );
    }

    public function test_the_scrollable_list_container_stays_bounded(): void
    {
        $src = $this->source();

        $this->assertMatchesRegularExpression(
            '/\.pc-notif-list\s*\{[^}]*max-height:\s*55vh[^}]*overflow-y:\s*auto/s',
            $src,
            'the notification list must keep its bounded, scrollable container'
        );
    }
}
