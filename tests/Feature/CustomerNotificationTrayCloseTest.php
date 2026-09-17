<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The customer notification tray (resources/views/customer/partials/
 * notification-bell.blade.php) must offer an explicit close (X) affordance in
 * its header, next to "Mark all read", reusing the shared .toast-x treatment
 * already defined in this partial for the per-card dismiss and toast close
 * buttons, and it must be wired to the same closePanel() path already used by
 * outside-click / Escape.
 *
 * The scrollable list container already carries max-height + overflow-y:auto
 * (70vh on the panel, 55vh on the list) — that half is asserted here only to
 * lock it in place, not because it was added in this pass.
 */
class CustomerNotificationTrayCloseTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(
            resource_path('views/customer/partials/notification-bell.blade.php')
        );
    }

    public function test_the_tray_head_has_a_close_button_using_the_shared_x_icon(): void
    {
        $src = $this->source();

        $headPos = strpos($src, 'data-notif-markread');
        $this->assertNotFalse($headPos, 'the notification tray head is missing');

        $listPos = strpos($src, 'data-notif-list');
        $this->assertNotFalse($listPos);

        $closePos = strpos($src, 'data-notif-close');
        $this->assertNotFalse($closePos, 'the tray has no close (X) button');
        $this->assertGreaterThan($headPos, $closePos, 'the close button must sit in the tray head');
        $this->assertLessThan($listPos, $closePos, 'the close button must sit in the tray head');

        $closeMarkup = substr($src, $closePos - 40, 200);
        $this->assertStringContainsString('aria-label="Close"', $closeMarkup);
        $this->assertStringContainsString('bi bi-x-lg', $closeMarkup);
    }

    public function test_the_close_button_reuses_the_shared_toast_x_style(): void
    {
        $src = $this->source();

        $closePos = strpos($src, 'data-notif-close');
        $this->assertNotFalse($closePos);
        $closeMarkup = substr($src, $closePos, 120);
        $this->assertStringContainsString('class="toast-x"', $closeMarkup);

        // .toast-x is already a borderless, transparent button elsewhere in this file.
        $this->assertMatchesRegularExpression(
            '/\.toast-x\s*\{[^}]*border:\s*0[^}]*background:\s*transparent/s',
            $src
        );
    }

    public function test_the_close_button_is_wired_to_the_shared_close_path(): void
    {
        $src = $this->source();

        $this->assertStringContainsString('function closePanel()', $src);
        $this->assertMatchesRegularExpression(
            '/closeBtn\.addEventListener\(.click.[\s\S]{0,80}closePanel\(\)/',
            $src,
            'the close button must call the same closePanel() helper as outside-click / Escape'
        );
        $this->assertSame(
            4,
            substr_count($src, 'closePanel();'),
            'closePanel() should be invoked by the X button, the toggle, outside-click and Escape'
        );
    }

    public function test_the_scrollable_list_container_stays_bounded(): void
    {
        $src = $this->source();

        $this->assertMatchesRegularExpression(
            '/data-notif-list class="max-h-\[55vh\] overflow-y-auto/',
            $src,
            'the notification list must keep its bounded, scrollable container'
        );
        $this->assertStringContainsString('max-h-[70vh] overflow-hidden', $src);
    }
}
