<?php

namespace Tests\Feature;

use App\Services\TableEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The in-browser camera scanner (getUserMedia + jsQR) is gone from
 * /customer/dineinqr. What replaces it, on both /welcome and
 * /customer/dineinqr, is a plain table-code field that validates through the
 * exact same door as every other entry point —
 * App\Services\TableEntry::validate() via AuthController::processQr().
 *
 * A real QR scan now means the customer's phone's OWN camera app opening the
 * printed QR's URL directly (GET /customer/menu?branch_id=&table=) — that path
 * already never touched a camera screen of ours and is unchanged; see
 * DineInQrEntryTest and TableOccupancyTest for its full coverage. This file
 * only covers what actually changed: the two pages and their code-entry UI.
 */
class CameraRemovalTest extends TestCase
{
    use DatabaseTransactions;

    /** Table numbers this suite invents, so cleanup can find them unambiguously. */
    private const PREFIX = 'CRT';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('table-code:127.0.0.1');
        RateLimiter::clear('table-session|ip:127.0.0.1');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('table-code:127.0.0.1');
        RateLimiter::clear('table-session|ip:127.0.0.1');
        parent::tearDown();
    }

    private function table(string $suffix, int $branchId = 1)
    {
        return TableEntry::findOrRegister($branchId, self::PREFIX . strtoupper($suffix));
    }

    // ══════════════════════════════════════════════════════════════════════
    // No camera markup remains
    // ══════════════════════════════════════════════════════════════════════

    public function test_welcome_page_has_no_camera_markup(): void
    {
        $res = $this->get('/');
        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringNotContainsString('getUserMedia', $html);
        $this->assertStringNotContainsString('jsQR', $html);
        $this->assertStringNotContainsString('qrVideo', $html);
        $this->assertStringNotContainsString('scanner-wrapper', $html);

        // And the replacement is actually present.
        $this->assertStringContainsString('welcomeTableCodeInput', $html);
        $this->assertStringContainsString('Welcome to Dine In', $html);
    }

    public function test_dineinqr_page_has_no_camera_markup(): void
    {
        $res = $this->get('/customer/dineinqr');
        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringNotContainsString('getUserMedia', $html);
        $this->assertStringNotContainsString('jsQR', $html);
        $this->assertStringNotContainsString('qrVideo', $html);
        $this->assertStringNotContainsString('scanner-wrapper', $html);
        $this->assertStringNotContainsString('scanner-corners', $html);

        $this->assertStringContainsString('tableCodeInput', $html);
        $this->assertStringContainsString('Welcome to Dine In', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Manual code entry, from both pages, through the same validation door
    // ══════════════════════════════════════════════════════════════════════

    public function test_manual_code_from_welcome_starts_a_guest_dine_in_session(): void
    {
        $table = $this->table('W1');

        $res = $this->from('/')->post('/customer/dineinqr', [
            'table_code' => $table->code,
            'next'       => 'guest',
        ]);

        $res->assertRedirect(route('customer.menu'));
        $this->assertSame($table->table_number, session('table_number'));
        $this->assertSame($table->branch_id, session('branch_id'));
        $this->assertSame('dine_in', session('order_type'));

        // "Continue as Guest" really lands on a working menu page.
        $this->get(route('customer.menu'))->assertOk();
    }

    public function test_manual_code_from_dineinqr_starts_a_guest_dine_in_session(): void
    {
        $table = $this->table('D1');

        $res = $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
            'table_code' => $table->code,
            'next'       => 'guest',
        ]);

        $res->assertRedirect(route('customer.menu'));
        $this->assertSame($table->table_number, session('table_number'));
    }

    public function test_a_bad_code_bounces_back_to_whichever_page_it_was_submitted_from(): void
    {
        $fromWelcome = $this->from('/')->post('/customer/dineinqr', [
            'table_code' => 'NOTREAL1',
            'next'       => 'guest',
        ]);
        $fromWelcome->assertRedirect('/');
        $this->assertNotNull(session('error'));
        $this->assertNull(session('table_number'));

        $this->flushSession();
        RateLimiter::clear('table-code:127.0.0.1');

        $fromDineinqr = $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
            'table_code' => 'NOTREAL2',
            'next'       => 'guest',
        ]);
        $fromDineinqr->assertRedirect('/customer/dineinqr');
        $this->assertNotNull(session('error'));
        $this->assertNull(session('table_number'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Direct QR routing is unchanged: no camera screen ever sat in this path
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_direct_qr_url_landing_still_routes_straight_to_the_menu(): void
    {
        $table = $this->table('U1');

        // Simulates a phone's own camera app opening the printed QR's URL —
        // this never went through our camera scanner and still does not.
        $res = $this->get('/customer/menu?branch_id=' . $table->branch_id
            . '&table=' . $table->table_number . '&k=' . $table->code);

        $res->assertOk();
        $this->assertSame($table->table_number, session('table_number'));
        $this->assertSame('dine_in', session('order_type'));
    }
}
