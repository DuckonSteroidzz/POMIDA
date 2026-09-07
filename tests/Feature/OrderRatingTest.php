<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rating a completed order, as a guest and as a logged-in customer.
 *
 * Every case runs inside a transaction that is rolled back, so no fabricated
 * orders or ratings survive the run.
 */
class OrderRatingTest extends TestCase
{
    use DatabaseTransactions;

    private function makeOrder(array $attributes = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'order_number' => 'RT-' . substr(uniqid(), -8),
            'user_id'      => null,
            'branch_id'    => 1,
            'type'         => 'dine_in',
            'table_number' => '5',
            'status'       => 'completed',
            'subtotal'     => 100,
            'total'        => 100,
            'completed_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ], $attributes));
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->firstOrFail();
    }

    // ══════════ Guest ══════════

    public function test_guest_can_rate_their_own_completed_order(): void
    {
        $orderId = $this->makeOrder();

        $res = $this->withSession(['guest_order_id' => $orderId])
            ->postJson("/customer/orders/{$orderId}/rating", ['rating' => 4]);

        $res->assertOk();
        $res->assertJson(['ok' => true, 'rating' => 4]);

        $row = DB::table('order_ratings')->where('order_id', $orderId)->first();

        $this->assertNotNull($row, 'rating was not persisted');
        $this->assertSame(4, (int) $row->rating);
        $this->assertNull($row->user_id, 'a guest rating must not be attributed to a user');
        $this->assertSame('stars', $row->rating_type);
    }

    public function test_guest_rating_is_readable_back_through_the_show_endpoint(): void
    {
        $orderId = $this->makeOrder();

        $this->withSession(['guest_order_id' => $orderId])
            ->postJson("/customer/orders/{$orderId}/rating", ['rating' => 5]);

        $this->withSession(['guest_order_id' => $orderId])
            ->getJson("/customer/orders/{$orderId}/rating")
            ->assertOk()
            ->assertJson(['rated' => true, 'rating' => 5]);
    }

    public function test_unrated_order_reports_itself_as_unrated(): void
    {
        $orderId = $this->makeOrder();

        $this->withSession(['guest_order_id' => $orderId])
            ->getJson("/customer/orders/{$orderId}/rating")
            ->assertOk()
            ->assertJson(['rated' => false, 'rating' => null]);
    }

    public function test_guest_cannot_rate_an_order_their_session_did_not_place(): void
    {
        $mine = $this->makeOrder();
        $someoneElse = $this->makeOrder();

        $this->withSession(['guest_order_id' => $mine])
            ->postJson("/customer/orders/{$someoneElse}/rating", ['rating' => 5])
            ->assertStatus(404);

        $this->assertDatabaseMissing('order_ratings', ['order_id' => $someoneElse]);
    }

    public function test_guest_cannot_rate_an_order_belonging_to_an_account(): void
    {
        $accountOrder = $this->makeOrder(['user_id' => $this->customer()->id]);

        // Even with the id in session, a guest may not touch an account's order.
        $this->withSession(['guest_order_id' => $accountOrder])
            ->postJson("/customer/orders/{$accountOrder}/rating", ['rating' => 5])
            ->assertStatus(404);

        $this->assertDatabaseMissing('order_ratings', ['order_id' => $accountOrder]);
    }

    public function test_guest_with_no_session_cannot_rate(): void
    {
        $orderId = $this->makeOrder();

        $this->postJson("/customer/orders/{$orderId}/rating", ['rating' => 5])
            ->assertStatus(404);

        $this->assertDatabaseMissing('order_ratings', ['order_id' => $orderId]);
    }

    // ══════════ Logged-in customer ══════════

    public function test_logged_in_customer_can_rate_their_own_order(): void
    {
        $customer = $this->customer();
        $orderId = $this->makeOrder(['user_id' => $customer->id, 'type' => 'pick_up', 'table_number' => null]);

        $this->actingAs($customer, 'customer')
            ->postJson("/customer/orders/{$orderId}/rating", ['rating' => 3])
            ->assertOk()
            ->assertJson(['ok' => true, 'rating' => 3]);

        $row = DB::table('order_ratings')->where('order_id', $orderId)->first();

        $this->assertSame(3, (int) $row->rating);
        $this->assertSame($customer->id, (int) $row->user_id);
    }

    public function test_logged_in_customer_cannot_rate_another_customers_order(): void
    {
        $customer = $this->customer();
        $otherOrder = $this->makeOrder(['user_id' => null]);

        $this->actingAs($customer, 'customer')
            ->postJson("/customer/orders/{$otherOrder}/rating", ['rating' => 5])
            ->assertStatus(404);

        $this->assertDatabaseMissing('order_ratings', ['order_id' => $otherOrder]);
    }

    public function test_form_post_still_redirects_for_the_order_history_modal(): void
    {
        $customer = $this->customer();
        $orderId = $this->makeOrder(['user_id' => $customer->id]);

        $this->actingAs($customer, 'customer')
            ->from('/customer/orders')
            ->post("/customer/orders/{$orderId}/rating", ['rating' => 5, 'rating_type' => 'stars'])
            ->assertRedirect('/customer/orders');

        $this->assertSame('Thanks for rating your meal!', session('rating_success'));
        $this->assertDatabaseHas('order_ratings', ['order_id' => $orderId, 'rating' => 5]);
    }

    // ══════════ Guards ══════════

    public function test_an_order_can_only_be_rated_once(): void
    {
        $orderId = $this->makeOrder();

        $this->withSession(['guest_order_id' => $orderId])
            ->postJson("/customer/orders/{$orderId}/rating", ['rating' => 2])
            ->assertOk();

        $second = $this->withSession(['guest_order_id' => $orderId])
            ->postJson("/customer/orders/{$orderId}/rating", ['rating' => 5]);

        $second->assertStatus(409);
        $second->assertJson(['ok' => false, 'rated' => true, 'rating' => 2]);

        // The original rating must survive the second attempt untouched.
        $this->assertSame(1, DB::table('order_ratings')->where('order_id', $orderId)->count());
        $this->assertSame(2, (int) DB::table('order_ratings')->where('order_id', $orderId)->value('rating'));
    }

    public static function unratableStatuses(): array
    {
        return [
            ['pending'], ['preparing'], ['serving'], ['cancelled'],
        ];
    }

    /** @dataProvider unratableStatuses */
    public function test_an_order_that_is_not_completed_cannot_be_rated(string $status): void
    {
        $orderId = $this->makeOrder(['status' => $status, 'completed_at' => null]);

        $this->withSession(['guest_order_id' => $orderId])
            ->postJson("/customer/orders/{$orderId}/rating", ['rating' => 5])
            ->assertStatus(422)
            ->assertJsonFragment(['ok' => false]);

        $this->assertDatabaseMissing('order_ratings', ['order_id' => $orderId]);
    }

    public function test_a_walk_in_order_cannot_be_rated(): void
    {
        $orderId = $this->makeOrder(['type' => 'walk_in']);

        $this->withSession(['guest_order_id' => $orderId])
            ->postJson("/customer/orders/{$orderId}/rating", ['rating' => 5])
            ->assertStatus(422);

        $this->assertDatabaseMissing('order_ratings', ['order_id' => $orderId]);
    }

    public static function invalidRatings(): array
    {
        return [
            'zero'        => [0],
            'six'         => [6],
            'negative'    => [-1],
            'not a number' => ['abc'],
            'null'        => [null],
        ];
    }

    /** @dataProvider invalidRatings */
    public function test_rating_value_is_validated($value): void
    {
        $orderId = $this->makeOrder();

        $this->withSession(['guest_order_id' => $orderId])
            ->postJson("/customer/orders/{$orderId}/rating", ['rating' => $value])
            ->assertStatus(422);

        $this->assertDatabaseMissing('order_ratings', ['order_id' => $orderId]);
    }

    public function test_rating_a_missing_order_is_a_404_not_a_500(): void
    {
        $this->withSession(['guest_order_id' => 999999])
            ->postJson('/customer/orders/999999/rating', ['rating' => 5])
            ->assertStatus(404);
    }

    public function test_show_endpoint_never_leaks_another_visitors_rating(): void
    {
        $mine = $this->makeOrder();
        $theirs = $this->makeOrder();

        $this->withSession(['guest_order_id' => $theirs])
            ->postJson("/customer/orders/{$theirs}/rating", ['rating' => 5])
            ->assertOk();

        $this->withSession(['guest_order_id' => $mine])
            ->getJson("/customer/orders/{$theirs}/rating")
            ->assertOk()
            ->assertJson(['rated' => false, 'rating' => null]);
    }
}
