<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3b F1 (customer half) — 8 /customer/... routes typed `int $id` with
 * no ->whereNumber() constraint. A non-numeric id (e.g. "abc") reached the
 * controller and PHP threw a raw TypeError -> 500, and every one of these 8
 * is reachable by an UNAUTHENTICATED guest just by editing a URL, since the
 * customer route group carries no auth middleware.
 *
 * Same two-part shape as AdminOrderRouteIdConstraintTest: a non-numeric id
 * must be a clean 404 from the ROUTE constraint (before the controller
 * runs), and a valid-but-nonexistent numeric id must still reach the
 * controller and 404 exactly as it did before this fix — whereNumber's
 * [0-9]+ pattern matches any valid integer id, so it cannot change that path.
 */
class CustomerRouteIdConstraintTest extends TestCase
{
    use DatabaseTransactions;

    public static function routesProvider(): array
    {
        return [
            'receipt'                   => ['GET',  'receipt/%s'],
            'orders.rating.show'        => ['GET',  'orders/%s/rating'],
            'orders.rating (submit)'    => ['POST', 'orders/%s/rating'],
            'orders.cancel'             => ['POST', 'orders/%s/cancel'],
            'continue-without-discount' => ['POST', 'orders/%s/continue-without-discount'],
            'gcash-payment'             => ['GET',  'gcash-payment/%s'],
            'gcash-payment.paid'        => ['POST', 'gcash-payment/%s/paid'],
            'gcash-payment.status'      => ['GET',  'gcash-payment/%s/status'],
        ];
    }

    /** @dataProvider routesProvider */
    public function test_non_numeric_id_returns_clean_404(string $method, string $template): void
    {
        $path = '/customer/' . sprintf($template, 'abc');

        $this->call($method, $path)->assertNotFound();
    }

    public static function routesThatStill404OnMissingOrderProvider(): array
    {
        return [
            'receipt'                   => ['GET',  'receipt/%s', []],
            'orders.rating (submit)'    => ['POST', 'orders/%s/rating', ['rating' => 5]],
            'orders.cancel'             => ['POST', 'orders/%s/cancel', []],
            'continue-without-discount' => ['POST', 'orders/%s/continue-without-discount', []],
            'gcash-payment'             => ['GET',  'gcash-payment/%s', []],
            'gcash-payment.paid'        => ['POST', 'gcash-payment/%s/paid', []],
            'gcash-payment.status'      => ['GET',  'gcash-payment/%s/status', []],
        ];
    }

    /** @dataProvider routesThatStill404OnMissingOrderProvider */
    public function test_valid_but_nonexistent_numeric_id_still_404s(string $method, string $template, array $data): void
    {
        $missingId = 999999999;
        $path = '/customer/' . sprintf($template, $missingId);

        $this->call($method, $path, $data)->assertNotFound();
    }

    /**
     * orderRating (GET) is deliberately the one exception in this group: it
     * answers "has this visitor already rated this order" for the
     * completed-order popup and returns rated:false for an order the visitor
     * holds no claim to, rather than 404ing. Unaffected by the whereNumber
     * fix either way — documented here so a future pass does not mistake
     * this shape for a regression.
     */
    public function test_order_rating_read_returns_unrated_for_missing_order(): void
    {
        $this->getJson('/customer/orders/999999999/rating')
            ->assertOk()
            ->assertJson(['rated' => false, 'rating' => null]);
    }
}
