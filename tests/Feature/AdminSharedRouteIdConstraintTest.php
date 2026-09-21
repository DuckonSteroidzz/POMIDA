<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3b F1 (admin "shared" half) — the remaining role:admin,staff,supervisor
 * routes typed `int $id` with no ->whereNumber() constraint. The sibling
 * /admin/orders/{id}/... routes in this same role group already got this fix
 * and their own test, AdminOrderRouteIdConstraintTest; this file covers the
 * rest of that group: receipt, help-requests assist/resolve, and
 * vouchers.issue-code.
 *
 * Same two-part shape as that test: a non-numeric id must be a clean 404 from
 * the ROUTE constraint, and a valid-but-nonexistent id must still reach the
 * controller and 404 via model-not-found (all four resolve their record via
 * findOrFail() or AdminOrderAccess::resolveRecordInScope()/resolveInScope(),
 * which throws the same ModelNotFoundException).
 */
class AdminSharedRouteIdConstraintTest extends TestCase
{
    use DatabaseTransactions;

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    public static function routesProvider(): array
    {
        return [
            'receipt'               => ['GET',  'receipt/%s'],
            'help-requests.assist'  => ['PUT',  'help-requests/%s/assist'],
            'help-requests.resolve' => ['PUT',  'help-requests/%s/resolve'],
            'vouchers.issue-code'   => ['POST', 'vouchers/%s/issue-code'],
        ];
    }

    /** @dataProvider routesProvider */
    public function test_non_numeric_id_returns_clean_404(string $method, string $template): void
    {
        $path = '/admin/' . sprintf($template, 'abc');

        $this->actingAs($this->staff(), 'admin')
            ->call($method, $path)
            ->assertNotFound();
    }

    /** @dataProvider routesProvider */
    public function test_valid_but_nonexistent_numeric_id_still_404s_via_model_not_found(string $method, string $template): void
    {
        $missingId = 999999999;
        $path = '/admin/' . sprintf($template, $missingId);

        $response = $this->actingAs($this->staff(), 'admin')
            ->call($method, $path);

        $response->assertNotFound();
        $this->assertInstanceOf(
            ModelNotFoundException::class,
            $response->exception,
            'expected the 404 to come from model-not-found, not the route constraint'
        );
    }
}
