<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PWD/Senior ID documents must not be readable without authorisation.
 *
 * THE EXPOSURE THIS EXISTS FOR (Pass 4, security checklist item #10)
 * ------------------------------------------------------------------
 * Discount ID uploads were written to the `public` disk, which is exposed
 * through the public/storage symlink. public/.htaccess serves an existing file
 * directly (`RewriteCond %{REQUEST_FILENAME} !-f`), so Laravel never ran for
 * those URLs at all. Proven live against a running server before any change:
 *
 *   $ curl -s -D- http://127.0.0.1:8123/storage/discount_ids/<name>.png
 *   HTTP/1.1 200 OK
 *   Content-Type: image/png
 *   Content-Length: 1299756
 *
 * — from a request with no cookies, no session and no authentication, and the
 * body was byte-identical (matching MD5) to the file on disk.
 *
 * Filenames are 40 random characters so they cannot be brute-forced, but the
 * admin order board rendered the URL into the page as `data-image="..."`,
 * putting it in page source, browser history and screenshots. The URL WAS the
 * credential: once one leaked it granted permanent, unauthenticated access.
 *
 * WHAT CHANGED
 * ------------
 * Uploads go to the `local` disk (storage/app), which is not web-reachable,
 * and are served only through /discount-id/{order}, which re-authorises every
 * request. The URL carries an ORDER id and never a filename.
 *
 * WHO MAY VIEW ONE — asserted below, both directions
 * ---------------------------------------------------
 *   staff / admin on the admin guard, active .... YES (verifying IS the feature)
 *   the customer who uploaded it ................ YES (their own document)
 *   the guest session that placed the order ..... YES (same ownership rule)
 *   a DIFFERENT customer ........................ NO
 *   an unauthenticated visitor .................. NO
 *   a deactivated staff account ................. NO
 * Full reasoning in App\Services\DiscountIdAccess.
 *
 * FALSE-POSITIVE DISCIPLINE
 * -------------------------
 * "The file was not served" passes just as happily when the route is broken,
 * misspelled, or returns 404 for everyone. So every refusal assertion in this
 * file is paired with a positive control proving the SAME order and the SAME
 * file are served to someone who is allowed — see
 * test_the_authorised_cases_actually_work_control(), and the paired
 * assertions inside the refusal tests themselves.
 */
class DiscountIdAccessTest extends TestCase
{
    use DatabaseTransactions;

    private string $storedPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Real disk, not Storage::fake() — the point is to prove the real
        // local disk is used and the real file is read back.
        $this->storedPath = '';
    }

    protected function tearDown(): void
    {
        // Bounded cleanup: only the file this test wrote, and only if it is
        // inside discount_ids/.
        if ($this->storedPath !== '' && str_starts_with($this->storedPath, 'discount_ids/')) {
            Storage::disk('local')->delete($this->storedPath);
        }

        parent::tearDown();
    }

    private function customer(int $skip = 0): User
    {
        return User::where('role', 'customer')->where('is_active', true)
            ->orderBy('id')->skip($skip)->firstOrFail();
    }

    private function staff(): ?User
    {
        return User::whereIn('role', ['admin', 'staff'])->where('is_active', true)
            ->orderBy('id')->first();
    }

    /**
     * A genuine, minimal PNG written straight to the real local disk.
     *
     * Not UploadedFile::fake()->image(), which needs the GD extension — GD is
     * not enabled on this machine, and a test that skips when an unrelated
     * extension is missing is a test that quietly stops guarding anything.
     * These are the real bytes of a 1x1 PNG, so finfo reports image/png and
     * the controller's image-type check is genuinely exercised rather than
     * bypassed.
     */
    private function storeRealImage(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $path = 'discount_ids/test-' . uniqid() . '.png';
        Storage::disk('local')->put($path, $png);

        $this->storedPath = $path;

        $this->assertTrue(
            Storage::disk('local')->exists($path),
            'setup failed: the fixture image was not written to the local disk'
        );
        $this->assertStringStartsWith(
            'image/',
            (string) Storage::disk('local')->mimeType($path),
            'setup failed: the fixture must be detected as a real image, or the '
            . 'controller\'s image-type check is not being exercised'
        );

        return $path;
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'DID-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ], $attrs));
    }

    // ══════════════════════════════════════════════════════════════════
    // The exposure itself: the old public path must be gone
    // ══════════════════════════════════════════════════════════════════

    /**
     * Uploads must land on the non-public disk. If this regresses, every other
     * assertion in this file keeps passing while the file is once again
     * readable by anyone — the route would be fine and the symlink would be
     * serving the file around it.
     */
    public function test_uploads_go_to_the_non_public_local_disk(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Customer/OrderController.php'));

        $this->assertStringNotContainsString(
            "store('discount_ids', 'public')",
            $source,
            'discount IDs must not be written to the public disk — that is the whole finding'
        );
        $this->assertStringContainsString(
            "store('discount_ids', 'local')",
            $source,
            'discount IDs should be stored on the local (non-web-reachable) disk'
        );
    }

    /**
     * The admin order board must not render a direct storage URL any more.
     * That attribute is how the URL escaped into page source and screenshots.
     */
    public function test_the_admin_board_no_longer_renders_a_public_storage_url(): void
    {
        $view = file_get_contents(base_path('resources/views/admin/home.blade.php'));

        $this->assertStringNotContainsString(
            "asset('storage/' . \$order->discount_id_image)",
            $view,
            'the order board must not build a direct public URL to an identity document'
        );
        $this->assertStringContainsString(
            "route('discount-id.show', \$order->id)",
            $view,
            'the order board should point at the authorising route instead'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // Allowed
    // ══════════════════════════════════════════════════════════════════

    public function test_staff_may_view_a_discount_id(): void
    {
        $staff = $this->staff();
        if (! $staff) {
            $this->markTestSkipped('no active admin/staff account in this database');
        }

        $path = $this->storeRealImage();
        $order = $this->makeOrder(['discount_id_image' => $path]);

        $response = $this->actingAs($staff, 'admin')->get("/discount-id/{$order->id}");

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
        $this->assertSame(
            Storage::disk('local')->get($path),
            $response->getContent(),
            'staff should receive the actual file contents'
        );
    }

    public function test_the_owning_customer_may_view_their_own_discount_id(): void
    {
        $customer = $this->customer();
        $path = $this->storeRealImage();
        $order = $this->makeOrder(['user_id' => $customer->id, 'discount_id_image' => $path]);

        $this->actingAs($customer, 'customer')
            ->get("/discount-id/{$order->id}")
            ->assertOk();
    }

    /**
     * A guest who uploaded an ID during a guest checkout can still see it from
     * the session that placed the order — the same rule the receipt uses.
     */
    public function test_the_guest_session_that_placed_the_order_may_view_it(): void
    {
        $path = $this->storeRealImage();
        $order = $this->makeOrder(['discount_id_image' => $path]);
        GuestOrders::remember($order->id);

        $this->get("/discount-id/{$order->id}")->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════
    // Refused — each paired with proof the same file IS servable
    // ══════════════════════════════════════════════════════════════════

    public function test_an_unauthenticated_visitor_is_refused(): void
    {
        $path = $this->storeRealImage();
        $order = $this->makeOrder(['discount_id_image' => $path]);

        // No session, no guest claim, nobody signed in.
        $this->get("/discount-id/{$order->id}")->assertNotFound();

        // CONTROL: the same order and the same file, for someone allowed.
        // Without this, a broken route would pass the assertion above.
        $staff = $this->staff();
        if ($staff) {
            $this->actingAs($staff, 'admin')
                ->get("/discount-id/{$order->id}")
                ->assertOk();
        }
    }

    public function test_a_different_customer_is_refused(): void
    {
        $owner = $this->customer();
        $other = $this->customer(1);

        if (! $other || $other->id === $owner->id) {
            $this->markTestSkipped('needs two distinct customer accounts');
        }

        $path = $this->storeRealImage();
        $order = $this->makeOrder(['user_id' => $owner->id, 'discount_id_image' => $path]);

        $this->actingAs($other, 'customer')
            ->get("/discount-id/{$order->id}")
            ->assertNotFound();

        // CONTROL: the owner gets it.
        $this->actingAs($owner, 'customer')
            ->get("/discount-id/{$order->id}")
            ->assertOk();
    }

    /**
     * A customer signed in on the CUSTOMER guard is not staff, even though
     * both are rows in the same users table. The staff branch must resolve
     * through the admin guard only.
     */
    public function test_a_customer_cannot_borrow_the_staff_privilege(): void
    {
        $customer = $this->customer();
        $path = $this->storeRealImage();

        // Someone else's order entirely.
        $order = $this->makeOrder(['user_id' => null, 'discount_id_image' => $path]);

        $this->actingAs($customer, 'customer')
            ->get("/discount-id/{$order->id}")
            ->assertNotFound();
    }

    /**
     * A deactivated staff account keeps its session until it next hits
     * middleware. The rule re-checks is_active itself, so a document is not
     * readable in that window.
     */
    public function test_a_deactivated_staff_account_is_refused(): void
    {
        $staff = $this->staff();
        if (! $staff) {
            $this->markTestSkipped('no active admin/staff account in this database');
        }

        $path = $this->storeRealImage();
        $order = $this->makeOrder(['discount_id_image' => $path]);

        // CONTROL first, while still active.
        $this->actingAs($staff, 'admin')->get("/discount-id/{$order->id}")->assertOk();

        // DatabaseTransactions rolls this back.
        $staff->forceFill(['is_active' => false])->save();

        $this->actingAs($staff->fresh(), 'admin')
            ->get("/discount-id/{$order->id}")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════
    // Missing / malformed
    // ══════════════════════════════════════════════════════════════════

    public function test_an_order_that_does_not_exist_is_404_not_500(): void
    {
        $staff = $this->staff();
        $missingId = ((int) Order::max('id')) + 99999;

        $test = $staff ? $this->actingAs($staff, 'admin') : $this;
        $test->get("/discount-id/{$missingId}")->assertNotFound();
    }

    public function test_an_order_with_no_discount_id_is_404(): void
    {
        $staff = $this->staff();
        if (! $staff) {
            $this->markTestSkipped('no active admin/staff account in this database');
        }

        $order = $this->makeOrder(['discount_id_image' => null]);

        $this->actingAs($staff, 'admin')
            ->get("/discount-id/{$order->id}")
            ->assertNotFound();
    }

    /**
     * A row can outlive its file — a hand-cleared storage directory, a partial
     * restore. That must be a 404, never a 500 leaking a stack trace.
     */
    public function test_a_row_pointing_at_a_missing_file_is_404_not_500(): void
    {
        $staff = $this->staff();
        if (! $staff) {
            $this->markTestSkipped('no active admin/staff account in this database');
        }

        $order = $this->makeOrder([
            'discount_id_image' => 'discount_ids/this-file-was-deleted-' . uniqid() . '.png',
        ]);

        $response = $this->actingAs($staff, 'admin')->get("/discount-id/{$order->id}");

        $response->assertNotFound();
        $this->assertNotSame(500, $response->getStatusCode());
    }

    /**
     * Even a tampered database value cannot walk out of the allowed directory.
     * The client never supplies a path, so this is defence in depth rather
     * than the primary control — but a stored "../../.env" must not be read.
     */
    public function test_a_traversal_path_in_the_database_cannot_escape(): void
    {
        $staff = $this->staff();
        if (! $staff) {
            $this->markTestSkipped('no active admin/staff account in this database');
        }

        foreach ([
            '../../../.env',
            'discount_ids/../../../.env',
            '/etc/passwd',
            'settings/gcash/qr.png',
        ] as $evil) {
            $order = $this->makeOrder(['discount_id_image' => $evil]);

            $this->actingAs($staff, 'admin')
                ->get("/discount-id/{$order->id}")
                ->assertNotFound("path '{$evil}' must not be served");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Response hygiene
    // ══════════════════════════════════════════════════════════════════

    public function test_an_id_document_is_never_cached_by_a_shared_cache(): void
    {
        $staff = $this->staff();
        if (! $staff) {
            $this->markTestSkipped('no active admin/staff account in this database');
        }

        $path = $this->storeRealImage();
        $order = $this->makeOrder(['discount_id_image' => $path]);

        $response = $this->actingAs($staff, 'admin')->get("/discount-id/{$order->id}");

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // ══════════════════════════════════════════════════════════════════
    // The control that makes every refusal above mean something
    // ══════════════════════════════════════════════════════════════════

    /**
     * If the route were broken, misrouted or 404-for-everyone, every "is
     * refused" assertion in this file would still pass. This asserts the
     * happy path end to end: a real file, on the real local disk, served
     * with real bytes, to each party that is supposed to get it.
     */
    public function test_the_authorised_cases_actually_work_control(): void
    {
        $path = $this->storeRealImage();
        $expected = Storage::disk('local')->get($path);
        $this->assertNotEmpty($expected, 'fixture file should have content');

        $customer = $this->customer();
        $order = $this->makeOrder(['user_id' => $customer->id, 'discount_id_image' => $path]);

        $ownerResponse = $this->actingAs($customer, 'customer')->get("/discount-id/{$order->id}");
        $ownerResponse->assertOk();
        $this->assertSame(
            strlen($expected),
            strlen($ownerResponse->getContent()),
            'the owner should receive the whole file, not a truncated or empty body'
        );

        $staff = $this->staff();
        if ($staff) {
            $staffResponse = $this->actingAs($staff, 'admin')->get("/discount-id/{$order->id}");
            $staffResponse->assertOk();
            $this->assertSame(strlen($expected), strlen($staffResponse->getContent()));
        }
    }
}
