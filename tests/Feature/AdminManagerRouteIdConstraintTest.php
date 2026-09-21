<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3b F1 (admin "manager tier" half) — the role:admin,supervisor routes
 * typed `int $id` with no ->whereNumber() constraint: menu items, their
 * ingredients, categories, subcategories, menu options, their ingredients,
 * inventory definitions, vouchers, and ads.
 *
 * Staff cannot reach this group at all (RoleMiddleware redirects them before
 * the route ever resolves an id), so — unlike AdminSharedRouteIdConstraintTest
 * — every case here acts as an admin.
 *
 * Two routes carry TWO int-typed parameters (deleteIngredient, deleteOptionIngredient)
 * and are tested separately: a route needs every numeric segment to match, so
 * either position being non-numeric alone must already 404 at the route.
 */
class AdminManagerRouteIdConstraintTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    public static function singleParamRoutesProvider(): array
    {
        return [
            'menu-items.toggle'          => ['PUT',    'menu-items/toggle/%s'],
            'menu-items.update'          => ['PUT',    'menu-items/%s'],
            'menu-items.delete'          => ['DELETE', 'menu-items/%s'],
            'menu-items.ingredients.add' => ['POST',   'menu-items/%s/ingredients'],
            'add-category.edit'          => ['GET',    'add-category/edit/%s'],
            'add-category.update'        => ['PUT',    'add-category/%s'],
            'add-category.delete'        => ['DELETE', 'add-category/%s'],
            'add-subcategory.update'     => ['PUT',    'add-subcategory/%s'],
            'add-subcategory.delete'     => ['DELETE', 'add-subcategory/%s'],
            'menu-options.update'        => ['PUT',    'menu-options/%s'],
            'menu-options.delete'        => ['DELETE', 'menu-options/%s'],
            'menu-options.assign'        => ['POST',   'menu-options/assign/%s'],
            'menu-options.ingredients.add' => ['POST', 'menu-options/%s/ingredients'],
            'inventory.edit'             => ['GET',    'inventory/edit/%s'],
            'inventory.update'           => ['PUT',    'inventory/%s'],
            'vouchers.update'            => ['PUT',    'vouchers/%s'],
            'vouchers.toggle'            => ['PUT',    'vouchers/%s/toggle'],
            'ads.update'                 => ['PUT',    'ads/%s'],
            'ads.toggle'                 => ['PUT',    'ads/%s/toggle'],
            'ads.delete'                 => ['DELETE', 'ads/%s'],
        ];
    }

    /** @dataProvider singleParamRoutesProvider */
    public function test_non_numeric_id_returns_clean_404(string $method, string $template): void
    {
        $path = '/admin/' . sprintf($template, 'abc');

        $this->actingAs($this->admin(), 'admin')
            ->call($method, $path)
            ->assertNotFound();
    }

    /** @dataProvider singleParamRoutesProvider */
    public function test_valid_but_nonexistent_numeric_id_still_404s_via_model_not_found(string $method, string $template): void
    {
        $missingId = 999999999;
        $path = '/admin/' . sprintf($template, $missingId);

        $response = $this->actingAs($this->admin(), 'admin')
            ->call($method, $path);

        $response->assertNotFound();
        $this->assertInstanceOf(
            ModelNotFoundException::class,
            $response->exception,
            'expected the 404 to come from model-not-found, not the route constraint'
        );
    }

    public static function twoParamRoutesProvider(): array
    {
        return [
            'menu-items.ingredients.delete'   => ['menu-items/%s/ingredients/%s'],
            'menu-options.ingredients.delete' => ['menu-options/%s/ingredients/%s'],
        ];
    }

    /** @dataProvider twoParamRoutesProvider */
    public function test_non_numeric_first_param_returns_clean_404(string $template): void
    {
        $path = '/admin/' . sprintf($template, 'abc', 1);

        $this->actingAs($this->admin(), 'admin')
            ->delete($path)
            ->assertNotFound();
    }

    /** @dataProvider twoParamRoutesProvider */
    public function test_non_numeric_second_param_returns_clean_404(string $template): void
    {
        $path = '/admin/' . sprintf($template, 1, 'abc');

        $this->actingAs($this->admin(), 'admin')
            ->delete($path)
            ->assertNotFound();
    }

    /** @dataProvider twoParamRoutesProvider */
    public function test_valid_but_nonexistent_two_param_ids_still_404_via_model_not_found(string $template): void
    {
        $path = '/admin/' . sprintf($template, 999999999, 999999998);

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete($path);

        $response->assertNotFound();
        $this->assertInstanceOf(
            ModelNotFoundException::class,
            $response->exception,
            'expected the 404 to come from model-not-found, not the route constraint'
        );
    }
}
