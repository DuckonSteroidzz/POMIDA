<?php

namespace Tests\Feature;

use App\Http\Controllers\Customer\AuthController;
use App\Models\User;
use App\Models\Voucher;
use App\Support\ShopDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Promotions & Rewards > Vouchers, Defect Report 3 part B: "some Wheel
 * switches do not turn on/off".
 *
 * ROOT CAUSE: the Wheel column was never a control. It was a <span role="img">
 * drawn from Voucher::isOnWheel() — is_active AND points_required > 0 AND not
 * expired AND not used up — with no handler and no route behind it. Clicking
 * it did nothing on any row. The only real lever was the Status switch
 * (is_active), which moved the Wheel picture on some rows (inactive, with
 * points, still valid) and never on the rest (expired, used up, 0 points). So
 * to the tester "some switches work".
 *
 * THE FIX: vouchers.on_wheel, a per-voucher "include on Spin & Win" flag, as
 * the tester's reference has it. It defaults to true, so every existing
 * voucher is on the wheel exactly as before until someone switches it off.
 * The Wheel switch sets it through PUT admin/vouchers/{id}/wheel, behind the
 * same role group and promotionScopeRefusal() as the other voucher writes. The
 * prize picker and the "On spin wheel" count both read it.
 */
class VoucherWheelSwitchTest extends TestCase
{
    use DatabaseTransactions;

    private function portal(string $role, ?int $branchId = 1): User
    {
        return User::create([
            'name'              => 'Wheel ' . $role,
            'email'             => 'wheel-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : $branchId,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function expiry(int $days): string
    {
        return CarbonImmutable::parse(ShopDay::todayString())->addDays($days)->format('Y-m-d') . ' 00:00:00';
    }

    private function voucher(array $attrs = []): Voucher
    {
        return Voucher::create($attrs + [
            'code'            => 'WHL-' . strtoupper(Str::random(8)),
            'description'     => 'Wheel test',
            'discount_type'   => 'fixed',
            'discount_value'  => 50,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 10,
            'is_active'       => true,
            'expires_at'      => null,
            'branch_id'       => null,
        ]);
    }

    private function page(User $user): string
    {
        return $this->actingAs($user, 'admin')->get('/admin/vouchers?branch=all&per_page=100')->assertOk()->getContent();
    }

    /** The Wheel switch <input> of one voucher's table row. */
    private function wheelInput(string $html, Voucher $voucher): string
    {
        $row = $this->between($html, 'data-voucher-row="' . $voucher->id . '"', '</tr>');
        $this->assertSame(1, preg_match('/<input[^>]*data-vch-wheel="' . $voucher->id . '"[^>]*>/', $row, $m), 'no Wheel switch in the row');

        return $m[0];
    }

    private function wheelLabel(string $html, Voucher $voucher): string
    {
        $row = $this->between($html, 'data-voucher-row="' . $voucher->id . '"', '</tr>');
        $this->assertSame(1, preg_match('/<label class="pc-switch[^"]*"[^>]*>(?:(?!<\/label>).)*?data-vch-wheel="' . $voucher->id . '"(?:(?!<\/label>).)*<\/label>/s', $row, $m), 'no switch label');

        return $m[0];
    }

    private function wheel(User $user, Voucher $voucher, bool $on)
    {
        return $this->actingAs($user, 'admin')->putJson(route('admin.vouchers.wheel', $voucher->id), ['on_wheel' => $on]);
    }

    /** Every voucher the real Spin & Win picker can award (globals, plenty of points). */
    private function pickable(): array
    {
        $picker = new ReflectionMethod(AuthController::class, 'winnableVoucherFor');
        $picker->setAccessible(true);
        $controller = app(AuthController::class);
        $this->withSession([]);

        $picked = [];
        for ($i = 0; $i < 300; $i++) {
            $won = $picker->invoke($controller, 1_000_000, $picked);
            if ($won === null) {
                break;
            }
            $picked[] = $won->id;
        }

        return $picked;
    }

    // ══════════ the switch is real ══════════

    public function test_the_wheel_cell_is_a_real_switch_with_on_off_text_for_the_owner(): void
    {
        $on = $this->voucher();
        $html = $this->page($this->portal('admin'));

        $input = $this->wheelInput($html, $on);
        $this->assertStringContainsString('type="checkbox"', $input);
        $this->assertStringContainsString('role="switch"', $input);
        $this->assertStringContainsString('checked', $input);
        $this->assertStringNotContainsString('disabled', $input);
        $this->assertStringContainsString('data-url="' . route('admin.vouchers.wheel', $on->id) . '"', $input);

        // ON / OFF is written inside the switch.
        $label = $this->wheelLabel($html, $on);
        $this->assertStringContainsString('>ON<', $label);
        $this->assertStringContainsString('>OFF<', $label);

        // The old picture-of-a-switch is gone.
        $this->assertStringNotContainsString('role="img"', $this->between($html, 'data-voucher-row="' . $on->id . '"', '</tr>'));
    }

    public function test_switching_off_and_on_changes_the_flag_the_picker_and_the_count(): void
    {
        $admin = $this->portal('admin');
        $v = $this->voucher();
        $this->assertContains($v->id, $this->pickable(), 'setup: a new points voucher should start on the wheel');

        $off = $this->wheel($admin, $v, false)->assertOk();
        $off->assertJson(['id' => $v->id, 'on_wheel' => false, 'live' => false]);
        $this->assertFalse($v->fresh()->on_wheel);
        $this->assertFalse($v->fresh()->isOnWheel());
        $this->assertNotContains($v->id, $this->pickable(), 'the picker still awards a voucher switched off the wheel');
        $this->assertNotContains($v->id, Voucher::onWheel()->pluck('id')->all());
        $this->assertSame(Voucher::onWheel()->count(), $off->json('stats.on_wheel'));

        $on = $this->wheel($admin, $v, true)->assertOk();
        $on->assertJson(['on_wheel' => true, 'live' => true]);
        $this->assertTrue($v->fresh()->isOnWheel());
        $this->assertContains($v->id, $this->pickable());
        $this->assertSame(Voucher::onWheel()->count(), $on->json('stats.on_wheel'));
    }

    public function test_the_switch_works_on_inactive_expired_and_used_up_vouchers_too(): void
    {
        $admin = $this->portal('admin');
        $rows = [
            'inactive' => $this->voucher(['is_active' => false]),
            'expired'  => $this->voucher(['expires_at' => $this->expiry(-3)]),
            'used up'  => $this->voucher(['max_uses' => 2, 'used_count' => 2]),
        ];

        foreach ($rows as $state => $v) {
            $this->wheel($admin, $v, false)->assertOk()->assertJson(['on_wheel' => false, 'live' => false]);
            $this->assertFalse($v->fresh()->on_wheel, "$state: did not switch off");
            $this->wheel($admin, $v, true)->assertOk()->assertJson(['on_wheel' => true, 'live' => false]);
            $this->assertTrue($v->fresh()->on_wheel, "$state: did not switch on");
            // Still not a prize: the switch is not a way round the other rules.
            $this->assertFalse($v->fresh()->isOnWheel(), "$state: became a live prize");
        }

        // And each row says why it is on but not live.
        $html = $this->page($admin);
        foreach ($rows as $v) {
            $this->assertStringContainsString('checked', $this->wheelInput($html, $v));
            $this->assertStringContainsString('vch-wheel-note', $this->between($html, 'data-voucher-row="' . $v->id . '"', '</tr>'));
        }
    }

    public function test_a_direct_voucher_cannot_go_on_the_wheel_and_says_why(): void
    {
        $admin = $this->portal('admin');
        $direct = $this->voucher(['points_required' => 0]);

        $this->wheel($admin, $direct, true)
            ->assertStatus(422)
            ->assertJsonPath('message', Voucher::WHEEL_NEEDS_POINTS);
        $this->assertFalse($direct->fresh()->isOnWheel());

        // Switching one OFF is always allowed.
        $this->wheel($admin, $direct, false)->assertOk();

        // On the page: OFF, disabled, with the reason as the tooltip.
        $html = $this->page($admin);
        $input = $this->wheelInput($html, $direct);
        $this->assertStringContainsString('disabled', $input);
        $this->assertStringNotContainsString('checked', $input);
        $this->assertStringContainsString('title="' . e(Voucher::WHEEL_NEEDS_POINTS) . '"', $this->wheelLabel($html, $direct));
    }

    public function test_the_wheel_route_validates_its_value(): void
    {
        $v = $this->voucher();
        $this->actingAs($this->portal('admin'), 'admin')
            ->putJson(route('admin.vouchers.wheel', $v->id), ['on_wheel' => 'maybe'])
            ->assertStatus(422);
        $this->assertTrue($v->fresh()->on_wheel);
    }

    // ══════════ who may switch ══════════

    public function test_a_supervisor_switches_only_their_own_branch(): void
    {
        $sup = $this->portal('supervisor', 1);
        $mine = $this->voucher(['branch_id' => 1]);
        $global = $this->voucher(['branch_id' => null]);
        $theirs = $this->voucher(['branch_id' => 2]);

        $this->wheel($sup, $mine, false)->assertOk();
        $this->assertFalse($mine->fresh()->on_wheel);

        $this->wheel($sup, $global, false)->assertStatus(403)
            ->assertJsonPath('message', 'Company-wide vouchers can only be managed by the owner.');
        $this->assertTrue($global->fresh()->on_wheel);

        $this->wheel($sup, $theirs, false)->assertStatus(404);
        $this->assertTrue($theirs->fresh()->on_wheel);

        $html = $this->page($sup);
        $this->assertStringNotContainsString('disabled', $this->wheelInput($html, $mine));
        $this->assertStringContainsString('disabled', $this->wheelInput($html, $global));
        $this->assertStringContainsString('title="Company-wide vouchers can only be changed by the owner."', $this->wheelLabel($html, $global));
        $this->assertStringNotContainsString('data-voucher-row="' . $theirs->id . '"', $html);
    }

    public function test_staff_cannot_switch_and_see_only_disabled_switches(): void
    {
        $staff = $this->portal('staff', 1);
        $mine = $this->voucher(['branch_id' => 1]);

        $this->wheel($staff, $mine, false)->assertRedirect(route('admin.home'));
        $this->assertTrue($mine->fresh()->on_wheel);

        $html = $this->page($staff);
        $this->assertStringContainsString('disabled', $this->wheelInput($html, $mine));
        $this->assertStringNotContainsString('data-url=', $this->wheelInput($html, $mine));
    }

    public function test_the_background_list_carries_the_same_working_switches(): void
    {
        $v = $this->voucher();
        $fragment = $this->actingAs($this->portal('admin'), 'admin')
            ->get('/admin/vouchers?branch=all&per_page=100', ['X-Vouchers-Fragment' => '1'])
            ->assertOk()->getContent();

        $input = $this->wheelInput($fragment, $v);
        $this->assertStringContainsString('data-url="' . route('admin.vouchers.wheel', $v->id) . '"', $input);
        $this->assertStringNotContainsString('disabled', $input);
    }

    public function test_the_page_script_listens_on_the_document_so_swapped_rows_still_work(): void
    {
        $html = $this->page($this->portal('admin'));

        // One delegated listener, not one per row (rows are replaced by the
        // background list refresh, and anything bound to them is lost).
        $this->assertMatchesRegularExpression("/document\.addEventListener\('change', function \(e\) \{\s*var input = e\.target\.closest \? e\.target\.closest\('\[data-vch-wheel\]'\)/", $html);
        $this->assertStringContainsString('id="vchToast"', $html);
    }

    // ══════════ Status: badge only; on/off moved to the ⋮ menu ══════════

    public function test_status_shows_only_the_badge_and_the_menu_activates_or_deactivates(): void
    {
        $admin = $this->portal('admin');
        $active = $this->voucher(['branch_id' => 1]);
        $inactive = $this->voucher(['branch_id' => 1, 'is_active' => false]);
        $html = $this->page($admin);

        $statusCell = $this->between($this->between($html, 'data-voucher-row="' . $active->id . '"', '</tr>'), 'vch-cell-status', '</td>');
        $this->assertStringNotContainsString('<form', $statusCell);
        $this->assertStringNotContainsString('<button', $statusCell);
        $this->assertStringNotContainsString('<input', $statusCell);

        $menuA = $this->between($html, 'id="vchMenu' . $active->id . '"', '</td>');
        $menuI = $this->between($html, 'id="vchMenu' . $inactive->id . '"', '</td>');
        $this->assertStringContainsString('action="' . route('admin.vouchers.toggle', $active->id) . '"', $menuA);
        $this->assertStringContainsString('Deactivate Voucher', $menuA);
        $this->assertStringNotContainsString('Activate Voucher', str_replace('Deactivate Voucher', '', $menuA));
        $this->assertStringContainsString('Activate Voucher', $menuI);
        $this->assertStringNotContainsString('Deactivate Voucher', $menuI);

        // The order in the reference: on/off, issue, edit, delete.
        $last = -1;
        foreach (['Deactivate Voucher', 'Issue Voucher', 'Edit Voucher', 'Delete Voucher'] as $item) {
            $pos = strpos($menuA, $item);
            $this->assertNotFalse($pos, "$item is missing");
            $this->assertGreaterThan($last, $pos, "$item is out of order");
            $last = $pos;
        }

        // The endpoint itself is unchanged.
        $this->actingAs($admin, 'admin')->put(route('admin.vouchers.toggle', $active->id))->assertRedirect(route('admin.vouchers'));
        $this->assertFalse($active->fresh()->is_active);
    }

    public function test_the_menu_per_role(): void
    {
        $mine = $this->voucher(['branch_id' => 1]);
        $global = $this->voucher(['branch_id' => null]);

        $sup = $this->page($this->portal('supervisor', 1));
        $supMine = $this->between($sup, 'id="vchMenu' . $mine->id . '"', '</td>');
        $this->assertStringContainsString('Deactivate Voucher', $supMine);
        $this->assertStringContainsString('Edit Voucher', $supMine);
        $this->assertStringNotContainsString('Delete Voucher', $supMine);

        $supGlobal = $this->between($sup, 'id="vchMenu' . $global->id . '"', '</td>');
        $this->assertStringNotContainsString('activate voucher', strtolower($supGlobal));
        $this->assertStringNotContainsString('Edit Voucher', $supGlobal);
        $this->assertStringNotContainsString(route('admin.vouchers.toggle', $global->id), $supGlobal);
        $this->assertStringContainsString('Read-only', $supGlobal);
        $this->assertStringContainsString('Issue Voucher', $supGlobal);

        $staff = $this->page($this->portal('staff', 1));
        $staffMine = $this->between($staff, 'id="vchMenu' . $mine->id . '"', '</td>');
        $this->assertStringContainsString('Issue Voucher', $staffMine);
        foreach (['Activate Voucher', 'Deactivate Voucher', 'Edit Voucher', 'Delete Voucher', 'Read-only'] as $item) {
            $this->assertStringNotContainsString($item, $staffMine, "staff menu has $item");
        }
    }

    // ══════════ Edit modal: the wheel box is the wheel ══════════

    public function test_edit_sets_the_wheel_flag_and_no_longer_switches_the_voucher_off(): void
    {
        $admin = $this->portal('admin');
        $v = $this->voucher(['branch_id' => 1]);
        $fields = [
            'code' => $v->code, 'discount_type' => 'fixed', 'discount_value' => 50, 'max_uses' => 100,
            'minimum_order' => 0, 'points_required' => 10, 'branch_id' => 1,
        ];

        // What the modal sends for an active voucher with the wheel box unticked.
        $this->actingAs($admin, 'admin')->put(route('admin.vouchers.update', $v->id), $fields + ['is_active' => 1, 'on_wheel' => 0])->assertRedirect();
        $this->assertTrue($v->fresh()->is_active, 'unticking the wheel box switched the voucher off');
        $this->assertFalse($v->fresh()->on_wheel);

        $this->actingAs($admin, 'admin')->put(route('admin.vouchers.update', $v->id), $fields + ['is_active' => 1, 'on_wheel' => 1])->assertRedirect();
        $this->assertTrue($v->fresh()->on_wheel);

        // A PUT without the field leaves the flag alone.
        $this->actingAs($admin, 'admin')->put(route('admin.vouchers.update', $v->id), $fields + ['is_active' => 1])->assertRedirect();
        $this->assertTrue($v->fresh()->on_wheel);

        $html = $this->page($admin);
        $this->assertStringContainsString('name="on_wheel" id="editVoucherOnWheel"', $html);
        $this->assertStringContainsString('<input type="hidden" name="is_active" value="1" id="editVoucherIsActive">', $html);
        $this->assertStringContainsString('data-on-wheel="1"', $this->between($html, 'id="vchMenu' . $v->id . '"', '</td>'));
    }

    // ══════════ staff: no lone tab ══════════

    public function test_staff_get_the_title_but_no_tab_bar_and_managers_keep_theirs(): void
    {
        $staff = $this->page($this->portal('staff', 1));
        $this->assertStringContainsString('Promotions &amp; Rewards', $staff);
        $this->assertStringNotContainsString('class="promo-tabs"', $staff);
        $this->assertStringNotContainsString('class="promo-tab', $staff);

        foreach (['admin', 'supervisor'] as $role) {
            $html = $this->page($this->portal($role, 1));
            $this->assertStringContainsString('class="promo-tabs"', $html, "$role lost the tab bar");
            $this->assertSame(3, preg_match_all('/class="promo-tab[ "]/', $html));
        }
    }

    private function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        $this->assertNotFalse($start, "CONTROL: '$from' not on the page");
        $end = strpos($haystack, $to, $start);
        $this->assertNotFalse($end, "CONTROL: '$to' not after '$from'");

        return substr($haystack, $start, $end - $start);
    }
}
