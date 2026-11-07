<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Support\ShopDay;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Refunds you can find, view and follow (QA, 5 Nov 2026).
 *
 * QA: "I can't see the refund." "Is it upon cancel?" "We can't open it again."
 * Reproduced: the only refund screen was the board's Refunds Owed band, shown
 * only while one was owed, and Mark Refunded made the row vanish with no date,
 * no name and nothing to open. The customer was never told either way.
 *
 * Pinned here:
 *   1. WHEN a refund exists — unchanged: a GCash order cancelled (by the
 *      customer or by staff) after "I Have Paid". Not unpaid GCash, not cash.
 *   2. The Refunds page lists owed AND refunded, with every column, filters,
 *      and the same branch scope as the rest of the portal.
 *   3. View opens a refund before and after it is refunded.
 *   4. Mark Refunded records who/when, keeps the row (now Refunded), refuses a
 *      second press, and notifies the customer once.
 *   5. The customer sees "Refund owed" then "Refunded on [date]".
 *   6. Permissions are unchanged: every portal role, own branch only.
 *
 * Every row is created here, inside DatabaseTransactions, on branches this
 * file makes — nothing depends on (or survives in) pomida_db_testing.
 */
class RefundsVisibleTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'RFVIS';

    private Branch $home;
    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = $this->branch('Home');
        $this->far = $this->branch('Far');
    }

    // ══════════════════ fixtures ══════════════════

    private function branch(string $label): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' ' . $label . ' ' . uniqid(),
            'code'      => 'RF' . strtoupper(substr(uniqid(), -7)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function user(string $role, ?int $branchId = null, array $extra = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'name'              => self::PREFIX . ' ' . ucfirst($role) . ' ' . substr(uniqid(), -5),
            'email'             => strtolower(self::PREFIX) . '-' . $role . '-' . uniqid() . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $branchId,
            'is_active'         => true,
            'email_verified_at' => now(),
            'verified_at'       => now(),
        ], $extra))->save();

        return $user;
    }

    private function customer(): User
    {
        return $this->user('customer', null, ['contact_number' => '09171112222']);
    }

    private function staff(?Branch $branch = null): User
    {
        return $this->user('staff', ($branch ?? $this->home)->id);
    }

    private function order(User $customer, ?Branch $branch = null, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'RFV-' . strtoupper(substr(uniqid(), -8)),
            'user_id'        => $customer->id,
            'branch_id'      => ($branch ?? $this->home)->id,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'gcash',
            'payment_status' => 'awaiting_verification',
            'subtotal'       => 250,
            'total'          => 250,
        ], $attrs));
    }

    /** A refund already owed, as either cancel path leaves it. */
    private function owed(User $customer, ?Branch $branch = null, string $by = 'customer', array $attrs = []): Order
    {
        return $this->order($customer, $branch, array_merge([
            'status'              => 'cancelled',
            'payment_status'      => 'refund_pending',
            'cancelled_at'        => now(),
            'cancellation_reason' => $by === 'staff'
                ? 'Cancelled by staff after the customer marked it paid via GCash. Refund must be sent manually.'
                : 'Cancelled by the customer after they marked it paid via GCash. Refund must be sent manually.',
        ], $attrs));
    }

    private function refundsPage(User $viewer, array $query = [], array $session = []): string
    {
        return $this->actingAs($viewer, 'admin')
            ->withSession($session)
            ->get(route('admin.refunds', $query))
            ->assertOk()
            ->getContent();
    }

    /** The <tr> for one order on the Refunds page, or '' when not listed. */
    private function row(string $html, Order $order): string
    {
        return preg_match('/<tr[^>]*data-refund-row="' . $order->id . '"[^>]*>.*?<\/tr>/s', $html, $m) ? $m[0] : '';
    }

    // ══════════ 1. when a refund is created (rule unchanged) ══════════

    public function test_customer_cancel_of_a_paid_gcash_order_creates_a_refund_listed_as_owed(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.orders.cancel', $order->id))
            ->assertRedirect(route('customer.orders'));

        $order->refresh();
        $this->assertSame('refund_pending', $order->payment_status);
        $this->assertSame('Customer', $order->cancelledByLabel());

        $row = $this->row($this->refundsPage($this->staff()), $order);
        $this->assertNotSame('', $row, 'a customer-cancelled paid GCash order must be on the Refunds page');
        $this->assertStringContainsString('Refund owed', $row);
        $this->assertStringContainsString('Customer', $row);
    }

    public function test_staff_cancel_of_a_paid_gcash_order_creates_a_refund_listed_as_owed(): void
    {
        $order = $this->order($this->customer());
        $staff = $this->staff();

        $this->actingAs($staff, 'admin')
            ->put(route('admin.orders.cancel', $order->id))
            ->assertRedirect(route('admin.home'));

        $order->refresh();
        $this->assertSame('refund_pending', $order->payment_status);
        $this->assertSame('Staff', $order->cancelledByLabel());

        $row = $this->row($this->refundsPage($staff), $order);
        $this->assertStringContainsString('Refund owed', $row);
        $this->assertStringContainsString('Staff', $row);
    }

    public function test_unpaid_gcash_and_cash_cancellations_create_no_refund(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();

        $unpaidByCustomer = $this->order($customer, null, ['payment_status' => 'pending']);
        $cashByCustomer = $this->order($customer, null, ['payment_method' => 'cash', 'payment_status' => 'pending']);
        $unpaidByStaff = $this->order($customer, null, ['payment_status' => 'pending']);
        $cashByStaff = $this->order($customer, null, ['payment_method' => 'cash', 'payment_status' => 'pending']);

        $this->actingAs($customer, 'customer');
        $this->post(route('customer.orders.cancel', $unpaidByCustomer->id));
        $this->post(route('customer.orders.cancel', $cashByCustomer->id));

        $this->actingAs($staff, 'admin');
        $this->put(route('admin.orders.cancel', $unpaidByStaff->id));
        $this->put(route('admin.orders.cancel', $cashByStaff->id));

        $html = $this->refundsPage($staff);

        foreach ([$unpaidByCustomer, $cashByCustomer, $unpaidByStaff, $cashByStaff] as $order) {
            $order->refresh();
            $this->assertSame('cancelled', $order->status);
            $this->assertFalse($order->isRefund(), $order->order_number . ' must not owe a refund');
            $this->assertSame('', $this->row($html, $order), $order->order_number . ' must not be on the Refunds page');
        }
    }

    // ══════════ 1b. approved / counter GCash also owes (QA, 5 Nov 2026) ══════════

    /** An online GCash order staff already APPROVED: still pending, money in. */
    private function approved(User $customer): Order
    {
        $order = $this->order($customer);
        $this->actingAs($this->staff(), 'admin')
            ->put(route('admin.orders.payment.approve', $order->id));
        auth('admin')->logout();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status, 'setup: staff approval marks it paid');
        $this->assertSame('pending', $order->status, 'setup: approval leaves the order cancellable');

        return $order;
    }

    public function test_customer_cancel_after_staff_approved_the_gcash_payment_owes_a_refund(): void
    {
        $customer = $this->customer();
        $order = $this->approved($customer);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.orders.cancel', $order->id))
            ->assertRedirect(route('customer.orders'));

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('refund_pending', $order->payment_status, 'approved GCash money must be owed back');
        $this->assertSame('Customer', $order->cancelledByLabel());
        $this->assertSame('250.00', (string) $order->total);
        $this->assertSame(1, Notification::where('order_id', $order->id)->where('type', 'refund_pending')->count());

        $this->assertNotSame('', $this->row($this->refundsPage($this->staff(), ['status' => 'owed']), $order));
    }

    public function test_staff_cancel_after_approving_the_gcash_payment_owes_a_refund(): void
    {
        $order = $this->approved($this->customer());

        // Even once the kitchen has started.
        $staff = $this->staff();
        $this->actingAs($staff, 'admin')->put(route('admin.orders.prepare', $order->id));
        $this->assertSame('preparing', $order->fresh()->status);

        $this->put(route('admin.orders.cancel', $order->id))->assertRedirect(route('admin.home'));

        $order->refresh();
        $this->assertSame('refund_pending', $order->payment_status);
        $this->assertSame('Staff', $order->cancelledByLabel());
        $this->assertNotSame('', $this->row($this->refundsPage($staff), $order));
    }

    public function test_staff_cancel_of_a_gcash_counter_order_owes_a_refund_and_can_be_settled(): void
    {
        $staff = $this->staff();
        // A counter order exactly as storeManualOrder() writes it: no
        // customer account, keyed in by staff, GCash taken on the spot.
        $order = Order::create([
            'order_number'   => 'RFV-' . strtoupper(substr(uniqid(), -8)),
            'user_id'        => null,
            'processed_by'   => $staff->id,
            'branch_id'      => $this->home->id,
            'type'           => 'walk_in',
            'status'         => 'pending',
            'payment_method' => 'gcash',
            'payment_status' => 'paid',
            'amount_paid'    => 250,
            'subtotal'       => 250,
            'total'          => 250,
        ]);

        $this->actingAs($staff, 'admin')->put(route('admin.orders.cancel', $order->id));

        $order->refresh();
        $this->assertSame('refund_pending', $order->payment_status);
        $this->assertSame('Staff', $order->cancelledByLabel());

        $row = $this->row($this->refundsPage($staff), $order);
        $this->assertStringContainsString('Walk-in customer', $row);
        $this->assertStringContainsString('Refund owed', $row);

        $this->get(route('admin.refunds.show', $order->id))->assertOk()->assertSee('Paid by GCash at the counter');

        $this->put(route('admin.orders.payment.refunded', $order->id));
        $order->refresh();
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame((int) $staff->id, (int) $order->refunded_by);
        // No account and no session points at a counter order: nobody to notify.
        $this->assertSame(0, Notification::where('order_id', $order->id)->where('type', 'refund_sent')->count());
    }

    public function test_cash_orders_never_owe_a_refund_paid_or_not(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();

        $paidCashCounter = $this->order($customer, null, ['user_id' => null, 'processed_by' => $staff->id, 'type' => 'walk_in', 'payment_method' => 'cash', 'payment_status' => 'paid']);
        $paidCashOnline = $this->order($customer, null, ['payment_method' => 'cash', 'payment_status' => 'paid']);
        $unpaidCash = $this->order($customer, null, ['payment_method' => 'cash', 'payment_status' => 'pending']);

        $this->actingAs($staff, 'admin');
        $this->put(route('admin.orders.cancel', $paidCashCounter->id));
        $this->put(route('admin.orders.cancel', $paidCashOnline->id));
        $this->actingAs($customer, 'customer');
        $this->post(route('customer.orders.cancel', $unpaidCash->id));

        foreach ([$paidCashCounter, $paidCashOnline] as $order) {
            $order->refresh();
            $this->assertSame('cancelled', $order->status);
            $this->assertSame('paid', $order->payment_status, 'a cash cancellation keeps its payment state');
        }
        $this->assertSame('pending', $unpaidCash->fresh()->payment_status);
        $this->assertSame(0, Order::whereKey([$paidCashCounter->id, $paidCashOnline->id, $unpaidCash->id])->refunds()->count());
    }

    public function test_the_customer_is_warned_before_cancelling_an_approved_gcash_order(): void
    {
        $customer = $this->customer();
        $this->approved($customer);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.orders'))
            ->assertOk()
            ->assertSee('You paid by GCash — if you cancel, staff will send your refund.');
    }

    // ══════════ 2. the Refunds page ══════════

    public function test_the_refunds_page_lists_owed_and_refunded_with_every_column(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();
        $owed = $this->owed($customer);
        $done = $this->owed($customer, null, 'staff', [
            'payment_status' => 'refunded',
            'cancelled_at'   => now()->subDay(),
        ]);
        $done->forceFill(['refunded_at' => now(), 'refunded_by' => $staff->id])->save();

        $html = $this->refundsPage($staff);

        foreach (['Order #', 'Customer', 'Branch', 'Amount', 'GCash / phone', 'Cancelled on', 'Cancelled by', 'Status', 'Refunded on', 'Refunded by'] as $heading) {
            $this->assertStringContainsString('<th' . ($heading === 'Amount' ? ' class="rf-right"' : '') . '>' . $heading . '</th>', $html, "missing column: $heading");
        }

        $owedRow = $this->row($html, $owed);
        foreach ([$owed->order_number, $customer->name, $this->home->name, '₱250.00', '09171112222', 'Customer', 'Refund owed', 'Mark Refunded', 'View'] as $text) {
            $this->assertStringContainsString($text, $owedRow, "owed row missing: $text");
        }

        $doneRow = $this->row($html, $done);
        foreach ([$done->order_number, 'Staff', 'Refunded', $staff->name, ShopDay::format($done->refunded_at, 'M d, Y'), 'View'] as $text) {
            $this->assertStringContainsString($text, $doneRow, "refunded row missing: $text");
        }
        $this->assertStringNotContainsString('Mark Refunded', $doneRow, 'a refunded row must be read-only');

        // Owed first: the work comes before the history.
        $this->assertLessThan(strpos($html, $done->order_number), strpos($html, $owed->order_number));
    }

    public function test_status_date_and_search_filters(): void
    {
        $customer = $this->customer();
        $other = $this->user('customer', null, ['name' => self::PREFIX . ' Zelda Unique', 'contact_number' => '09998887777']);
        $staff = $this->staff();

        $owedToday = $this->owed($customer);
        $owedOld = $this->owed($other, null, 'customer', ['cancelled_at' => now()->subDays(10)]);
        $refunded = $this->owed($customer, null, 'staff', ['payment_status' => 'refunded']);

        $owedOnly = $this->refundsPage($staff, ['status' => 'owed']);
        $this->assertNotSame('', $this->row($owedOnly, $owedToday));
        $this->assertNotSame('', $this->row($owedOnly, $owedOld));
        $this->assertSame('', $this->row($owedOnly, $refunded));

        $refundedOnly = $this->refundsPage($staff, ['status' => 'refunded']);
        $this->assertSame('', $this->row($refundedOnly, $owedToday));
        $this->assertNotSame('', $this->row($refundedOnly, $refunded));

        $today = ShopDay::todayString();
        $dated = $this->refundsPage($staff, ['from' => $today, 'to' => $today]);
        $this->assertNotSame('', $this->row($dated, $owedToday));
        $this->assertSame('', $this->row($dated, $owedOld), 'cancelled 10 days ago is outside today');

        foreach ([$owedOld->order_number, 'Zelda Unique', '0999888'] as $term) {
            $found = $this->refundsPage($staff, ['q' => $term]);
            $this->assertNotSame('', $this->row($found, $owedOld), "search \"$term\" should find it");
            $this->assertSame('', $this->row($found, $owedToday), "search \"$term\" should not match the other customer");
        }

        // Garbage never errors — it just falls back to no filter.
        $this->refundsPage($staff, ['status' => 'nope', 'from' => '2026-02-31', 'to' => 'x']);
    }

    public function test_branch_scope_follows_the_lock_and_the_viewing_bar(): void
    {
        $customer = $this->customer();
        $here = $this->owed($customer, $this->home);
        $there = $this->owed($customer, $this->far);

        // Staff and supervisor: their own branch, whatever the session says.
        foreach ([$this->staff(), $this->user('supervisor', $this->home->id)] as $locked) {
            $html = $this->refundsPage($locked, [], ['selected_branch_id' => $this->far->id]);
            $this->assertNotSame('', $this->row($html, $here));
            $this->assertSame('', $this->row($html, $there), $locked->role . ' must not see another branch\'s refund');
        }

        // Owner: the Viewing bar.
        $owner = $this->user('admin');
        $all = $this->refundsPage($owner, [], ['selected_branch_id' => 'all']);
        $this->assertNotSame('', $this->row($all, $here));
        $this->assertNotSame('', $this->row($all, $there));

        $farOnly = $this->refundsPage($owner, [], ['selected_branch_id' => $this->far->id]);
        $this->assertSame('', $this->row($farOnly, $here));
        $this->assertNotSame('', $this->row($farOnly, $there));
    }

    // ══════════ 3. View, before and after ══════════

    public function test_view_opens_a_refund_before_and_after_it_is_refunded(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();
        $order = $this->owed($customer);

        $before = $this->actingAs($staff, 'admin')->get(route('admin.refunds.show', $order->id))->assertOk();
        $before->assertSee($order->order_number)
            ->assertSee('Refund owed')
            ->assertSee('09171112222')
            ->assertSee('Mark Refunded');

        $this->put(route('admin.orders.payment.refunded', $order->id), ['from' => 'refunds']);

        $after = $this->get(route('admin.refunds.show', $order->id))->assertOk();
        $after->assertSee($order->order_number)
            ->assertSee('Refunded')
            ->assertSee($staff->name)
            ->assertDontSee('data-testid="refund-mark"', false);
    }

    public function test_view_is_scoped_and_only_for_refunds(): void
    {
        $customer = $this->customer();

        $foreign = $this->owed($customer, $this->far);
        $this->actingAs($this->staff(), 'admin')
            ->get(route('admin.refunds.show', $foreign->id))
            ->assertNotFound();

        $notARefund = $this->order($customer, null, ['payment_status' => 'paid', 'status' => 'completed']);
        $this->get(route('admin.refunds.show', $notARefund->id))->assertNotFound();
    }

    // ══════════ 4. Mark Refunded ══════════

    public function test_mark_refunded_records_who_and_when_and_the_row_stays_as_refunded(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();
        $order = $this->owed($customer);

        $this->actingAs($staff, 'admin')
            ->from(route('admin.refunds'))
            ->put(route('admin.orders.payment.refunded', $order->id), ['from' => 'refunds'])
            ->assertRedirect(route('admin.refunds'));

        $order->refresh();
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame('cancelled', $order->status, 'refunding must not revive the order');
        $this->assertSame((int) $staff->id, (int) $order->refunded_by);
        $this->assertNotNull($order->refunded_at);
        $this->assertTrue($order->refunded_at->diffInSeconds(now(), true) < 60);
        $this->assertSame('250.00', (string) $order->total, 'the amount is never touched');

        $row = $this->row($this->refundsPage($staff), $order);
        $this->assertNotSame('', $row, 'the row must move to Refunded, not disappear');
        $this->assertStringContainsString('Refunded', $row);
        $this->assertStringContainsString($staff->name, $row);

        // From the board it still goes back to the board, as before.
        $second = $this->owed($customer);
        $this->put(route('admin.orders.payment.refunded', $second->id))
            ->assertRedirect(route('admin.home'));
    }

    public function test_a_second_press_is_refused_and_keeps_the_first_record(): void
    {
        $customer = $this->customer();
        $first = $this->staff();
        $order = $this->owed($customer);

        $this->actingAs($first, 'admin')->put(route('admin.orders.payment.refunded', $order->id));
        $stamp = $order->fresh()->refunded_at;

        $this->actingAs($this->staff(), 'admin')
            ->put(route('admin.orders.payment.refunded', $order->id))
            ->assertSessionHasErrors('payment');

        $order->refresh();
        $this->assertSame((int) $first->id, (int) $order->refunded_by, 'a second press must not overwrite who refunded it');
        $this->assertEquals($stamp, $order->refunded_at);
        $this->assertSame(1, Notification::where('order_id', $order->id)->where('type', 'refund_sent')->count(), 'the customer is told once');
    }

    // ══════════ 5. what the customer sees ══════════

    public function test_customer_sees_refund_owed_then_refunded_and_gets_a_notification(): void
    {
        $customer = $this->customer();
        $order = $this->owed($customer);

        $owedText = 'Refund owed: the cafe will send ₱250.00 back to your GCash.';

        $this->actingAs($customer, 'customer')
            ->get(route('customer.orders', ['tab' => 'history']))
            ->assertOk()
            ->assertSee($owedText);
        $this->get(route('customer.receipt', $order->id))->assertOk()->assertSee($owedText);

        $this->actingAs($this->staff(), 'admin')->put(route('admin.orders.payment.refunded', $order->id));
        $order->refresh();

        $refundedText = 'Refunded on ' . ShopDay::format($order->refunded_at, 'M j, Y');

        $this->actingAs($customer, 'customer')
            ->get(route('customer.orders', ['tab' => 'history']))
            ->assertSee($refundedText)
            ->assertDontSee($owedText);
        $this->get(route('customer.receipt', $order->id))->assertSee($refundedText);

        $note = Notification::where('order_id', $order->id)->where('type', 'refund_sent')->sole();
        $this->assertSame('customer', $note->audience);
        $this->assertSame((int) $customer->id, (int) $note->user_id);
        $this->assertStringContainsString($refundedText, $note->message);

        $this->getJson(route('customer.notifications.index'))
            ->assertOk()
            ->assertJsonFragment(['type' => 'refund_sent', 'title' => 'Refund sent']);
    }

    public function test_a_non_refund_order_shows_no_refund_line(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, null, ['status' => 'cancelled', 'payment_status' => 'pending', 'cancelled_at' => now()]);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.receipt', $order->id))
            ->assertOk()
            ->assertDontSee('data-testid="receipt-refund-status"', false)
            ->assertSee('Freshly baked, all settled.');
    }

    // ══════════ 6. board, bell and permissions ══════════

    public function test_the_board_band_always_shows_with_a_link_to_all_refunds(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'admin')->get(route('admin.home'))->assertOk()
            ->assertSee('Refunds Owed')
            ->assertSee('No refunds owed')
            ->assertSee('View all refunds');

        $order = $this->owed($this->customer());

        $this->get(route('admin.home'))->assertOk()
            ->assertSee($order->order_number)
            ->assertSee(route('admin.refunds.show', $order->id))
            ->assertDontSee('No refunds owed');
    }

    public function test_the_bell_row_for_a_refund_opens_it(): void
    {
        $staff = $this->staff();
        $order = $this->owed($this->customer());
        Notification::refundPending($order);

        $this->actingAs($staff, 'admin')
            ->getJson(route('admin.notifications.index'))
            ->assertOk()
            ->assertJsonFragment(['type' => 'refund_pending', 'url' => route('admin.refunds.show', $order->id)]);
    }

    public function test_every_portal_role_can_open_refunds_and_customers_and_guests_cannot(): void
    {
        foreach (['staff' => $this->home->id, 'supervisor' => $this->home->id, 'admin' => null] as $role => $branchId) {
            $this->actingAs($this->user($role, $branchId), 'admin')
                ->get(route('admin.refunds'))
                ->assertOk();
            auth('admin')->logout();
        }

        $order = $this->owed($this->customer());

        $this->actingAs($this->customer(), 'customer')
            ->get(route('admin.refunds'))
            ->assertRedirect();
        $this->get(route('admin.refunds.show', $order->id))->assertRedirect();
        $this->put(route('admin.orders.payment.refunded', $order->id));
        $this->assertSame('refund_pending', $order->fresh()->payment_status, 'a customer cannot mark a refund sent');
    }
}
