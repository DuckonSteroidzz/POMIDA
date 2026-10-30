<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\BranchGcash;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * GCash per branch (Defect Report 5, item 2 — Oct 2026).
 *
 * One rule, App\Support\BranchGcash: a branch offers GCash only when it is
 * switched on AND has a QR AND a number. Checkout hides the option and the
 * server refuses it otherwise; the counter does the same; an order placed
 * before GCash was turned off still pays with its own branch's QR.
 *
 * Every QR upload goes to a FAKE public disk. The real QR file in
 * storage/app/public/settings/gcash is never touched by these tests.
 */
class BranchGcashTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRATION = 'database/migrations/2026_10_30_000000_copy_global_gcash_to_each_branch.php';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function user(string $role, ?int $branchId = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'branch_id' => $branchId,
            'is_active' => true,
            'email' => 'bgc-' . $role . '-' . uniqid() . '@example.test',
        ]);
    }

    private function owner(): User
    {
        return $this->user('admin');
    }

    private function branch(array $attrs = []): Branch
    {
        return Branch::create(array_merge([
            'name' => 'GC Branch ' . uniqid(),
            'code' => 'G' . strtoupper(substr(uniqid(), -6)),
            'address' => 'GCash test street',
            'is_active' => true,
            'is_main_branch' => false,
        ], $attrs));
    }

    /** Give a branch GCash settings directly (as the migration / a save would). */
    private function setGcash(Branch $branch, ?string $enabled, ?string $qr, ?string $number): void
    {
        Setting::where('branch_id', $branch->id)->whereIn('key', ['gcash_enabled', 'gcash_qr', 'gcash_phone'])->delete();
        foreach (['gcash_enabled' => $enabled, 'gcash_qr' => $qr, 'gcash_phone' => $number] as $key => $value) {
            if ($value !== null) {
                Setting::create(['branch_id' => $branch->id, 'key' => $key, 'value' => $value, 'group' => 'payment', 'type' => 'text']);
            }
        }
    }

    private function editPayload(Branch $branch, array $overrides = []): array
    {
        return array_merge([
            '_form' => 'edit',
            '_branch_id' => $branch->id,
            'name' => $branch->name,
            'address' => $branch->address,
            'is_active' => '1',
            'gcash_enabled' => '0',
            'gcash_number' => '',
        ], $overrides);
    }

    private function png(string $name = 'qr.png', int $kb = 20): UploadedFile
    {
        return UploadedFile::fake()->image($name, 300, 300)->size($kb);
    }

    // ══════════ the one rule ══════════

    public function test_a_branch_offers_gcash_only_when_on_with_both_a_qr_and_a_number(): void
    {
        $b = $this->branch();

        $this->setGcash($b, '1', 'settings/gcash/a.png', '09171234567');
        $this->assertTrue(BranchGcash::available($b));

        $this->setGcash($b, '0', 'settings/gcash/a.png', '09171234567');
        $this->assertFalse(BranchGcash::available($b), 'switched off');

        $this->setGcash($b, '1', null, '09171234567');
        $this->assertFalse(BranchGcash::available($b), 'no QR');

        $this->setGcash($b, '1', 'settings/gcash/a.png', null);
        $this->assertFalse(BranchGcash::available($b), 'no number');

        $this->setGcash($b, null, null, null);
        $this->assertFalse(BranchGcash::available($b), 'a branch with no rows is off');
    }

    public function test_phone_numbers_are_normalised_to_the_stored_11_digit_format(): void
    {
        // '917 123 4567' / '9171234567' is what the Account Settings field
        // posts: the ten digits typed beside its fixed +63.
        foreach (['09171234567', '0917 123 4567', '0917-123-4567', '+63 917 123 4567', '639171234567', '(0917) 123.4567', '917 123 4567', '9171234567'] as $input) {
            $this->assertSame('09171234567', BranchGcash::normalizeNumber($input), $input);
        }

        foreach (['12345', '0817 123 4567', '0917123456', '091712345678', 'abc', '+1 917 123 4567', '817 123 4567', '91712345678', '+9171234567'] as $bad) {
            $this->assertNull(BranchGcash::normalizeNumber($bad), $bad);
        }
    }

    // ══════════ the data migration ══════════

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    public function test_the_migration_copies_the_global_qr_and_number_to_every_branch_and_turns_them_on(): void
    {
        Setting::whereNotNull('branch_id')->whereIn('key', ['gcash_enabled', 'gcash_qr', 'gcash_phone'])->delete();
        Setting::updateOrCreate(['branch_id' => null, 'key' => 'gcash_qr'], ['value' => 'settings/gcash/global.png']);
        Setting::updateOrCreate(['branch_id' => null, 'key' => 'gcash_phone'], ['value' => '09159139900']);
        $extra = $this->branch();

        $this->migration()->up();

        foreach (Branch::pluck('id') as $id) {
            $gcash = BranchGcash::for($id);
            $this->assertSame('settings/gcash/global.png', $gcash['qr'], "branch {$id} QR");
            $this->assertSame('09159139900', $gcash['number'], "branch {$id} number");
            $this->assertTrue($gcash['enabled'], "branch {$id} has both, so it is on");
            $this->assertTrue($gcash['available']);
        }

        // The global rows are kept.
        $this->assertSame('settings/gcash/global.png', Setting::get('gcash_qr', null, null));
        $this->assertSame('09159139900', Setting::get('gcash_phone', null, null));
        $this->assertTrue(BranchGcash::available($extra));
    }

    public function test_the_migration_leaves_gcash_off_when_the_shop_had_no_number(): void
    {
        Setting::whereNotNull('branch_id')->whereIn('key', ['gcash_enabled', 'gcash_qr', 'gcash_phone'])->delete();
        Setting::updateOrCreate(['branch_id' => null, 'key' => 'gcash_qr'], ['value' => 'settings/gcash/global.png']);
        Setting::updateOrCreate(['branch_id' => null, 'key' => 'gcash_phone'], ['value' => '']);

        $this->migration()->up();

        foreach (Branch::pluck('id') as $id) {
            $gcash = BranchGcash::for($id);
            $this->assertSame('settings/gcash/global.png', $gcash['qr']);
            $this->assertNull($gcash['number']);
            $this->assertFalse($gcash['enabled'], "branch {$id} has no number, so it is off");
        }
    }

    public function test_the_migration_never_overwrites_a_branch_and_is_safe_to_run_twice(): void
    {
        Setting::whereNotNull('branch_id')->whereIn('key', ['gcash_enabled', 'gcash_qr', 'gcash_phone'])->delete();
        Setting::updateOrCreate(['branch_id' => null, 'key' => 'gcash_qr'], ['value' => 'settings/gcash/global.png']);
        Setting::updateOrCreate(['branch_id' => null, 'key' => 'gcash_phone'], ['value' => '09159139900']);
        $own = $this->branch();
        $this->setGcash($own, '0', 'settings/gcash/own.png', '09181112222');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(['enabled' => false, 'qr' => 'settings/gcash/own.png', 'number' => '09181112222', 'available' => false], BranchGcash::for($own));
        $this->assertSame(3 * Branch::count(), Setting::whereNotNull('branch_id')->whereIn('key', ['gcash_enabled', 'gcash_qr', 'gcash_phone'])->count(), 'no duplicate rows');

        // down() removes only what up() wrote and nobody changed.
        $this->migration()->down();
        $this->assertSame('settings/gcash/own.png', BranchGcash::for($own)['qr'], 'an owner-set QR survives a rollback');
        $this->assertSame('09159139900', Setting::get('gcash_phone', null, null), 'global kept');
    }

    // ══════════ saving from Edit Branch (owner) ══════════

    public function test_the_owner_turns_gcash_on_with_a_new_qr_and_number(): void
    {
        $b = $this->branch();

        $this->actingAs($this->owner(), 'admin')
            ->put(route('admin.branches.update', $b->id), $this->editPayload($b, [
                'gcash_enabled' => '1',
                'gcash_number' => '0917 123 4567',
                'gcash_qr' => $this->png('My QR.png'),
            ]))
            ->assertRedirect(route('admin.branches'))
            ->assertSessionHasNoErrors();

        $gcash = BranchGcash::for($b);
        $this->assertTrue($gcash['available']);
        $this->assertSame('09171234567', $gcash['number'], 'stored in the 11-digit format');
        $this->assertStringStartsWith('settings/gcash/', $gcash['qr']);
        $this->assertStringNotContainsString('My QR', $gcash['qr'], 'stored under a random name, not the upload name');
        $this->assertMatchesRegularExpression('/\.png$/', $gcash['qr']);
        Storage::disk('public')->assertExists($gcash['qr']);
    }

    public function test_turning_gcash_on_needs_both_a_qr_and_a_number(): void
    {
        $owner = $this->owner();
        $b = $this->branch();

        // Number but no QR anywhere.
        $this->actingAs($owner, 'admin')
            ->from(route('admin.branches'))
            ->put(route('admin.branches.update', $b->id), $this->editPayload($b, ['gcash_enabled' => '1', 'gcash_number' => '09171234567']))
            ->assertSessionHasErrors(['gcash_enabled' => BranchGcash::ENABLE_NEEDS_BOTH_MESSAGE]);

        // QR but no number.
        $this->actingAs($owner, 'admin')
            ->from(route('admin.branches'))
            ->put(route('admin.branches.update', $b->id), $this->editPayload($b, ['gcash_enabled' => '1', 'gcash_qr' => $this->png()]))
            ->assertSessionHasErrors('gcash_enabled');

        $this->assertFalse(BranchGcash::for($b)['enabled']);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'a refused save stores no file');

        // An existing QR counts.
        $this->setGcash($b, '0', 'settings/gcash/kept.png', null);
        $this->actingAs($owner, 'admin')
            ->put(route('admin.branches.update', $b->id), $this->editPayload($b, ['gcash_enabled' => '1', 'gcash_number' => '09171234567']))
            ->assertSessionHasNoErrors();
        $this->assertTrue(BranchGcash::available($b));
    }

    public function test_the_number_must_be_a_ph_mobile_number(): void
    {
        $b = $this->branch();

        $this->actingAs($this->owner(), 'admin')
            ->from(route('admin.branches'))
            ->put(route('admin.branches.update', $b->id), $this->editPayload($b, ['gcash_number' => '12345']))
            ->assertSessionHasErrors(['gcash_number' => BranchGcash::NUMBER_MESSAGE]);
    }

    public static function badUploads(): array
    {
        return [
            'pdf'       => [fn () => UploadedFile::fake()->create('qr.pdf', 50, 'application/pdf')],
            'gif'       => [fn () => UploadedFile::fake()->image('qr.gif')],
            'over 5 MB' => [fn () => UploadedFile::fake()->image('qr.png', 300, 300)->size(5121)],
        ];
    }

    /**
     * @dataProvider badUploads
     */
    public function test_the_qr_upload_rules_are_enforced_on_the_server(\Closure $file): void
    {
        $b = $this->branch();
        $this->setGcash($b, '0', 'settings/gcash/old.png', null);

        $this->actingAs($this->owner(), 'admin')
            ->from(route('admin.branches'))
            ->put(route('admin.branches.update', $b->id), $this->editPayload($b, ['gcash_qr' => $file()]))
            ->assertSessionHasErrors('gcash_qr');

        $this->assertSame('settings/gcash/old.png', BranchGcash::for($b)['qr']);
    }

    public function test_the_old_qr_is_removed_only_after_the_new_one_saves_and_only_if_unused(): void
    {
        $owner = $this->owner();
        $a = $this->branch();
        $b = $this->branch();
        Storage::disk('public')->put('settings/gcash/shared.png', 'x');
        $this->setGcash($a, '1', 'settings/gcash/shared.png', '09171234567');
        $this->setGcash($b, '1', 'settings/gcash/shared.png', '09171234567');

        // A replaces it — B still uses the file, so it stays.
        $this->actingAs($owner, 'admin')
            ->put(route('admin.branches.update', $a->id), $this->editPayload($a, ['gcash_enabled' => '1', 'gcash_number' => '09171234567', 'gcash_qr' => $this->png()]))
            ->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists('settings/gcash/shared.png');
        Storage::disk('public')->assertExists(BranchGcash::for($a)['qr']);

        // B replaces it too — now nobody uses it, so it goes.
        $this->actingAs($owner, 'admin')
            ->put(route('admin.branches.update', $b->id), $this->editPayload($b, ['gcash_enabled' => '1', 'gcash_number' => '09171234567', 'gcash_qr' => $this->png()]))
            ->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing('settings/gcash/shared.png');
    }

    public function test_turning_gcash_off_keeps_the_qr_and_number(): void
    {
        $b = $this->branch();
        $this->setGcash($b, '1', 'settings/gcash/k.png', '09171234567');

        $this->actingAs($this->owner(), 'admin')
            ->put(route('admin.branches.update', $b->id), $this->editPayload($b, ['gcash_enabled' => '0', 'gcash_number' => '09171234567']))
            ->assertSessionHasNoErrors();

        $this->assertSame(['enabled' => false, 'qr' => 'settings/gcash/k.png', 'number' => '09171234567', 'available' => false], BranchGcash::for($b));
    }

    public function test_an_edit_without_the_gcash_fields_leaves_gcash_alone(): void
    {
        $b = $this->branch();
        $this->setGcash($b, '1', 'settings/gcash/k.png', '09171234567');

        $this->actingAs($this->owner(), 'admin')
            ->put(route('admin.branches.update', $b->id), ['name' => $b->name, 'address' => 'New address', 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(BranchGcash::available($b));
    }

    // ══════════ only the owner ══════════

    public static function nonOwners(): array
    {
        return ['staff' => ['staff'], 'supervisor' => ['supervisor']];
    }

    /**
     * @dataProvider nonOwners
     */
    public function test_only_the_owner_can_change_gcash(string $role): void
    {
        $b = $this->branch();
        $this->setGcash($b, '1', 'settings/gcash/k.png', '09171234567');
        $user = $this->user($role, $b->id);

        // The owner-only route group turns them away (the app's standard
        // denial: a redirect to the dashboard, see RolePermissionMatrixTest)…
        $this->actingAs($user, 'admin')
            ->put(route('admin.branches.update', $b->id), $this->editPayload($b, ['gcash_enabled' => '0']))
            ->assertRedirect(route('admin.home'));
        $this->actingAs($user, 'admin')
            ->post(route('admin.account.gcash-qr.update'), ['branch_id' => $b->id, 'gcash_phone' => '09180000000'])
            ->assertRedirect(route('admin.home'));

        $this->assertTrue(BranchGcash::available($b));
        $this->assertSame('09171234567', BranchGcash::for($b)['number']);

        // …and the save itself refuses them with a 403 even if a route ever moved.
        $this->actingAs($user, 'admin');
        try {
            BranchGcash::save($b, false, null, null);
            $this->fail('a non-owner saved GCash settings');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ══════════ Account Settings card: the Viewing branch ══════════

    public function test_the_account_card_asks_for_a_branch_when_all_branches_is_selected(): void
    {
        $html = $this->actingAs($this->owner(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get(route('admin.account'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Select a branch to manage its GCash', $html);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*disabled/', $html);
        $this->assertStringNotContainsString('name="branch_id" value=', $html);
    }

    public function test_the_account_card_reads_and_saves_the_viewing_branch_only(): void
    {
        $owner = $this->owner();
        $a = $this->branch(['name' => 'Account Card Branch A']);
        $b = $this->branch();
        $this->setGcash($a, '0', null, '09171111111');
        $this->setGcash($b, '0', null, '09172222222');
        $globalBefore = Setting::get('gcash_phone', null, null);

        $html = $this->actingAs($owner, 'admin')
            ->withSession(['selected_branch_id' => $a->id])
            ->get(route('admin.account'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Account Card Branch A', $html);
        // The field sits beside a fixed +63 and shows the 10 digits after it.
        $this->assertStringContainsString('value="917 111 1111"', $html);
        $this->assertStringContainsString('name="branch_id" value="' . $a->id . '"', $html);

        $this->actingAs($owner, 'admin')
            ->post(route('admin.account.gcash-qr.update'), ['branch_id' => $a->id, 'gcash_phone' => '0917 333 3333', 'gcash_qr' => $this->png()])
            ->assertRedirect(route('admin.account'))
            ->assertSessionHasNoErrors();

        $this->assertSame('09173333333', BranchGcash::for($a)['number']);
        $this->assertNotNull(BranchGcash::for($a)['qr']);
        $this->assertSame('09172222222', BranchGcash::for($b)['number'], 'another branch is untouched');
        $this->assertSame($globalBefore, Setting::get('gcash_phone', null, null), 'the global value is untouched');
    }

    public function test_the_account_card_refuses_a_save_without_a_branch(): void
    {
        $this->actingAs($this->owner(), 'admin')
            ->from(route('admin.account'))
            ->post(route('admin.account.gcash-qr.update'), ['gcash_phone' => '09171234567'])
            ->assertSessionHasErrors('branch_id');
    }

    // ══════════ customer checkout ══════════

    private function orderableItem(): MenuItem
    {
        return MenuItem::query()->firstOrFail();
    }

    public function test_checkout_hides_gcash_for_a_branch_without_it_and_shows_it_for_one_with_it(): void
    {
        // A real branch with a real item, so the cart renders its payment step.
        $item = MenuItem::where('is_available', true)->whereNotNull('branch_id')->firstOrFail();
        $b = Branch::findOrFail($item->branch_id);
        $cart = [(string) $item->id => [
            'menu_item_id' => $item->id, 'name' => $item->name, 'price' => (float) $item->price,
            'base_price' => (float) $item->price, 'quantity' => 1, 'image' => null, 'options' => [],
        ]];
        $session = ['order_type' => 'pick_up', 'branch_id' => $b->id, 'cart' => $cart];

        $this->setGcash($b, '0', 'settings/gcash/k.png', '09171234567');
        $off = $this->withSession($session)->get('/customer/cart')->assertOk()->getContent();
        $this->assertStringContainsString('id="cashPaymentBtn"', $off, 'the payment step rendered');
        $this->assertStringNotContainsString('id="gcashPaymentBtn"', $off);

        $this->setGcash($b, '1', 'settings/gcash/k.png', '09171234567');
        $on = $this->withSession($session)->get('/customer/cart')->assertOk()->getContent();
        $this->assertStringContainsString('id="gcashPaymentBtn"', $on);
    }

    public function test_the_server_refuses_gcash_for_a_branch_without_it(): void
    {
        $b = $this->branch();
        $this->setGcash($b, '0', 'settings/gcash/k.png', '09171234567');
        $before = Order::count();

        $this->withSession(['order_type' => 'pick_up', 'branch_id' => $b->id])
            ->from('/customer/cart')
            ->post('/customer/place-order', [
                'order_type' => 'pick_up',
                'payment_method' => 'gcash',
                'items' => [['menu_item_id' => $this->orderableItem()->id, 'quantity' => 1]],
            ])
            ->assertRedirect('/customer/cart')
            ->assertSessionHasErrors(['payment_method' => BranchGcash::UNAVAILABLE_MESSAGE]);

        $this->assertSame($before, Order::count(), 'no order was created');
    }

    // ══════════ the GCash payment page ══════════

    private function guestGcashOrder(Branch $branch, string $paymentStatus = 'pending'): Order
    {
        $order = Order::create([
            'order_number' => 'BGC-' . substr(uniqid(), -8),
            'user_id' => null,
            'branch_id' => $branch->id,
            'type' => 'pick_up',
            'status' => 'pending',
            'payment_method' => 'gcash',
            'payment_status' => $paymentStatus,
            'subtotal' => 100,
            'total' => 100,
        ]);
        GuestOrders::remember($order->id);

        return $order;
    }

    public function test_the_gcash_page_shows_the_orders_own_branch_qr_and_number(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $this->setGcash($a, '1', 'settings/gcash/branch-a.png', '09171111111');
        $this->setGcash($b, '1', 'settings/gcash/branch-b.png', '09172222222');

        $html = $this->get(route('customer.gcash-payment', $this->guestGcashOrder($b)->id))->assertOk()->getContent();

        $this->assertStringContainsString('09172222222', $html);
        $this->assertStringContainsString('settings/gcash/branch-b.png', $html);
        $this->assertStringNotContainsString('09171111111', $html);
        $this->assertStringNotContainsString('branch-a.png', $html);
    }

    public function test_an_order_placed_before_gcash_was_turned_off_still_works(): void
    {
        $b = $this->branch();
        $this->setGcash($b, '1', 'settings/gcash/branch-b.png', '09172222222');
        $order = $this->guestGcashOrder($b);

        // The owner switches GCash off after the order was placed.
        $this->setGcash($b, '0', 'settings/gcash/branch-b.png', '09172222222');

        $html = $this->get(route('customer.gcash-payment', $order->id))->assertOk()->getContent();
        $this->assertStringContainsString('09172222222', $html, 'the customer can still see where to pay');

        $this->post(route('customer.gcash-payment.paid', $order->id))
            ->assertRedirect(route('customer.gcash-payment', $order->id));
        $this->assertSame('awaiting_verification', $order->fresh()->payment_status);
    }

    // ══════════ the counter (manual order) ══════════

    public function test_the_counter_cannot_record_gcash_for_a_branch_without_it(): void
    {
        $b = $this->branch();
        $this->setGcash($b, '0', null, null);

        $this->actingAs($this->owner(), 'admin')
            ->from(route('admin.home'))
            ->post(route('admin.manual-order.store'), [
                'branch_id' => $b->id,
                'order_type' => 'pick_up',
                'payment_method' => 'gcash',
                'amount_paid' => 100,
                'items' => [['menu_item_id' => $this->orderableItem()->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors(['payment_method' => BranchGcash::UNAVAILABLE_MESSAGE]);
    }

    public function test_the_counter_form_marks_which_branches_offer_gcash(): void
    {
        $owner = $this->owner();
        $on = $this->branch(['name' => 'Counter GCash On']);
        $off = $this->branch(['name' => 'Counter GCash Off']);
        $this->setGcash($on, '1', 'settings/gcash/k.png', '09171234567');
        $this->setGcash($off, '0', 'settings/gcash/k.png', '09171234567');

        $html = $this->actingAs($owner, 'admin')->get(route('admin.home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="' . $on->id . '"\s+data-gcash="1"/', $html);
        $this->assertMatchesRegularExpression('/value="' . $off->id . '"\s+data-gcash="0"/', $html);
        $this->assertStringContainsString('function syncManualGcashOption()', $html);
    }
}
