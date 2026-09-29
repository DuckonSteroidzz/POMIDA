<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Spin & Win switch is ONE global settings row, and the owner's toggle,
 * the game page, the Vouchers-page label and the spin endpoint all use it
 * (2026-09-27, the stale-row fix that followed F4).
 *
 * WHAT WAS WRONG
 * --------------
 * pomida_db and pomida_db_testing each held exactly one game_enabled row,
 * id 1, branch_id = 1, value '1'. It came from an older seed (group 'game',
 * type 'boolean'). AdminController::toggleGame() writes the global row
 * (branch_id NULL), but every reader took the first game_enabled row with no
 * branch filter. So the owner's first "switch off" INSERTED a new NULL row
 * '0', flashed "Game disabled!", and every reader still saw row 1 = '1'. The
 * wheel stayed up and addPoints() kept granting spins. Every later press
 * repeated this. Reproduced before the fix by running the real toggleGame()
 * three times against pomida_db_testing inside a rolled-back transaction.
 *
 * WHY GLOBAL, NOT PER-BRANCH
 * --------------------------
 * The owner gets one switch: one card on the Vouchers page with no branch
 * picker, labelled "Enable or disable the game for customers", marked in the
 * view as system-level configuration for the whole system. The seeder and the
 * toggle both write branch_id NULL, and the settings migration defines NULL
 * as "global setting". The branch-1 row was the odd one out, so it is the
 * row that was corrected (branch_id set to NULL, value kept).
 *
 * Every test builds the rows it needs inside its own transaction, which
 * DatabaseTransactions rolls back.
 */
class GameSwitchSingleRowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        DB::table('settings')->where('key', 'game_enabled')->delete();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ══════════ fixtures ══════════

    private function insertSwitchRow(?int $branchId, string $value): void
    {
        DB::table('settings')->insert([
            'key'        => 'game_enabled',
            'branch_id'  => $branchId,
            'value'      => $value,
            'group'      => $branchId === null ? 'business' : 'game',
            'label'      => $branchId === null ? 'Spin & Win Enabled' : 'Enable Spin Wheel Game',
            'type'       => $branchId === null ? 'text' : 'boolean',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function owner(): User
    {
        return User::where('role', 'admin')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    /** Press the Vouchers-page switch exactly as the owner does. */
    private function ownerPressesTheSwitch(string $expectedFlash): void
    {
        $this->actingAs($this->owner(), 'admin')
            ->post('/admin/game/toggle')
            ->assertRedirect(route('admin.vouchers'))
            ->assertSessionHas('success', $expectedFlash);
    }

    /** @return list<string> "branch:value" for every game_enabled row, in id order */
    private function switchRows(): array
    {
        return DB::table('settings')->where('key', 'game_enabled')->orderBy('id')->get()
            ->map(fn ($r) => ($r->branch_id ?? 'global') . ':' . $r->value)
            ->all();
    }

    /**
     * What each of the four places that read the switch currently says.
     * They must always be the same.
     *
     * @return array{owner_label:bool, page:bool, endpoint:bool}
     */
    private function whatEveryReaderSays(): array
    {
        $label = $this->actingAs($this->owner(), 'admin')->get('/admin/vouchers')->assertOk()->getContent();

        $order = Order::create([
            'order_number'   => 'GSR-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 500,
            'total'          => 500,
        ]);
        GuestOrders::remember($order->id);

        $page = $this->get('/customer/game')->assertOk()->getContent();
        $spin = $this->postJson('/customer/add-points');

        $labelOn = str_contains($label, '✓ Enabled');
        $labelOff = str_contains($label, '✗ Disabled');
        $this->assertNotSame($labelOn, $labelOff, 'the Vouchers-page switch rendered neither or both labels');

        if ($spin->status() !== 200) {
            $this->assertSame('game_disabled', $spin->json('blocked'), 'setup: the spin was refused for another reason');
        }

        return [
            'owner_label' => $labelOn,
            'page'        => str_contains($page, 'id="spinBtn"'),
            'endpoint'    => $spin->status() === 200,
        ];
    }

    private function assertEveryReaderSays(bool $on, string $when): void
    {
        $this->assertSame(
            ['owner_label' => $on, 'page' => $on, 'endpoint' => $on],
            $this->whatEveryReaderSays(),
            "{$when}: the switch should read " . ($on ? 'ON' : 'OFF') . ' everywhere'
        );
    }

    // ══════════ the owner's switch works ══════════

    public function test_switching_the_game_off_refuses_the_very_next_spin(): void
    {
        $this->insertSwitchRow(null, '1');
        $this->assertEveryReaderSays(true, 'before the press');

        $this->ownerPressesTheSwitch('Game disabled!');

        $this->assertEveryReaderSays(false, 'straight after "Game disabled!"');
        $this->assertSame(['global:0'], $this->switchRows(), 'the press must update the one row, not add another');

        $this->ownerPressesTheSwitch('Game enabled!');

        $this->assertEveryReaderSays(true, 'after switching it back on');
        $this->assertSame(['global:1'], $this->switchRows());
    }

    /**
     * The exact failure the live database would have produced: a branch-1
     * '1' row that sorts BEFORE the global row. Under the old unfiltered read
     * it won every time, so "Game disabled!" changed nothing.
     */
    public function test_a_leftover_branch_row_cannot_keep_the_game_on(): void
    {
        $this->insertSwitchRow(1, '1');
        $this->insertSwitchRow(null, '1');

        $this->ownerPressesTheSwitch('Game disabled!');

        $this->assertEveryReaderSays(false, 'with a stray branch-1 "1" row still present');
        $this->assertSame(['1:1', 'global:0'], $this->switchRows(), 'the toggle wrote to a row other than the global one');
    }

    /**
     * The shape both databases were in before the data fix, for any copy
     * that has not had it (an older dump, a teammate's database). The legacy
     * row is simply not the switch. Every reader agrees the game is off,
     * the first press creates the global row and turns it on, and the switch
     * works normally from then on. There is no step where they disagree.
     */
    public function test_a_database_that_still_has_only_the_legacy_row_stays_consistent(): void
    {
        $this->insertSwitchRow(1, '1');

        $this->assertEveryReaderSays(false, 'with only the legacy branch-1 row');

        $this->ownerPressesTheSwitch('Game enabled!');
        $this->assertEveryReaderSays(true, 'after the first press');

        $this->ownerPressesTheSwitch('Game disabled!');
        $this->assertEveryReaderSays(false, 'after the second press');

        $this->assertSame(['1:1', 'global:0'], $this->switchRows());
    }
}
