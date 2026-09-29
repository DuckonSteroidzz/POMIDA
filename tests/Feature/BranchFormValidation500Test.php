<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Branch parity B1: storeBranch()/updateBranch() used to validate 'address'
 * as nullable even though branches.address is NOT NULL in the migration, and
 * validated 'code' as max:20 even though branches.code is varchar(10). Both
 * mismatches let a request pass Laravel validation and then blow up as an
 * uncaught QueryException (500) at the database layer instead of coming back
 * as a normal validation error.
 */
class BranchFormValidation500Test extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'B1VALID';

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    public function test_store_branch_with_blank_address_is_rejected_not_500(): void
    {
        $name = self::PREFIX . ' Blank Address ' . uniqid();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.branches.store'), [
                'name' => $name,
                'code' => 'B1A' . substr(uniqid(), -4),
                'address' => '',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('address');
        $this->assertDatabaseMissing('branches', ['name' => $name]);
    }

    public function test_store_branch_with_long_code_is_rejected_not_500(): void
    {
        $name = self::PREFIX . ' Long Code ' . uniqid();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.branches.store'), [
                'name' => $name,
                'code' => 'ABCDEFGHIJKLMNO', // 15 chars, over the 10-char column
                'address' => self::PREFIX . ' address',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('branches', ['name' => $name]);
    }

    public function test_update_branch_clearing_address_is_rejected_not_500(): void
    {
        $admin = $this->admin();
        $name = self::PREFIX . ' For Update ' . uniqid();

        $store = $this->actingAs($admin, 'admin')
            ->post(route('admin.branches.store'), [
                'name' => $name,
                'code' => 'B1U' . substr(uniqid(), -4),
                'address' => self::PREFIX . ' original address',
            ]);
        $store->assertStatus(302);
        $store->assertSessionDoesntHaveErrors();

        $branch = Branch::where('name', $name)->firstOrFail();

        $response = $this->actingAs($admin, 'admin')
            ->put(route('admin.branches.update', $branch->id), [
                'name' => $branch->name,
                'address' => '',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('address');
        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'address' => self::PREFIX . ' original address',
        ]);
    }

    public function test_store_branch_with_valid_data_still_succeeds(): void
    {
        $name = self::PREFIX . ' Valid ' . uniqid();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.branches.store'), [
                'name' => $name,
                'code' => 'B1V' . substr(uniqid(), -4),
                'address' => self::PREFIX . ' a perfectly fine address',
            ]);

        $response->assertStatus(302);
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('branches', ['name' => $name]);
    }

    public function test_update_branch_with_valid_address_still_succeeds(): void
    {
        $admin = $this->admin();
        $name = self::PREFIX . ' For Valid Update ' . uniqid();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.branches.store'), [
                'name' => $name,
                'code' => 'B1W' . substr(uniqid(), -4),
                'address' => self::PREFIX . ' original address',
            ])->assertStatus(302);

        $branch = Branch::where('name', $name)->firstOrFail();

        $response = $this->actingAs($admin, 'admin')
            ->put(route('admin.branches.update', $branch->id), [
                'name' => $branch->name,
                'address' => self::PREFIX . ' updated address',
            ]);

        $response->assertStatus(302);
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'address' => self::PREFIX . ' updated address',
        ]);
    }
}
