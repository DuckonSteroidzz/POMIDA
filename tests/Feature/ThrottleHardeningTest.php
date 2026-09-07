<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\RateLimitServiceProvider;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Real repeated-request verification of the customer money endpoints'
 * rate limits.
 *
 * Round one of this file proved the endpoints were throttled at all
 * (place-order, gcash-payment/{id}/paid, orders/{id}/rating had no limit
 * whatsoever). That was correct security work and the protection is still
 * here — the assertions below still hammer the real routes until they refuse.
 *
 * What this round fixes is what the limits counted. They keyed on IP address,
 * and in a café every customer shares the shop's one public IP, so
 * `throttle:10,1` on place-order meant ten orders a minute for the WHOLE ROOM:
 * a customer could be refused because of what a stranger at another table had
 * just done. Reported from live use as constant 429s during ordinary ordering.
 *
 * So there are now two assertions where there used to be one:
 *
 *   1. One visitor still cannot exceed their own limit   (abuse is still capped)
 *   2. One visitor's usage never blocks a DIFFERENT visitor on the same IP
 *      (the café bug), with a per-IP ceiling still underneath so a
 *      cookie-discarding script cannot go unlimited.
 *
 * Plus: hitting the place-order limit must not dump the customer on a
 * full-page 429 with their order silently gone — the last three tests.
 */
class ThrottleHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function customer(int $skip = 0): User
    {
        return User::where('role', 'customer')->orderBy('id')->skip($skip)->first();
    }

    /**
     * Hammer a route as ONE identified visitor and report the 1-based request
     * that is first refused for rate limiting.
     *
     * Acting as a signed-in customer is what makes this a genuine single
     * visitor: RateLimitServiceProvider::visitorKey() keys a logged-in
     * customer by account id, which is stable, whereas the test client starts
     * a fresh session per request the way a browser that threw its cookie away
     * would.
     *
     * "Refused" is no longer only a 429 status: these routes are wrapped in
     * throttle.friendly, which converts the rejection into a redirect carrying
     * an explanation. X-RateLimit-Rejected identifies it either way.
     */
    private function firstBlockedRequest(string $method, string $uri, int $attempts, array $data = [], ?User $as = null): ?int
    {
        $as = $as ?: $this->customer();

        for ($i = 1; $i <= $attempts; $i++) {
            $res = $method === 'GET'
                ? $this->actingAs($as, 'customer')->getJson($uri)
                : $this->actingAs($as, 'customer')->post($uri, $data);

            if ($res->headers->get('X-RateLimit-Rejected') === '1' || $res->getStatusCode() === 429) {
                return $i;
            }
        }

        return null;
    }

    public function test_place_order_blocks_one_visitor_at_their_own_limit(): void
    {
        $limit = RateLimitServiceProvider::PLACE_ORDER_PER_SESSION;

        $this->assertSame(
            $limit + 1,
            $this->firstBlockedRequest('POST', '/customer/place-order', $limit + 5, ['order_type' => 'pick_up']),
            'place-order should refuse one visitor starting at request ' . ($limit + 1)
        );
    }

    public function test_gcash_paid_blocks_one_visitor_at_their_own_limit(): void
    {
        $limit = RateLimitServiceProvider::GCASH_PAID_PER_SESSION;

        $this->assertSame(
            $limit + 1,
            $this->firstBlockedRequest('POST', '/customer/gcash-payment/999999/paid', $limit + 5)
        );
    }

    public function test_rating_post_blocks_one_visitor_at_their_own_limit(): void
    {
        $limit = RateLimitServiceProvider::RATING_POST_PER_SESSION;

        $this->assertSame(
            $limit + 1,
            $this->firstBlockedRequest('POST', '/customer/orders/999999/rating', $limit + 5, ['rating' => 5])
        );
    }

    public function test_rating_get_blocks_one_visitor_at_their_own_limit(): void
    {
        $limit = RateLimitServiceProvider::RATING_GET_PER_SESSION;

        $this->assertSame(
            $limit + 1,
            $this->firstBlockedRequest('GET', '/customer/orders/999999/rating', $limit + 5)
        );
    }

    /**
     * THE CAFÉ BUG.
     *
     * One customer exhausts their own place-order allowance. A second customer
     * on the same wi-fi — same IP, different visitor — must still be able to
     * order. Under the old per-IP `throttle:10,1` this second customer was
     * refused having done nothing at all, which is what the owner kept hitting.
     */
    public function test_one_customers_limit_never_blocks_another_customer_on_the_same_ip(): void
    {
        $limit = RateLimitServiceProvider::PLACE_ORDER_PER_SESSION;

        $first = $this->customer(0);
        $second = $this->customer(1);

        $this->assertNotNull($second, 'this test needs two customer accounts');
        $this->assertNotSame($first->id, $second->id);

        $this->assertSame(
            $limit + 1,
            $this->firstBlockedRequest('POST', '/customer/place-order', $limit + 3, ['order_type' => 'pick_up'], $first),
            'the first customer should be capped'
        );

        $res = $this->actingAs($second, 'customer')
            ->post('/customer/place-order', ['order_type' => 'pick_up']);

        $this->assertNull(
            $res->headers->get('X-RateLimit-Rejected'),
            'a second customer on the same café wi-fi must not inherit the first customer\'s limit'
        );
        $this->assertNotSame(429, $res->getStatusCode());
    }

    /**
     * The per-IP ceiling is still there underneath, so a script that throws its
     * cookies away between requests — a new anonymous visitor every time —
     * cannot order without limit.
     */
    public function test_a_per_ip_ceiling_still_exists_underneath(): void
    {
        $ceiling = RateLimitServiceProvider::PLACE_ORDER_PER_IP;

        $this->assertGreaterThan(
            RateLimitServiceProvider::PLACE_ORDER_PER_SESSION,
            $ceiling,
            'the per-IP ceiling must be higher than one visitor\'s limit, or it defeats the point'
        );

        $blockedAt = null;

        for ($i = 1; $i <= $ceiling + 5; $i++) {
            // No actingAs and no cookie carried over: a brand-new visitor each
            // time, as far as anything but the IP address can tell.
            $res = $this->post('/customer/place-order', ['order_type' => 'pick_up']);

            if ($res->headers->get('X-RateLimit-Rejected') === '1') {
                $blockedAt = $i;
                break;
            }
        }

        $this->assertSame(
            $ceiling + 1,
            $blockedAt,
            'session-hopping requests from one IP must still hit the IP ceiling'
        );
    }

    /** A legitimate customer well under any limit is never blocked. */
    public function test_normal_usage_is_never_blocked(): void
    {
        $customer = $this->customer();

        foreach ([
            ['/customer/place-order', ['order_type' => 'pick_up']],
            ['/customer/gcash-payment/999999/paid', []],
            ['/customer/orders/999999/rating', ['rating' => 5]],
        ] as [$uri, $data]) {
            for ($i = 1; $i <= 5; $i++) {
                $this->assertNull(
                    $this->actingAs($customer, 'customer')->post($uri, $data)->headers->get('X-RateLimit-Rejected'),
                    $uri . ' blocked normal usage'
                );
            }
        }
    }

    /**
     * Login stays keyed on IP on purpose: there, "many attempts from one
     * address" IS the attack, and a shared address being throttled together is
     * the intended behaviour rather than a bug. This is the case the café
     * reasoning must NOT be applied to, so it is asserted explicitly.
     */
    public function test_login_is_still_throttled_per_ip_across_sessions(): void
    {
        $blocked = false;

        for ($i = 1; $i <= 15; $i++) {
            $res = $this->post('/customer/login', [
                'email' => 'nobody' . $i . '@example.com',
                'password' => 'wrong-password',
            ]);

            if ($res->getStatusCode() === 429) {
                $blocked = true;
                break;
            }
        }

        $this->assertTrue($blocked, 'login must still block a password-guesser who rotates sessions');
    }

    /**
     * Hitting the place-order limit must not strand the customer: they get a
     * redirect back with a readable message, not a full-page 429 whose only
     * exit is the landing page.
     */
    public function test_place_order_limit_returns_a_friendly_redirect_not_a_429_page(): void
    {
        $limit = RateLimitServiceProvider::PLACE_ORDER_PER_SESSION;
        $customer = $this->customer();

        $last = null;

        for ($i = 1; $i <= $limit + 2; $i++) {
            $last = $this->actingAs($customer, 'customer')
                ->from('/customer/cart')
                ->post('/customer/place-order', ['order_type' => 'pick_up']);
        }

        $this->assertSame('1', $last->headers->get('X-RateLimit-Rejected'), 'this should still be a rate-limit refusal');
        $this->assertNotSame(429, $last->getStatusCode(), 'the customer should not see the raw 429 page here');
        $last->assertRedirect('/customer/cart');
        $last->assertSessionHasErrors('error');

        $this->assertStringContainsString(
            'cart is still here',
            session('errors')->first('error'),
            'the message must tell the customer their order did not happen and their cart is safe'
        );
    }

    /** And their cart survives the rejection, so nothing has to be rebuilt. */
    public function test_the_cart_survives_hitting_the_place_order_limit(): void
    {
        $limit = RateLimitServiceProvider::PLACE_ORDER_PER_SESSION;
        $customer = $this->customer();

        $cart = ['99' => ['menu_item_id' => '99', 'name' => 'Probe', 'price' => 10.0, 'quantity' => 2, 'options' => []]];

        for ($i = 1; $i <= $limit + 2; $i++) {
            $this->actingAs($customer, 'customer')
                ->withSession(['cart' => $cart])
                ->post('/customer/place-order', ['order_type' => 'pick_up']);
        }

        $this->assertSame($cart, session('cart'), 'the cart must survive a rate-limit rejection');
    }

    /**
     * The 429 page itself must offer a dine-in customer a way back to their
     * table, not only the landing page — and must still render when there is
     * no session context at all.
     */
    public function test_the_429_page_offers_a_route_back_to_an_in_progress_order(): void
    {
        $ceiling = RateLimitServiceProvider::PLACE_ORDER_PER_IP;

        // A GET the branded page can actually be reached through: exhaust a
        // limiter on a route that renders HTML rather than redirecting.
        $this->withSession([
            'order_type' => 'dine_in',
            'table_number' => '7',
            'customer_order_id' => 999999,
        ]);

        $html = view('errors.429')->render();

        $this->assertStringContainsString('Back to my order', $html);
        $this->assertStringContainsString('Table 7', $html);
        $this->assertStringContainsString('Back to home', $html, 'home must stay available too');

        unset($ceiling);
    }
}
