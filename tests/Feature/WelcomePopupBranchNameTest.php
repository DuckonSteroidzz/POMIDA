<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\TableEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Batch 1 UI fix #4 — the one-shot welcome popup after login/register/QR-claim
 * used to always name a branch via
 * StoreContact::forBranch(session('branch_id')), which falls back to the
 * MAIN branch whenever branch_id is null (that fallback exists for the Store
 * Information/contact screens, which always need SOME address to show). A
 * Pick-Up customer who had not chosen a branch yet therefore saw "Welcome to
 * Main Branch!" — a branch they never picked.
 *
 * Fixed in customer/partials/welcome-popup.blade.php only: the branch name is
 * now resolved solely when session('branch_id') is actually set, otherwise
 * the popup falls back to a plain "Welcome!".
 *
 * Dine-In must be unaffected: AuthController::switchBranch() always sets
 * session('branch_id') to the scanned QR's branch before this popup can fire,
 * on every one of the Dine-In entry doors, so that case keeps naming the real
 * branch exactly as before.
 */
class WelcomePopupBranchNameTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'WPBN';
    private const PASSWORD = 'Aa1!aaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'pomida_db_testing',
            DB::selectOne('select database() as d')->d,
            'WelcomePopupBranchNameTest must only ever run against pomida_db_testing.'
        );

        RateLimiter::clear('customer-login|ip:127.0.0.1');
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('customer-login|ip:127.0.0.1');
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');

        parent::tearDown();
    }

    private function branch(string $label): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' ' . $label . ' ' . uniqid(),
            'code'      => 'WPB' . strtoupper(substr(uniqid(), -7)),
            'address'   => 'WPBN test address',
            'is_active' => true,
        ]);
    }

    private function customer(string $slug): User
    {
        return User::create([
            'name'      => self::PREFIX . ' ' . ucfirst($slug),
            'email'     => strtolower(self::PREFIX) . '-' . $slug . '-' . uniqid() . '@example.test',
            'password'  => self::PASSWORD,
            'role'      => 'customer',
            'branch_id' => null,
            'is_active' => true,
            // A customer who can log in has confirmed their email (Oct 2026 gate).
            'email_verified_at' => now(),
        ]);
    }

    /** The popup's <h3> text only, trimmed — independent of surrounding whitespace/indentation. */
    private function popupTitleText(string $html): string
    {
        $start = strpos($html, 'id="welcomePopupTitle"');
        $this->assertNotFalse($start, 'welcome popup did not render at all');

        $openEnd = strpos($html, '>', $start) + 1;
        $closeStart = strpos($html, '</h3>', $openEnd);
        $this->assertNotFalse($closeStart);

        return trim(substr($html, $openEnd, $closeStart - $openEnd));
    }

    // ══════════ Pick-Up, no branch selected yet ══════════

    public function test_pickup_login_with_no_branch_selected_shows_a_generic_welcome(): void
    {
        $user = $this->customer('nobranch');

        $this->post(route('customer.login.post'), [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('customer.menu'));

        $this->assertNull(session('branch_id'), 'a fresh Pick-Up login must not invent a branch');

        $html = $this->get(route('customer.menu'))->assertOk()->getContent();

        $this->assertSame('Welcome!', $this->popupTitleText($html));
        $this->assertStringNotContainsString('Welcome to Main Branch!', $html);
    }

    // ══════════ Pick-Up, a branch already selected ══════════

    public function test_pickup_login_with_a_branch_already_selected_names_that_branch(): void
    {
        $user = $this->customer('withbranch');
        $branch = $this->branch('Pick');

        $this->withSession(['branch_id' => $branch->id, 'order_type' => 'pick_up'])
            ->post(route('customer.login.post'), [
                'email'    => $user->email,
                'password' => self::PASSWORD,
            ])->assertRedirect(route('customer.menu'));

        $this->assertSame($branch->id, session('branch_id'));

        $html = $this->get(route('customer.menu'))->assertOk()->getContent();

        $this->assertSame('Welcome to ' . $branch->name . '!', $this->popupTitleText($html));
    }

    // ══════════ Dine-In via QR — must keep naming the scanned branch ══════════

    public function test_dine_in_guest_qr_scan_names_the_scanned_branch(): void
    {
        $branch = $this->branch('Dine');
        $code = TableEntry::findOrRegister($branch->id, '1')->code;

        $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', [
                'tableData' => "branch_id={$branch->id}&table=1&k={$code}",
                'next'      => 'guest',
            ])->assertRedirect(route('customer.menu'));

        $this->assertSame($branch->id, session('branch_id'));
        $this->assertSame('dine_in', session('order_type'));

        $html = $this->get(route('customer.menu'))->assertOk()->getContent();

        $this->assertSame('Welcome to ' . $branch->name . '!', $this->popupTitleText($html));
    }
}
