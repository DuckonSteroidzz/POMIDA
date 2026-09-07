<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The voucher row's Apply button, restyled (2026-09-03).
 *
 * THE BUG
 * -------
 * The button carried class="btn-custom", a class that is defined nowhere on
 * this page (grepped the whole file — zero matches for ".btn-custom"), so it
 * rendered as a raw, unstyled browser button next to the modal's other
 * controls. It was also labelled "Check" and the code input was full width,
 * absurd for a 13-character code like PCH-VKH43-KU7ZR.
 *
 * WHAT WAS REUSED
 * ----------------
 * The modal's OWN Cancel and Place Order buttons (this same modal's footer)
 * had no shared class to point at either — they were two bare inline-styled
 * <button> tags. Rather than give Apply a fourth one-off inline block, the
 * two existing inline declarations were pulled out verbatim (same colours,
 * radius, padding, weight — nothing invented) into two page-scoped classes,
 * manual-modal-btn-secondary and manual-modal-btn-primary, and Cancel / Place
 * Order were switched to them too. Apply now carries the SAME secondary class
 * Cancel carries, which is what these assertions check directly, not just the
 * button's visible text.
 */
class ManualOrderVoucherButtonStyleTest extends TestCase
{
    use DatabaseTransactions;

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function homePage()
    {
        return $this->actingAs($this->staff(), 'admin')->get('/admin/home');
    }

    public function test_the_apply_button_is_labelled_apply_not_check(): void
    {
        $page = $this->homePage();

        $page->assertOk();
        $page->assertDontSee('>Check<', false);

        $body = $page->getContent();
        $this->assertMatchesRegularExpression(
            '/id="manualVoucherCheck"[^>]*>\s*Apply\s*</s',
            $body,
            'the voucher button is not labelled "Apply"'
        );
    }

    public function test_the_apply_button_carries_the_same_class_as_the_modals_cancel_button(): void
    {
        $body = $this->homePage()->getContent();

        // The button that closes the modal (Cancel) — its class list.
        preg_match(
            '/<button[^>]*onclick="closeManualOrder\(\)"[^>]*class="([^"]*)"/s',
            $body,
            $cancelMatch
        );
        // Fall back to class-before-onclick attribute order.
        if (empty($cancelMatch)) {
            preg_match(
                '/<button[^>]*class="([^"]*)"[^>]*onclick="closeManualOrder\(\)"/s',
                $body,
                $cancelMatch
            );
        }
        $this->assertNotEmpty($cancelMatch, 'could not find the Cancel button to compare against');
        $cancelClasses = explode(' ', trim($cancelMatch[1]));

        // The Apply button — its class list.
        preg_match(
            '/id="manualVoucherCheck"[^>]*class="([^"]*)"/s',
            $body,
            $applyMatchA
        );
        preg_match(
            '/class="([^"]*)"[^>]*id="manualVoucherCheck"/s',
            $body,
            $applyMatchB
        );
        $applyClassAttr = !empty($applyMatchA) ? $applyMatchA[1] : ($applyMatchB[1] ?? null);
        $this->assertNotNull($applyClassAttr, 'the Apply button has no class attribute at all');
        $applyClasses = explode(' ', trim($applyClassAttr));

        $this->assertNotEmpty($applyClasses, 'the Apply button carries no class');

        $shared = array_intersect($cancelClasses, $applyClasses);
        $this->assertNotEmpty(
            $shared,
            'the Apply button shares no class with the modal\'s own Cancel button — '
                . 'Cancel has [' . implode(',', $cancelClasses) . '], '
                . 'Apply has [' . implode(',', $applyClasses) . ']'
        );

        // Specifically the secondary-button class, not just any overlap.
        $this->assertContains('manual-modal-btn-secondary', $applyClasses);
        $this->assertContains('manual-modal-btn-secondary', $cancelClasses);
    }

    public function test_the_class_the_apply_button_uses_is_actually_defined_on_this_page(): void
    {
        $view = file_get_contents(resource_path('views/admin/home.blade.php'));

        $this->assertStringContainsString(
            '.manual-modal-btn-secondary',
            $view,
            'manual-modal-btn-secondary has no CSS rule defined on this page — same class of bug as btn-custom'
        );

        $this->assertStringNotContainsString(
            'btn-custom',
            $view,
            'the old undefined class should be gone entirely, not just unused'
        );
    }

    public function test_the_voucher_input_is_not_full_width(): void
    {
        $body = $this->homePage()->getContent();

        preg_match('/id="manualVoucherCode"[^>]*style="([^"]*)"/s', $body, $m);
        $this->assertNotEmpty($m, 'could not find the voucher code input');

        $style = $m[1];

        $this->assertStringNotContainsString(
            'flex:1;',
            $style,
            'the voucher input still stretches to fill the whole row'
        );
        $this->assertMatchesRegularExpression(
            '/max-width\s*:\s*\d/',
            $style,
            'the voucher input has no width cap, so it will still fill the row on a wide screen'
        );
    }

    public function test_the_field_still_carries_the_name_the_server_depends_on(): void
    {
        $this->homePage()->assertSee('name="voucher_code"', false);
    }
}
