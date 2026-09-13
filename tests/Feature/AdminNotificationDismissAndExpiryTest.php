<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin notification tray: per-card dismiss + age-based auto-expiry.
 *
 * "Mark all read" only ever set read_at, so the tray grew without bound and
 * kept showing days-old rows. Two changes are pinned here:
 *
 *   (a) Per-notification dismiss — an X on each card stamps dismissed_at
 *       (Notification::dismiss endpoint) and Notification::scopeInTray() drops
 *       the row from the feed for good.
 *
 *   (b) Auto-expiry — scopeInTray() also excludes INFORMATIONAL rows older than
 *       Notification::AUTO_EXPIRY_DAYS. Rows whose type is in
 *       Notification::ACTIONABLE_TYPES (gcash_awaiting_verification,
 *       refund_pending) are NEVER auto-expired by age, because that would hide
 *       unfinished staff work — they only leave the tray on a manual dismiss.
 *
 * Runs inside DatabaseTransactions so every row here rolls back. As a second
 * guard against the live DB this suite shares, tearDown() also deletes only
 * rows above the pre-test high-water mark that carry this suite's own title
 * prefix — never an id-only bound, never a pre-existing row.
 */
class AdminNotificationDismissAndExpiryTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = '[PCX-DISMISS-TEST] ';

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

    private function admin(): User
    {
        return User::where('role', 'admin')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    /**
     * A staff-audience notification for branch 1 (an admin's default 'all'
     * scope sees every branch, so branch choice does not matter here).
     */
    private function makeNotification(array $attrs = []): Notification
    {
        return Notification::create(array_merge([
            'user_id'   => null,
            'order_id'  => null,
            'branch_id' => 1,
            'audience'  => Notification::AUDIENCE_STAFF,
            'type'      => 'new_order',
            'title'     => self::PREFIX . 'New online order',
            'message'   => 'Order #TEST came in.',
        ], $attrs));
    }

    private function backdate(Notification $n, int $days): void
    {
        // created_at is not fillable; set it straight in the row.
        DB::table('notifications')->where('id', $n->id)->update([
            'created_at' => now()->subDays($days),
        ]);
    }

    /** @return array<int> the notification ids currently in the admin tray feed */
    private function trayIds(): array
    {
        $res = $this->actingAs($this->admin(), 'admin')
            ->getJson(route('admin.notifications.index'));

        $res->assertOk();

        return collect($res->json('notifications'))->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_dismissing_a_notification_removes_it_from_the_tray(): void
    {
        $n = $this->makeNotification();

        $this->assertContains($n->id, $this->trayIds(), 'sanity: the row should start in the tray');

        $this->actingAs($this->admin(), 'admin')
            ->postJson(route('admin.notifications.dismiss', ['notification' => $n->id]))
            ->assertOk()
            ->assertJson(['dismissed' => true]);

        $this->assertNotNull($n->fresh()->dismissed_at);
        $this->assertNotContains($n->id, $this->trayIds(), 'a dismissed row must leave the tray feed');
    }

    public function test_a_dismissed_notification_does_not_reappear(): void
    {
        $n = $this->makeNotification();

        $this->actingAs($this->admin(), 'admin')
            ->postJson(route('admin.notifications.dismiss', ['notification' => $n->id]))
            ->assertOk();

        // Poll the feed a few more times — it must stay gone.
        $this->assertNotContains($n->id, $this->trayIds());
        $this->assertNotContains($n->id, $this->trayIds());

        // And the unread-count feed excludes it too.
        $count = $this->actingAs($this->admin(), 'admin')
            ->getJson(route('admin.notifications.unread-count'));
        $latestId = (int) $count->json('latest_id');
        $this->assertNotSame($n->id, $latestId, 'a dismissed row must not be the tray high-water mark');
    }

    public function test_auto_expiry_hides_an_old_informational_notification(): void
    {
        $fresh = $this->makeNotification(['title' => self::PREFIX . 'fresh new order']);
        $stale = $this->makeNotification(['title' => self::PREFIX . 'stale new order']);
        $this->backdate($stale, Notification::AUTO_EXPIRY_DAYS + 1);

        $ids = $this->trayIds();

        $this->assertContains($fresh->id, $ids, 'a recent informational row still shows');
        $this->assertNotContains(
            $stale->id,
            $ids,
            'an informational row past AUTO_EXPIRY_DAYS must be auto-expired from the tray'
        );
    }

    public function test_auto_expiry_does_not_hide_an_unresolved_actionable_notification(): void
    {
        $gcash = $this->makeNotification([
            'type'  => 'gcash_awaiting_verification',
            'title' => self::PREFIX . 'GCash payment needs verifying',
        ]);
        $refund = $this->makeNotification([
            'type'  => 'refund_pending',
            'title' => self::PREFIX . 'Refund needed',
        ]);

        // Far older than any informational row would survive.
        $this->backdate($gcash, 90);
        $this->backdate($refund, 400);

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
        $this->actingAs($this->admin(), 'admin')
            ->postJson(route('admin.notifications.dismiss', ['notification' => $gcash->id]))
            ->assertOk();

        $this->assertNotContains($gcash->id, $this->trayIds());
        $this->assertContains($refund->id, $this->trayIds());
    }

    public function test_dismiss_rejects_an_out_of_scope_notification_id(): void
    {
        // A customer-audience row is never in the staff tray scope.
        $customerRow = $this->makeNotification([
            'audience'  => Notification::AUDIENCE_CUSTOMER,
            'branch_id' => null,
            'user_id'   => $this->admin()->id,
            'type'      => 'order_status_changed',
            'title'     => self::PREFIX . 'not a staff row',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->postJson(route('admin.notifications.dismiss', ['notification' => $customerRow->id]))
            ->assertNotFound();

        $this->assertNull($customerRow->fresh()->dismissed_at);
    }

    public function test_the_tray_markup_carries_a_per_card_dismiss_x(): void
    {
        $src = file_get_contents(
            resource_path('views/admin/partials/notification-bell.blade.php')
        );

        $this->assertStringContainsString('data-anotif-dismiss', $src);
        $this->assertMatchesRegularExpression(
            '/\.pc-notif-dismiss\s*\{[^}]*border:\s*0[^}]*background:\s*transparent/s',
            $src,
            'the per-card dismiss button must reuse the borderless/transparent admin button style'
        );
        // Reuses the shared bi-x-lg icon, like the tray-level X.
        $dismissPos = strpos($src, 'data-anotif-dismiss');
        $this->assertStringContainsString('bi bi-x-lg', substr($src, $dismissPos, 160));
    }
}
