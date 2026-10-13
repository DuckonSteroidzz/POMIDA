<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The receipt page's two "Help" controls (desktop header and mobile bottom
 * nav) used to be little forms POSTing to customer.help-request with no
 * `message` field. That route validates `message` as required, so every tap
 * bounced straight back with a validation error the receipt never displayed:
 * the button looked like it worked and did nothing. They now link to the
 * customer Help page (customer.help), which is where a seated dine-in
 * customer can actually ask staff for help, and where everyone else finds
 * the instructions and the shop's contact details.
 */
class ReceiptHelpLinkTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::create([
            'name'      => 'Receipt Help Link Test Customer',
            'email'     => 'receipt-help-' . uniqid() . '@example.test',
            'password'  => Hash::make('irrelevant-' . uniqid()),
            'role'      => 'customer',
            'points'    => 0,
            'is_active' => true,
        ]);
    }

    private function order(User $user, string $type): Order
    {
        return Order::create([
            'order_number'   => 'RHL-' . substr(uniqid(), -8),
            'user_id'        => $user->id,
            'branch_id'      => 1,
            'type'           => $type,
            'table_number'   => $type === 'dine_in' ? '5' : null,
            'status'         => 'completed',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal'       => 80,
            'total'          => 80,
        ]);
    }

    private function receipt(User $customer, Order $order, array $session): string
    {
        return $this->actingAs($customer, 'customer')
            ->withSession($session)
            ->get(route('customer.receipt', $order->id))
            ->assertOk()
            ->getContent();
    }

    public function test_a_dine_in_receipt_links_both_help_controls_to_the_help_page(): void
    {
        $customer = $this->customer();
        $html = $this->receipt($customer, $this->order($customer, 'dine_in'), [
            'order_type' => 'dine_in', 'branch_id' => 1, 'table_number' => '5',
        ]);

        $this->assertSame(
            2,
            substr_count($html, 'href="' . route('customer.help') . '"'),
            'both the desktop and the mobile Help control should link to the Help page'
        );
        $this->assertStringNotContainsString(route('customer.help-request'), $html, 'no Help control may post to help-request any more');
    }

    public function test_a_pickup_receipt_gets_a_working_help_link_too(): void
    {
        // Before, a pick-up customer saw a greyed-out, dead "Help" label in
        // the mobile nav. The Help page serves every customer type.
        $customer = $this->customer();
        $html = $this->receipt($customer, $this->order($customer, 'pick_up'), [
            'order_type' => 'pick_up', 'branch_id' => 1,
        ]);

        $this->assertSame(2, substr_count($html, 'href="' . route('customer.help') . '"'));
        $this->assertStringNotContainsString(route('customer.help-request'), $html);
    }

    public function test_the_link_lands_on_a_working_help_page(): void
    {
        $this->withSession(['order_type' => 'dine_in', 'branch_id' => 1, 'table_number' => '5'])
            ->get(route('customer.help'))
            ->assertOk()
            ->assertSee('Request Assistance');
    }

    public function test_the_receipt_view_no_longer_posts_an_empty_help_request(): void
    {
        $source = file_get_contents(resource_path('views/customer/receipt.blade.php'));

        $this->assertStringNotContainsString("route('customer.help-request')", $source);
        $this->assertSame(2, substr_count($source, "route('customer.help')"));
    }
}
