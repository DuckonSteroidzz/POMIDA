<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QA, Nov 2 2026: in the cart the voucher box was empty, yet a red warning said
 * "You have already used this voucher."
 *
 * ROOT CAUSE (reproduced in Chromium at 1440 and 390 on pomida_db_testing)
 * ----------
 * The applied code is remembered in localStorage['peachy_voucher'] (and a
 * loyalty reward in ['peachy_reward']). Every cart load puts it back by sending
 * it through the same applyCode() a typed code uses. Once that code had been
 * spent on an earlier order, the server answered "You have already used this
 * voucher.", and the refusal branch did two things at once: it cleared the box
 * and painted the red message. Result: an empty box and a red error on a page
 * the customer had only opened. A stored reward code repeated it on every visit,
 * because it was never forgotten unless it sat in a slot.
 *
 * THE RULE NOW
 * ------------
 * An error answers something the customer just did (typed + Apply, or "Use
 * now"). A code the PAGE restores that no longer works is dropped quietly:
 * box emptied, remembered code forgotten, nothing said. The server's answers
 * are unchanged, so a typed or chosen code is explained exactly as before.
 *
 * The behaviour is the page's own JS, so the browser run is the real proof;
 * these are the markup pins (like CartVoucherInputFeedbackTest) plus the
 * server contract the explicit path relies on.
 */
class CartStoredVoucherQuietRestoreTest extends TestCase
{
    use DatabaseTransactions;

    private function cartHtml(): string
    {
        $item = MenuItem::findOrFail(24);

        return $this->withSession([
                'cart' => [(string) $item->id => [
                    'menu_item_id' => $item->id,
                    'name'         => $item->name,
                    'price'        => (float) $item->price,
                    'quantity'     => 1,
                    'options'      => [],
                ]],
                'order_type' => 'pick_up',
                'branch_id'  => 1,
            ])
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();
    }

    /** The body of applyCode(), up to the PWD/Senior section that follows it. */
    private function applyCodeBody(string $html): string
    {
        $start = strpos($html, 'function applyCode(code, restore)');
        $this->assertNotFalse($start, 'applyCode() no longer says whether it is a restore');

        $end = strpos($html, 'PWD / SENIOR CITIZEN ID ROWS', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    // ══════════ the page ══════════

    public function test_the_load_handler_restores_the_stored_voucher_as_a_restore(): void
    {
        $html = $this->cartHtml();

        $this->assertStringContainsString('applyCode(savedVoucher, true)', $html);
        $this->assertStringContainsString('{ code: stored, restore: true }', $html, 'the stored reward is not restored quietly');
        $this->assertStringContainsString('{ code: chosen, restore: false }', $html, 'a "Use now" code must still be explained when refused');
    }

    public function test_a_typed_code_is_never_a_restore(): void
    {
        // applyVoucher() is what the Apply button calls.
        $this->assertStringContainsString('return applyCode(code);', $this->cartHtml());
    }

    public function test_every_refusal_message_is_gated_on_not_being_a_restore(): void
    {
        $body = $this->applyCodeBody($this->cartHtml());

        // voucher refusal and reward refusal, plus "could not check" below.
        $this->assertSame(2, substr_count($body, "if (!restore) setVoucherMessage(data.message, 'error');"));
        $this->assertStringContainsString('if (restore) return;', $body);

        // No error painter is left that a restore can reach.
        $this->assertSame(
            3,
            substr_count($body, "'error'"),
            'a new error-painting call was added to applyCode(): it must be gated on !restore too'
        );
    }

    public function test_a_dropped_restore_forgets_the_remembered_codes(): void
    {
        $body = $this->applyCodeBody($this->cartHtml());

        $this->assertStringContainsString("localStorage.removeItem('peachy_voucher')", $body);
        $this->assertStringContainsString("localStorage.removeItem('peachy_reward')", $body);
        $this->assertStringContainsString("if (sameCode(localStorage.getItem('peachy_reward'), code))", $body);
        $this->assertStringContainsString("restoredBox.value = ''", $body, 'a dropped restore must leave the box empty');
    }

    public function test_success_paths_are_untouched(): void
    {
        $body = $this->applyCodeBody($this->cartHtml());

        $this->assertStringContainsString("setVoucherMessage('Voucher applied.', 'success')", $body);
        $this->assertStringContainsString("setVoucherMessage(data.message, 'success')", $body);
        $this->assertStringContainsString('refreshDiscountSummary()', $body);
    }

    // ══════════ the server contract the explicit path relies on ══════════

    public function test_applying_a_spent_voucher_still_says_so_when_the_customer_asks(): void
    {
        $user = User::create([
            'name' => 'Quiet Restore', 'email' => 'quiet-' . Str::lower(Str::random(10)) . '@invalid.local',
            'password' => 'Quiet!Pass123', 'role' => 'customer', 'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $voucher = Voucher::forceCreate([
            'branch_id' => null, 'code' => 'QR' . strtoupper(Str::random(8)), 'description' => 'quiet restore',
            'discount_type' => 'fixed', 'discount_value' => 20, 'max_uses' => 100, 'used_count' => 0,
            'minimum_order' => 0, 'expires_at' => now()->addDays(30), 'valid_from' => now()->subDay()->toDateString(),
            'is_active' => true, 'on_wheel' => true, 'points_required' => 5,
        ]);

        DB::table('user_vouchers')->insert([
            'user_id' => $user->id, 'voucher_id' => $voucher->id, 'claim_code' => 'QRCLAIM' . strtoupper(Str::random(8)),
            'acquired_date' => now()->toDateString(), 'valid_from' => now()->subDay()->toDateString(),
            'is_used' => 1, 'used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user, 'customer')
            ->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->postJson('/customer/apply-voucher', ['code' => $voucher->code, 'subtotal' => 60])
            ->assertOk()
            ->assertJson(['success' => false, 'message' => 'You have already used this voucher.']);
    }
}
