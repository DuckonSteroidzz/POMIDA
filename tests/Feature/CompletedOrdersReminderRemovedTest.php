<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The Completed Orders "kept on purpose" reminder box, removed on request.
 *
 * The owner asked for the on-screen paragraph gone — it read as clutter once
 * the page had been used a few times. The POLICY it explained is unchanged:
 * there is still no delete action anywhere on this page, and Cancel (while an
 * order is still open, elsewhere in the app) remains the only correction
 * path. Only the explanatory text goes; nothing about what the page actually
 * does should move. The filter row above it (Date From / Date To / Type /
 * Status / Apply Filters / Reset) is untouched and still renders exactly
 * where it did.
 */
class CompletedOrdersReminderRemovedTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    public function test_the_reminder_paragraph_is_gone(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/completed-orders')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('kept on purpose', $html);
        $this->assertStringNotContainsString('there is no delete button here by design', $html);
    }

    public function test_the_filter_row_is_still_present_and_unchanged(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/completed-orders')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Apply Filters', $html);
        $this->assertStringContainsString('name="date_from"', $html);
        $this->assertStringContainsString('name="date_to"', $html);
        $this->assertStringContainsString('name="type"', $html);
        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString(route('admin.completed-orders'), $html);
    }

    public function test_there_is_still_genuinely_no_delete_action_on_this_page(): void
    {
        $admin = $this->admin();

        $order = Order::create([
            'order_number'   => 'CORT-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'completed',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal'       => 100,
            'total'          => 100,
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->get('/admin/completed-orders')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringNotContainsString('/admin/completed-orders/' . $order->id, $html);
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('admin.completed-orders.delete'),
            'a delete route for completed orders now exists — the policy was not supposed to change'
        );
    }

    public function test_cancel_remains_the_only_correction_path_and_is_unaffected(): void
    {
        $order = Order::create([
            'order_number'   => 'CORT-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/orders/' . $order->id . '/cancel')
            ->assertRedirect();

        $order->refresh();

        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->cancelled_at);
    }
}
