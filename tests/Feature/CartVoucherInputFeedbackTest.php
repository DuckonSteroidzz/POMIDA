<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * THE CART'S VOUCHER BOX — what it says it is, and what it says when it says no.
 *
 * THE BUG
 * -------
 * Two reports about the same control, both confirmed in a real browser at
 * 375px before this was written:
 *
 *  1. The box was an unlabelled rounded rectangle. No placeholder, no icon —
 *     nothing on screen said it wanted a voucher code. Its only label was an
 *     aria-label, which a sighted customer never sees.
 *
 *  2. Submitting a garbage code ("pch-wqeqwe-wqeqwe") left the rejected text
 *     sitting in the field, so the customer's next attempt started by clearing
 *     it by hand. The refusal WAS printed, but as a bare 12px line under the
 *     field, in a colour set by an inline style — not the alert box this same
 *     page already uses for the PWD/Senior card's refusals.
 *
 * WHAT WAS REUSED, AND WHY THESE ASSERTIONS LOOK FOR IT
 * -----------------------------------------------------
 * Nothing here is new design. showDiscountCardMessage() a few hundred lines
 * further down this same file already had the page's message pattern —
 * "rounded-xl bg-red-50 px-3 py-2 text-xs font-semibold text-red-700" for a
 * refusal and the bg-green-50/text-green-700 twin for success — and the icon
 * is bi-ticket-perforated, which is already THE voucher icon across
 * customer/vouchers and admin/vouchers (6 uses). So these tests assert the
 * shared classes by name: a future rewrite that invents a fresh colour for
 * this one box fails here rather than passing because it still looks vaguely
 * red.
 *
 * These are markup assertions because the behaviour is entirely in the page —
 * applyVoucher()'s failure branch is what clears the field, and there is no
 * server round trip that could be asserted instead.
 */
class CartVoucherInputFeedbackTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The cart with something in it. The voucher box lives inside the order
     * summary, which is only rendered when the cart HAS items — an empty cart
     * has no voucher box to assert about at all.
     */
    private function cartPage()
    {
        $item = MenuItem::findOrFail(24);

        $cart = [
            (string) $item->id => [
                'menu_item_id' => $item->id,
                'name'         => $item->name,
                'price'        => (float) $item->price,
                'quantity'     => 1,
                'options'      => [],
            ],
        ];

        return $this->withSession([
                'cart'       => $cart,
                'order_type' => 'pick_up',
                'branch_id'  => 1,
            ])
            ->get('/customer/cart');
    }

    // ══════════════════ 1. the box says what it is ══════════════════

    public function test_the_voucher_box_carries_a_visible_placeholder(): void
    {
        $page = $this->cartPage();
        $page->assertOk();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="voucherInput"[^>]*placeholder="Enter voucher code"/s',
            $page->getContent(),
            'the voucher input has no visible "Enter voucher code" placeholder'
        );
    }

    public function test_the_voucher_box_carries_the_apps_own_voucher_icon(): void
    {
        $body = $this->cartPage()->getContent();

        // The icon must sit with the input, not merely somewhere on a 2,000
        // line page — so the window searched is the voucher block itself.
        $start = strpos($body, 'id="voucherInput"');
        $this->assertNotFalse($start, 'the voucher input is gone');

        $block = substr($body, max(0, $start - 900), 1400);

        $this->assertStringContainsString(
            'bi-ticket-perforated',
            $block,
            'the voucher box does not carry the app\'s existing voucher icon'
        );
    }

    // ══════════════════ 2. a refused code resets the box ══════════════════

    public function test_a_rejected_code_is_cleared_from_the_field(): void
    {
        $body = $this->cartPage()->getContent();

        $this->assertMatchesRegularExpression(
            '/else\s*\{[^}]*voucherInput[^}]*\.value\s*=\s*\'\'/s',
            $body,
            'applyVoucher() does not clear #voucherInput on the failure path'
        );
    }

    /**
     * The window of source between "function setVoucherMessage" and the start
     * of the next function.
     *
     * Asserting against the WHOLE page would be a false pass: this file also
     * contains showDiscountCardMessage(), which legitimately carries the same
     * class strings for the PWD/Senior card. A page-wide assertion therefore
     * stays green even if the voucher box is repainted in some other colour —
     * proven during the sabotage pass, where swapping the voucher's tone to
     * bg-rose-100 did not fail the page-wide version of this test.
     */
    private function setVoucherMessageBody(string $body): string
    {
        $start = strpos($body, 'function setVoucherMessage');

        $this->assertNotFalse(
            $start,
            'there is no single place deciding how a voucher message is painted'
        );

        $end = strpos($body, 'function applyVoucher', $start);

        $this->assertNotFalse($end, 'setVoucherMessage() runs into the rest of the page');

        return substr($body, $start, $end - $start);
    }

    public function test_a_refusal_is_shown_in_the_pages_own_alert_box(): void
    {
        $painter = $this->setVoucherMessageBody($this->cartPage()->getContent());

        // The page's established tones, as showDiscountCardMessage() spells
        // them — asserted inside the voucher's own painter, so a fresh colour
        // invented for this one box fails here.
        $this->assertStringContainsString(
            'bg-red-50 text-red-700',
            $painter,
            'the voucher refusal does not reuse the page\'s established error alert classes'
        );

        $this->assertStringContainsString(
            'bg-green-50 text-green-700',
            $painter,
            'the voucher success message does not reuse the page\'s established classes'
        );

        // The alert SHAPE, also lifted from showDiscountCardMessage().
        $this->assertStringContainsString(
            'rounded-xl px-3 py-2 text-xs font-semibold',
            $painter,
            'the voucher message is not painted in the page\'s alert shape'
        );
    }

    public function test_the_refusal_no_longer_paints_itself_with_inline_colours(): void
    {
        $body = $this->cartPage()->getContent();

        $this->assertStringNotContainsString(
            "msg.style.color = '#C0392B'",
            $body,
            'applyVoucher() still hand-paints its message colour inline'
        );
    }
}
