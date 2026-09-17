<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression guard for the missing game_enabled seed row (Sept 2026,
 * lower-severity bundle item #2).
 *
 * Nothing seeded the game_enabled row that gates Spin & Win. The read side
 * already treats a missing row as disabled (safe default), but
 * AdminController::toggleGame() did a bare
 *
 *     DB::table('settings')->where('key', 'game_enabled')->update(...)
 *
 * — an UPDATE against a row that does not exist affects 0 rows and does
 * nothing, while the controller still redirected with a flashed "Game
 * enabled!" success message. On a fresh environment the admin toggle was
 * therefore permanently broken: it always claimed success and never
 * actually created the row, so the feature could never be turned on.
 *
 * toggleGame() now uses updateOrInsert(), so it self-heals even before a
 * reseed. This file recreates the "row genuinely absent" scenario directly
 * (deletes it inside this test's own transaction, which rolls back
 * afterwards — nothing here is a permanent change) and asserts the row is
 * actually created, not just that a success message was flashed.
 */
class GameToggleMissingRowTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function deleteGameEnabledRow(): void
    {
        DB::table('settings')->where('key', 'game_enabled')->delete();
    }

    public function test_toggling_with_no_existing_row_actually_creates_it(): void
    {
        $this->deleteGameEnabledRow();

        $this->assertNull(
            DB::table('settings')->where('key', 'game_enabled')->first(),
            'setup failed: the row must be genuinely absent before the toggle'
        );

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/game/toggle')
            ->assertRedirect(route('admin.vouchers'));

        $row = DB::table('settings')->where('key', 'game_enabled')->whereNull('branch_id')->first();

        $this->assertNotNull(
            $row,
            'toggleGame() flashed success but never actually created the missing game_enabled row'
        );
        $this->assertSame('1', $row->value, 'the first toggle from a missing row must enable the game');
    }

    /**
     * The false-positive this bug produced: the flash message claimed
     * success on every call, even when the row never existed and never got
     * created. This pins that the message is no longer a false positive —
     * the row backing "enabled" must exist by the time it is shown.
     */
    public function test_the_success_flash_is_not_a_false_positive(): void
    {
        $this->deleteGameEnabledRow();

        $response = $this->actingAs($this->admin(), 'admin')->post('/admin/game/toggle');

        $response->assertSessionHas('success', 'Game enabled!');
        $this->assertNotNull(
            DB::table('settings')->where('key', 'game_enabled')->whereNull('branch_id')->first(),
            '"Game enabled!" was flashed but no row backs that claim'
        );
    }

    public function test_toggling_an_existing_row_still_flips_its_value_without_duplicating_it(): void
    {
        $this->deleteGameEnabledRow();
        DB::table('settings')->insert([
            'key'        => 'game_enabled',
            'branch_id'  => null,
            'value'      => '1',
            'group'      => 'business',
            'label'      => 'Spin & Win Enabled',
            'type'       => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/game/toggle')
            ->assertRedirect(route('admin.vouchers'))
            ->assertSessionHas('success', 'Game disabled!');

        $this->assertSame(
            1,
            DB::table('settings')->where('key', 'game_enabled')->count(),
            'toggling an existing row must update it in place, never insert a duplicate'
        );
        $this->assertSame(
            '0',
            DB::table('settings')->where('key', 'game_enabled')->whereNull('branch_id')->value('value')
        );
    }
}
