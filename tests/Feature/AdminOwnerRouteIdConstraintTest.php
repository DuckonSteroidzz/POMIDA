<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3b F1 (admin "owner only" half) — the role:admin routes typed
 * `int $id` with no ->whereNumber() constraint: archived-catalogue restore,
 * the two-stage inventory delete, voucher delete/issue-reward, and branches.
 *
 * Two behaviors coexist here, and the fix does not change which one a route
 * gets — only whether a NON-numeric id can reach the controller at all:
 *   - findOrFail() routes 404 via ModelNotFoundException for a valid-but-
 *     missing numeric id (same as the other route-id-constraint tests).
 *   - restoreArchivedCatalogue/restoreInventory/forceDeleteInventory (and,
 *     since 2026-09-24, forceDeleteArchivedMenuItem) instead
 *     use ->find($id) and redirect back with a flashed error when it is
 *     null — a deliberate design choice (see the controllers), not a bug
 *     this pass touches. Their non-numeric-id case still 404s at the ROUTE,
 *     because whereNumber runs before any of that controller logic.
 */
class AdminOwnerRouteIdConstraintTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    public static function allRoutesProvider(): array
    {
        return [
            'archived.restore'      => ['PUT',    'archived/menu-item/%s/restore'],
            'archived.menu-item.force-delete' => ['DELETE', 'archived/menu-item/%s/force'],
            'inventory.delete'      => ['DELETE', 'inventory/%s'],
            'inventory.restore'     => ['PUT',    'inventory/%s/restore'],
            'inventory.force-delete' => ['DELETE', 'inventory/%s/force'],
            'vouchers.delete'       => ['DELETE', 'vouchers/%s'],
            'vouchers.issue-reward' => ['POST',   'vouchers/%s/issue-reward'],
            'branches.update'       => ['PUT',    'branches/%s'],
            'branches.toggle'       => ['PUT',    'branches/%s/toggle'],
        ];
    }

    /** @dataProvider allRoutesProvider */
    public function test_non_numeric_id_returns_clean_404(string $method, string $template): void
    {
        $path = '/admin/' . sprintf($template, 'abc');

        $this->actingAs($this->admin(), 'admin')
            ->call($method, $path)
            ->assertNotFound();
    }

    public static function findOrFailRoutesProvider(): array
    {
        return [
            'inventory.delete'      => ['DELETE', 'inventory/%s'],
            'vouchers.delete'       => ['DELETE', 'vouchers/%s'],
            'vouchers.issue-reward' => ['POST',   'vouchers/%s/issue-reward'],
            'branches.update'       => ['PUT',    'branches/%s'],
            'branches.toggle'       => ['PUT',    'branches/%s/toggle'],
        ];
    }

    /** @dataProvider findOrFailRoutesProvider */
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

    public static function findAndRedirectRoutesProvider(): array
    {
        return [
            'archived.restore'       => ['PUT',    'archived/menu-item/%s/restore'],
            'archived.menu-item.force-delete' => ['DELETE', 'archived/menu-item/%s/force'],
            'inventory.restore'      => ['PUT',    'inventory/%s/restore'],
            'inventory.force-delete' => ['DELETE', 'inventory/%s/force'],
        ];
    }

    /**
     * @dataProvider findAndRedirectRoutesProvider
     *
     * These three deliberately do NOT 404 on a missing numeric id — they
     * redirect back with a flashed error (see the class docblock). Pinned
     * here so the whereNumber fix is never mistaken for having changed it.
     */
    public function test_valid_but_nonexistent_numeric_id_redirects_with_error(string $method, string $template): void
    {
        $missingId = 999999999;
        $path = '/admin/' . sprintf($template, $missingId);

        $response = $this->actingAs($this->admin(), 'admin')
            ->call($method, $path);

        $response->assertStatus(302);
        $response->assertSessionHasErrors();
    }
}
