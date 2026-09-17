<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * routes/web.php's /admin/orders/{id}/... routes had no whereNumber
 * constraint. A non-numeric id (e.g. "abc") reached the controller, whose
 * methods all type-hint `int $id`, and PHP threw a raw TypeError — a 500,
 * not a handled 404.
 *
 * Each route is checked both ways so the two failure modes are never
 * conflated: a non-numeric id must be rejected by the ROUTE constraint
 * (404 before the controller runs), while a numeric-but-nonexistent id
 * must still reach the controller and fail via Eloquent's
 * findOrFail()/model-not-found path (also a 404, but a different one).
 */
class AdminOrderRouteIdConstraintTest extends TestCase
{
    use DatabaseTransactions;

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    public static function orderRoutesProvider(): array
    {
        return [
            'prepare'          => ['orders/%s/prepare'],
            'serve'            => ['orders/%s/serve'],
            'complete'         => ['orders/%s/complete'],
            'cancel'           => ['orders/%s/cancel'],
            'discount approve' => ['orders/%s/discount/approve'],
            'discount reject'  => ['orders/%s/discount/reject'],
            'payment approve'  => ['orders/%s/payment/approve'],
            'payment reject'   => ['orders/%s/payment/reject'],
            'payment refunded' => ['orders/%s/payment/refunded'],
        ];
    }

    /** @dataProvider orderRoutesProvider */
    public function test_non_numeric_id_returns_clean_404(string $template): void
    {
        $path = '/admin/' . sprintf($template, 'abc');

        $this->actingAs($this->staff(), 'admin')
            ->put($path)
            ->assertNotFound();
    }

    /** @dataProvider orderRoutesProvider */
    public function test_valid_but_nonexistent_numeric_id_still_404s_via_model_not_found(string $template): void
    {
        $missingId = 999999999;

        $path = '/admin/' . sprintf($template, $missingId);

        $response = $this->actingAs($this->staff(), 'admin')
            ->put($path);

        $response->assertNotFound();
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class,
            $response->exception,
            'expected the 404 to come from model-not-found, not the route constraint'
        );
    }
}
