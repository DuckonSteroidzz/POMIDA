<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The customer notification tray: per-card dismiss + age-based auto-expiry.
 *
 * The admin bell already had both (see AdminNotificationDismissAndExpiryTest,
 * commit f4b9393) — Notification::scopeInTray(), AUTO_EXPIRY_DAYS,
 * ACTIONABLE_TYPES, and a dismiss endpoint all already existed on the SAME
 * model/table both audiences share. The customer dropdown had no X per item
 * (only "Mark all read"), and its index()/unreadCount() endpoints were not
 * even scoped through inTray() yet — a dismissed row would have reappeared on
 * the very next poll. This pins both fixes:
 *
 *   (a) Per-notification dismiss — an X on each card stamps dismissed_at
 *       (CustomerNotificationController::dismiss(), scoped through
 *       Notification::scopeVisibleToCurrentCustomer() exactly like every
 *       other read here) and scopeInTray() drops the row from the feed.
 *
 *   (b) Auto-expiry — index()/unreadCount() now apply inTray(), so an
 *       INFORMATIONAL row older than AUTO_EXPIRY_DAYS drops out on its own,
 *       while an ACTIONABLE_TYPES row never does by age — only a manual
 *       dismiss removes it. In today's code both actionable types
 *       (gcash_awaiting_verification, refund_pending) are only ever created
 *       staff-audience (Notification::gcashAwaitingVerification() /
 *       refundPending() both call forStaff()), so this combination does not
 *       occur in production yet — the test below proves the SCOPE itself
 *       honours the rule regardless of audience, matching admin's behaviour
 *       exactly, so nothing here would need to change if that ever does.
 *
 * Authorization mirrors the admin suite's "wrong scope is a 404" case, plus
 * the customer-specific one admin doesn't have: a SECOND customer's own row.
 *
 * Runs inside DatabaseTransactions so every row here rolls back. As a second
 * guard against the live DB this suite shares, tearDown() also deletes only
 * rows above the pre-test high-water mark that carry this suite's own title
 * prefix — never an id-only bound, never a pre-existing row.
 */
class CustomerNotificationDismissAndExpiryTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = '[PCX-CUST-DISMISS-TEST] ';

    private int $highWaterMark = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->highWaterMark = (int) (Notification::max('id') ?? 0);
    }

    protected function tearDown(): void
    {
        Notification::where('id', '>', $this->highWaterMark)
            ->where('title', 'like', self::PREFIX . '%')
            ->forceDelete();

        parent::tearDown();
    }

    private function customer(?int $excludeId = null): User
    {
        return User::where('role', 'customer')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('id')
            ->firstOrFail();
    }

    /** A customer-audience notification for a logged-in user. */
    private function makeNotification(int $userId, array $attrs = []): Notification
    {
        return Notification::create(array_merge([
            'user_id'   => $userId,
            'order_id'  => null,
            'branch_id' => null,
            'audience'  => Notification::AUDIENCE_CUSTOMER,
            'type'      => 'order_status_changed',
            'title'     => self::PREFIX . 'Order update',
            'message'   => 'Order #TEST changed status.',
        ], $attrs));
    }

    /** A guest order this test session owns, for the guest-path cases. */
    private function guestOrder(): Order
    {
        $order = Order::create([
            'order_number'   => 'CNX-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);

        GuestOrders::remember($order->id);

        return $order;
    }

    private function backdate(Notification $n, int $days): void
    {
        // created_at is not fillable; set it straight in the row.
        DB::table('notifications')->where('id', $n->id)->update([
            'created_at' => now()->subDays($days),
        ]);
    }

    /** @return array<int> the notification ids currently in the CALLER's tray feed */
    private function trayIds(): array
    {
        $res = $this->getJson(route('customer.notifications.index'));

        $res->assertOk();

        return collect($res->json('notifications'))->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    // ══════════ Dismiss: a customer's own notification ══════════

    public function test_dismissing_a_notification_removes_it_from_the_tray(): void
    {
        $customer = $this->customer();
        $n = $this->makeNotification($customer->id);

        $this->actingAs($customer, 'customer');

        $this->assertContains($n->id, $this->trayIds(), 'sanity: the row should start in the tray');

        $this->postJson(route('customer.notifications.dismiss', ['notification' => $n->id]))
            ->assertOk()
            ->assertJson(['dismissed' => true]);

        $this->assertNotNull($n->fresh()->dismissed_at);
        $this->assertNotContains($n->id, $this->trayIds(), 'a dismissed row must leave the tray feed');
    }

    public function test_a_dismissed_notification_does_not_reappear(): void
    {
        $customer = $this->customer();
        $n = $this->makeNotification($customer->id);

        $this->actingAs($customer, 'customer');

        $this->postJson(route('customer.notifications.dismiss', ['notification' => $n->id]))
            ->assertOk();

        // Poll the feed a few more times — it must stay gone.
        $this->assertNotContains($n->id, $this->trayIds());
        $this->assertNotContains($n->id, $this->trayIds());

        // And the unread-count feed excludes it too.
        $count = $this->getJson(route('customer.notifications.unread-count'));
        $latestId = (int) $count->json('latest_id');
        $this->assertNotSame($n->id, $latestId, 'a dismissed row must not be the tray high-water mark');
    }

    public function test_a_guest_can_dismiss_their_own_notification(): void
    {
        $order = $this->guestOrder();
        $n = Notification::create([
            'user_id'   => null,
            'order_id'  => $order->id,
            'branch_id' => null,
            'audience'  => Notification::AUDIENCE_CUSTOMER,
            'type'      => 'order_status_changed',
            'title'     => self::PREFIX . 'Guest order update',
            'message'   => 'Order #TEST changed status.',
        ]);

        $this->assertContains($n->id, $this->trayIds(), 'sanity: the guest row should start in the tray');

        $this->postJson(route('customer.notifications.dismiss', ['notification' => $n->id]))
            ->assertOk()
            ->assertJson(['dismissed' => true]);

        $this->assertNotNull($n->fresh()->dismissed_at);
        $this->assertNotContains($n->id, $this->trayIds());
    }

    // ══════════ Dismiss: refusals — an id alone must never be enough ══════════

    public function test_dismiss_rejects_another_customers_notification(): void
    {
        $owner = $this->customer();
        $other = $this->customer(excludeId: $owner->id);

        $n = $this->makeNotification($owner->id, ['title' => self::PREFIX . 'owned by someone else']);

        $this->actingAs($other, 'customer');

        $this->postJson(route('customer.notifications.dismiss', ['notification' => $n->id]))
            ->assertNotFound();

        $this->assertNull($n->fresh()->dismissed_at, 'another customer must never be able to dismiss this row');
    }

    public function test_dismiss_rejects_a_staff_notification(): void
    {
        $staffRow = Notification::create([
            'user_id'   => null,
            'order_id'  => null,
            'branch_id' => 1,
            'audience'  => Notification::AUDIENCE_STAFF,
            'type'      => 'new_order',
            'title'     => self::PREFIX . 'not a customer row',
            'message'   => 'Order #TEST came in.',
        ]);

        $this->actingAs($this->customer(), 'customer');

        $this->postJson(route('customer.notifications.dismiss', ['notification' => $staffRow->id]))
            ->assertNotFound();

        $this->assertNull($staffRow->fresh()->dismissed_at, 'a customer must never be able to dismiss a staff row');
    }

    public function test_a_guest_cannot_dismiss_another_sessions_notification(): void
    {
        $order = $this->guestOrder();
        $n = Notification::create([
            'user_id'   => null,
            'order_id'  => $order->id,
            'branch_id' => null,
            'audience'  => Notification::AUDIENCE_CUSTOMER,
            'type'      => 'order_status_changed',
            'title'     => self::PREFIX . 'not this browsers order',
            'message'   => 'Order #TEST changed status.',
        ]);

        // A different browser: no claim on this guest order.
        $this->flushSession();

        $this->postJson(route('customer.notifications.dismiss', ['notification' => $n->id]))
            ->assertNotFound();

        $this->assertNull($n->fresh()->dismissed_at);
    }

    // ══════════ Auto-expiry ══════════

    public function test_auto_expiry_hides_an_old_informational_notification(): void
    {
        $customer = $this->customer();
        $fresh = $this->makeNotification($customer->id, ['title' => self::PREFIX . 'fresh update']);
        $stale = $this->makeNotification($customer->id, ['title' => self::PREFIX . 'stale update']);
        $this->backdate($stale, Notification::AUTO_EXPIRY_DAYS + 1);

        $this->actingAs($customer, 'customer');
        $ids = $this->trayIds();

        $this->assertContains($fresh->id, $ids, 'a recent informational row still shows');
        $this->assertNotContains(
            $stale->id,
            $ids,
            'an informational row past AUTO_EXPIRY_DAYS must be auto-expired from the tray'
        );
    }

    /**
     * Both ACTIONABLE_TYPES are staff-only in today's factories — see the
     * class docblock. This proves scopeInTray() itself is audience-agnostic:
     * a customer-audience row of one of these types is exempted from
     * auto-expiry exactly like the admin suite already pins for staff rows,
     * so nothing here would silently regress if a customer-facing actionable
     * type were ever added.
     */
    public function test_auto_expiry_does_not_hide_an_unresolved_actionable_notification(): void
    {
        $customer = $this->customer();
        $gcash = $this->makeNotification($customer->id, [
            'type'  => 'gcash_awaiting_verification',
            'title' => self::PREFIX . 'GCash payment needs verifying',
        ]);
        $refund = $this->makeNotification($customer->id, [
            'type'  => 'refund_pending',
            'title' => self::PREFIX . 'Refund needed',
        ]);

        // Far older than any informational row would survive.
        $this->backdate($gcash, 90);
        $this->backdate($refund, 400);

        $this->actingAs($customer, 'customer');
        $ids = $this->trayIds();

        $this->assertContains(
            $gcash->id,
            $ids,
            'an unresolved gcash_awaiting_verification row must NOT auto-expire, whatever its age'
        );
        $this->assertContains(
            $refund->id,
            $ids,
            'an unresolved refund_pending row must NOT auto-expire, whatever its age'
        );

        // The only way an actionable row leaves the tray is a manual dismiss.
        $this->postJson(route('customer.notifications.dismiss', ['notification' => $gcash->id]))
            ->assertOk();

        $this->assertNotContains($gcash->id, $this->trayIds());
        $this->assertContains($refund->id, $this->trayIds());
    }

    // ══════════ Markup ══════════

    public function test_the_tray_markup_carries_a_per_card_dismiss_x(): void
    {
        $src = file_get_contents(
            resource_path('views/customer/partials/notification-bell.blade.php')
        );

        $this->assertStringContainsString('data-notif-dismiss', $src);
        // Reuses the existing borderless/transparent toast-close style —
        // no new class or colour introduced for this button.
        $this->assertMatchesRegularExpression(
            '/\.toast-x\s*\{[^}]*border:\s*0[^}]*background:\s*transparent/s',
            $src,
            'the per-card dismiss button must reuse the existing toast-x style'
        );
        $dismissPos = strpos($src, 'data-notif-dismiss');
        $this->assertStringContainsString('bi bi-x-lg', substr($src, $dismissPos, 200));
    }
}
