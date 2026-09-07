<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Can a visitor grant themselves privileges by smuggling extra fields into a
 * form the server was not expecting?
 *
 * RELATIONSHIP TO PASS 1 §4
 * -------------------------
 * Pass 1 tested this by hand against a running server and found no mass
 * assignment issue (the escalation it did find was the unvalidated points
 * award, a different bug). Pass 5 re-verified the same ground with fresh eyes
 * and confirms that result still holds — and turns it into standing tests,
 * which Pass 1 did not leave behind.
 *
 * WHY THIS NEEDS TESTS RATHER THAN A CODE READ
 * ---------------------------------------------
 * Every one of the 23 Eloquent models uses $fillable, but that is not what is
 * protecting this application. User::$fillable contains `role`, `is_active`
 * and `points`; Order::$fillable contains `status`, `payment_status`, `total`,
 * `discount_amount` and `discount_status`; Voucher::$fillable contains
 * `used_count`. Every privileged column in the schema is mass-assignable.
 *
 * What actually protects it is that no request array ever reaches a model.
 * Re-verified across app/ in Pass 5: zero occurrences of $request->all(),
 * $request->except(), or create()/update()/fill() taking a request array, and
 * all eight forceFill() call sites use hardcoded literal keys. Writes assign
 * either explicit literals or individually-named validated fields.
 *
 * That is a convention, not a mechanism. One future `->update($request->all())`
 * re-opens all of it silently, and the models will not stop it. These tests
 * are the thing that would notice.
 *
 * FALSE-POSITIVE DISCIPLINE
 * -------------------------
 * "The privileged field did not change" passes just as well when the request
 * was rejected outright for an unrelated reason — a validation error, a
 * missing session, a 429. So every test here asserts BOTH halves: the
 * privileged field is unchanged AND the request otherwise succeeded and did
 * the ordinary thing it was supposed to do.
 */
class MassAssignmentEscalationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // The discount test below places a real order with a real ID upload.
        // Without faking the disk, the suite leaves a file behind on every
        // run — the exact leak Pass 4 found and fixed in
        // CartTotalsMatchCheckoutTest. Both disks are faked so a regression
        // to the `public` disk would not leak either.
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)
            ->orderBy('id')->firstOrFail();
    }

    private function admin(): ?User
    {
        return User::where('role', 'admin')->where('is_active', true)->orderBy('id')->first();
    }

    private function staff(): ?User
    {
        return User::where('role', 'staff')->where('is_active', true)->orderBy('id')->first();
    }

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();
    }

    // ══════════════════════════════════════════════════════════════════
    // The models themselves offer no protection — pinned, so the reason
    // these tests exist stays visible
    // ══════════════════════════════════════════════════════════════════

    /**
     * Not a bug on its own, but the premise of everything below: if these
     * fields ever leave $fillable the protection changes shape, and whoever
     * does that should see this test and understand what it was relying on.
     */
    public function test_privileged_columns_are_mass_assignable_so_controllers_are_the_only_guard(): void
    {
        $userFillable = (new User)->getFillable();

        foreach (['role', 'is_active', 'points'] as $privileged) {
            $this->assertContains(
                $privileged,
                $userFillable,
                "User::\$fillable no longer contains {$privileged} — good, but this test's "
                . 'premise changed and the comments here need updating'
            );
        }

        $orderFillable = (new Order)->getFillable();
        foreach (['status', 'payment_status', 'total', 'discount_amount', 'discount_status'] as $privileged) {
            $this->assertContains($privileged, $orderFillable);
        }
    }

    /**
     * The convention that is actually doing the work. If this fails, some new
     * code is handing a raw request array to a model and every assertion below
     * is one refactor away from being wrong.
     */
    public function test_no_controller_hands_a_raw_request_array_to_a_model(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            // create($request->all()), update($request->all()), fill(...),
            // and the ->except() variant that is just as wide.
            if (preg_match(
                '/(create|update|fill|forceFill|updateOrCreate|firstOrCreate|insert)\s*\(\s*\$request->(all|except)\s*\(/',
                $source
            )) {
                $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these files pass a raw request array to a model, which makes every privileged "
            . "column in \$fillable settable from a form:\n  " . implode("\n  ", $offenders)
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. Registration
    // ══════════════════════════════════════════════════════════════════

    public function test_registration_cannot_smuggle_role_is_active_or_points(): void
    {
        $email = 'massassign-reg-' . uniqid() . '@invalid.local';

        $response = $this->post('/customer/register', [
            'name'                  => 'Escalation Probe',
            'email'                 => $email,
            'password'              => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'contact_number'        => '09171234567',
            'terms'                 => 'on',

            // The smuggled payload.
            'role'       => 'admin',
            'is_active'  => 1,
            'points'     => 99999,
            'verified_at' => now()->toDateTimeString(),
            'branch_id'  => 1,
        ]);

        $user = User::where('email', $email)->first();

        // CONTROL: the request must actually have worked. Without this, a
        // validation failure would satisfy every assertion below.
        $this->assertNotNull(
            $user,
            'the registration did not succeed, so this proves nothing about mass assignment. '
            . 'Errors: ' . json_encode(session('errors')?->all() ?? [])
        );
        $response->assertRedirect();

        // The actual assertions.
        $this->assertSame('customer', $user->role, 'ESCALATION: registration granted the admin role');
        $this->assertSame(0, (int) $user->points, 'ESCALATION: registration granted points');
        $this->assertNull($user->verified_at, 'registration should not let a visitor mark themselves verified');

        // And it did the ordinary thing: the account works.
        $this->assertSame('Escalation Probe', $user->name);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. Account settings
    // ══════════════════════════════════════════════════════════════════

    public function test_account_settings_cannot_smuggle_role_or_points(): void
    {
        $customer = $this->customer();
        $originalRole = $customer->role;
        $originalPoints = (int) $customer->points;
        $newName = 'Renamed ' . uniqid();

        $response = $this->actingAs($customer, 'customer')->put('/customer/account', [
            'name'           => $newName,
            'email'          => $customer->email,
            'contact_number' => '09170000000',
            'address'        => 'Test address',

            // Smuggled.
            'role'      => 'admin',
            'points'    => 99999,
            'is_active' => 0,
            'branch_id' => 1,
        ]);

        $fresh = $customer->fresh();

        // CONTROL: the update must actually have taken effect.
        $this->assertSame(
            $newName,
            $fresh->name,
            'the account update did not apply, so the assertions below prove nothing. '
            . 'Status: ' . $response->getStatusCode()
            . ' Errors: ' . json_encode(session('errors')?->all() ?? [])
        );

        $this->assertSame($originalRole, $fresh->role, 'ESCALATION: account settings changed the role');
        $this->assertSame($originalPoints, (int) $fresh->points, 'ESCALATION: account settings changed points');
        $this->assertTrue((bool) $fresh->is_active, 'account settings should not have deactivated the user');
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. Order placement — the money fields
    // ══════════════════════════════════════════════════════════════════

    public function test_placing_an_order_cannot_smuggle_totals_or_status(): void
    {
        $item = $this->item();
        $price = (float) $item->price;

        $payload = [
            'order_type' => 'pick_up',
            'items'      => [
                ['menu_item_id' => $item->id, 'quantity' => 2],
            ],
            'payment_method' => 'cash',

            // The smuggled payload: free food, already paid, already done.
            'discount_amount' => 9999,
            'total'           => 1,
            'subtotal'        => 1,
            'amount_paid'     => 0,
            'status'          => 'completed',
            'payment_status'  => 'paid',
            'discount_status' => 'approved',
            'receipt_number'  => 'FAKE-RECEIPT-1',
            'processed_by'    => 1,
        ];

        $before = (int) Order::max('id');

        $cart = [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => $price,
            'base_price'   => $price,
            'quantity'     => 2,
            'image'        => $item->image,
            'options'      => [],
        ]];

        $this->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', $payload);

        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        // CONTROL: an order must actually have been created.
        $this->assertNotNull(
            $order,
            'no order was created, so this proves nothing about mass assignment. '
            . 'Errors: ' . json_encode(session('errors')?->all() ?? [])
        );

        // The money must be what the server computed, not what was posted.
        $this->assertEqualsWithDelta(
            $price * 2,
            (float) $order->total,
            0.01,
            'ESCALATION: the posted total was accepted — the order was charged at the smuggled price'
        );
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.01, 'ESCALATION: a discount was smuggled in');

        // The state machine must start where the server says.
        $this->assertSame('pending', $order->status, 'ESCALATION: the order was created already completed');
        $this->assertNotSame('paid', $order->payment_status, 'ESCALATION: the order was created already paid');
        $this->assertNotSame('FAKE-RECEIPT-1', $order->receipt_number);
        $this->assertNull($order->processed_by, 'a customer must not be able to attribute their order to a staff member');

        /*
         * NOT asserted here: that discount_status is not 'approved'.
         *
         * It legitimately IS 'approved' on this order, and that is correct
         * rather than a smuggled value. OrderController::placeOrder()
         * initialises $discountStatus = 'approved' as the default for an order
         * with no PWD/Senior discount at all — there is nothing for staff to
         * verify, and discount_amount is 0, which is asserted above. The
         * posted discount_status never reaches the model either way.
         *
         * The case where that field actually carries authority is an order
         * that DOES claim a discount, where the server must say 'pending'
         * until staff approve it. That is the real test, and it is the next
         * one down.
         */
    }

    /**
     * The discount_status field only means something on an order that actually
     * claims a PWD/Senior discount: it is what holds the discount unverified
     * until staff approve it. So this posts a real claim AND smuggles
     * discount_status=approved, and asserts the server still says pending.
     *
     * Skipping straight to 'approved' would be a real escalation — a free
     * discount with no staff verification.
     */
    public function test_a_claimed_discount_cannot_be_smuggled_in_pre_approved(): void
    {
        $customer = $this->customer();
        $item = $this->item();
        $price = (float) $item->price;

        $cart = [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => $price,
            'base_price'   => $price,
            'quantity'     => 1,
            'image'        => $item->image,
            'options'      => [],
        ]];

        // A real PNG, because the upload rule needs one and GD is not
        // available on this machine to fake it.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC'
        );
        $tmp = tempnam(sys_get_temp_dir(), 'massid') . '.png';
        file_put_contents($tmp, $png);
        $upload = new \Illuminate\Http\UploadedFile($tmp, 'id.png', 'image/png', null, true);

        $before = (int) Order::max('id');

        $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],

                'discount_type'                   => 'pwd',
                'discount_beneficiary_name'       => 'Juan Dela Cruz',
                'discount_beneficiary_id'         => 'PWD-123',
                'discount_beneficiary_expiration' => now()->addYear()->toDateString(),
                'discount_beneficiary_image'      => $upload,

                // The smuggled payload: skip staff verification entirely.
                'discount_status' => 'approved',
            ]);

        @unlink($tmp);

        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        // CONTROL: the discounted order must actually have been created, or
        // the assertion below is empty.
        $this->assertNotNull(
            $order,
            'no discounted order was created, so this proves nothing. '
            . 'Errors: ' . json_encode(session('errors')?->all() ?? [])
        );
        $this->assertGreaterThan(
            0,
            (float) $order->discount_amount,
            'CONTROL: the discount was not applied at all, so discount_status is not '
            . 'being exercised as an authority field'
        );

        $this->assertSame(
            'pending',
            $order->discount_status,
            'ESCALATION: a claimed PWD discount was accepted as pre-approved, skipping staff verification'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. User-update endpoints reachable by a non-admin
    // ══════════════════════════════════════════════════════════════════

    /**
     * Staff may sign into the admin portal, so the question is whether any
     * user-writing endpoint there is reachable by a staff session — and if it
     * is, whether a role can be pushed through it.
     *
     * This confirms Pass 1 §3's live result (staff creating an admin was
     * bounced) and adds the mass-assignment half: not just "was it refused"
     * but "did any admin account appear".
     */
    public function test_staff_cannot_create_an_admin_through_the_staff_form(): void
    {
        $staff = $this->staff();
        if (! $staff) {
            $this->markTestSkipped('no active staff account in this database');
        }

        $adminsBefore = User::where('role', 'admin')->count();
        $email = 'massassign-staff-' . uniqid() . '@invalid.local';

        $this->actingAs($staff, 'admin')->post('/admin/users', [
            'name'      => 'Smuggled Admin',
            'email'     => $email,
            'branch_id' => 1,
            'password'  => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'role'      => 'admin',   // smuggled
            'is_active' => 1,
        ]);

        $this->assertSame(
            $adminsBefore,
            User::where('role', 'admin')->count(),
            'ESCALATION: a staff session created an admin account'
        );
        $this->assertNull(User::where('email', $email)->first(), 'staff should not be able to create users at all');
    }

    /**
     * The same form driven by a real admin — who IS allowed to use it — must
     * still force role=staff. This is the control that proves the test above
     * is not just observing a blocked route: the endpoint works, and it still
     * refuses to mint an admin.
     */
    public function test_even_an_admin_cannot_mint_another_admin_through_the_staff_form(): void
    {
        $admin = $this->admin();
        if (! $admin) {
            $this->markTestSkipped('no active admin account in this database');
        }

        $adminsBefore = User::where('role', 'admin')->count();
        $email = 'massassign-admin-' . uniqid() . '@invalid.local';

        $this->actingAs($admin, 'admin')->post('/admin/users', [
            'name'      => 'Should Be Staff',
            'email'     => $email,
            'branch_id' => 1,
            'password'  => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'role'      => 'admin',   // smuggled
            'points'    => 99999,
        ]);

        $created = User::where('email', $email)->first();

        // CONTROL: the creation must have worked, or the assertion is empty.
        $this->assertNotNull(
            $created,
            'the admin could not create a staff account, so this proves nothing. '
            . 'Errors: ' . json_encode(session('errors')?->all() ?? [])
        );

        $this->assertSame('staff', $created->role, 'ESCALATION: the staff form minted an admin');
        $this->assertSame(0, (int) $created->points, 'points were smuggled into a new staff account');
        $this->assertSame(
            $adminsBefore,
            User::where('role', 'admin')->count(),
            'the number of admin accounts changed'
        );
    }

    /**
     * The wheel endpoint from Pass 1 §4 — the escalation that WAS real. This
     * pins that fix: an arbitrary points value must still be refused.
     */
    public function test_the_points_award_endpoint_still_rejects_an_arbitrary_value(): void
    {
        $customer = $this->customer();
        $before = (int) $customer->fresh()->points;

        $this->actingAs($customer, 'customer')
            ->postJson('/customer/add-points', ['points' => 99999])
            ->assertStatus(422);

        $this->assertSame(
            $before,
            (int) $customer->fresh()->points,
            'ESCALATION: Pass 1 §4 regressed — an arbitrary points value was credited'
        );
    }
}
