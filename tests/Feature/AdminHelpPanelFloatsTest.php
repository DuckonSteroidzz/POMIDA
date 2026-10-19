<?php

namespace Tests\Feature;

use App\Models\HelpRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The "New Notifications" panel on the admin / staff / supervisor Home floats
 * above the page (item E, Oct 2026 defect report).
 *
 * The bug: the panel was already position:fixed, but .pc-wrap reserved room for
 * it with `margin-right: 355px` from 1000px up, so Active Orders shrank
 * (1133px -> 778px in a real browser at 1440) every time a customer asked for
 * help. Now nothing in the layout depends on the panel. The measured widths at
 * 390 / 1440 for all three roles are in the item E report; these tests pin the
 * CSS and markup that make it so, and that Assist / Done are still there.
 */
class AdminHelpPanelFloatsTest extends TestCase
{
    use DatabaseTransactions;

    private function portal(string $role): User
    {
        return User::create([
            'name'              => 'Panel ' . $role,
            'email'             => 'panel-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : 1,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @dataProvider roles
     */
    public function test_the_panel_floats_and_the_board_reserves_no_room_for_it(string $role): void
    {
        $request = HelpRequest::create([
            'branch_id' => 1, 'table_number' => 'PANEL-' . Str::upper(Str::random(4)),
            'status' => 'pending', 'message' => 'I need staff assistance', 'requested_at' => now(),
        ]);

        $html = $this->actingAs($this->portal($role), 'admin')->get('/admin/home')->assertOk()->getContent();

        // The panel is there with both of its actions.
        $this->assertStringContainsString('New Notifications', $html);
        $this->assertStringContainsString(route('admin.help-requests.assist', $request->id), $html);
        $this->assertStringContainsString(route('admin.help-requests.resolve', $request->id), $html);

        // A fixed layer above the page...
        $this->assertSame(1, preg_match('/\.pc-toasts \{([^}]*)\}/', $html, $m));
        $this->assertStringContainsString('position: fixed;', $m[1]);
        $this->assertMatchesRegularExpression('/z-index: 1[0-9]{3};/', $m[1]);

        // ...and nothing that makes the board move over for it.
        $this->assertStringNotContainsString('has-notifications', $html);
        $this->assertStringNotContainsString('margin-right: 355px', $html);
        $this->assertDoesNotMatchRegularExpression('/\.pc-wrap[^{]*\{[^}]*margin-right/', $html);
        $this->assertStringContainsString('<div class="pc-wrap">', $html);
    }

    public static function roles(): array
    {
        return ['admin' => ['admin'], 'staff' => ['staff'], 'supervisor' => ['supervisor']];
    }

    public function test_the_page_looks_the_same_with_and_without_a_request(): void
    {
        $user = $this->portal('staff');

        $without = $this->actingAs($user, 'admin')->get('/admin/home')->assertOk()->getContent();

        HelpRequest::create([
            'branch_id' => 1, 'table_number' => 'PANEL-' . Str::upper(Str::random(4)),
            'status' => 'pending', 'message' => 'x', 'requested_at' => now(),
        ]);
        $with = $this->actingAs($user, 'admin')->get('/admin/home')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="pc-wrap">', $without);
        $this->assertStringContainsString('<div class="pc-wrap">', $with, 'the wrapper changes class when a request exists');
        $this->assertStringNotContainsString('class="pc-toasts"', $without);
        $this->assertStringContainsString('class="pc-toasts"', $with);
    }

    public function test_the_live_refresh_no_longer_toggles_a_layout_class(): void
    {
        $html = $this->actingAs($this->portal('admin'), 'admin')->get('/admin/home')->assertOk()->getContent();

        // The poll still swaps the two regions, and only those.
        $this->assertStringContainsString("var SWAP_IDS = ['pc-live-region', 'pc-help-toasts-slot'];", $html);
        $this->assertStringNotContainsString("classList.toggle(\n                            'has-notifications'", $html);
    }
}
