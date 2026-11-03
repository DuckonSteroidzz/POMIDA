<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Providers\RateLimitServiceProvider;
use App\Services\GcashProof;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The customer's GCash payment screenshot: who can attach it, what is accepted,
 * who can see it, and that the GCash flow around it is unchanged.
 *
 * Storage is faked in setUp(): nothing here touches storage/app. The images are
 * real ones built with GD, written to temp files with neutral names, because
 * the `mimes` rule and the decoder both read the file's bytes.
 *
 * Every refusal is paired with someone who IS allowed on the same order, since
 * "404" also passes when the route is simply broken.
 */
class GcashPaymentProofTest extends TestCase
{
    use DatabaseTransactions;

    private const PROOF_PATTERN = '#^gcash_proofs/[a-f0-9]{40}\.jpg$#';

    private Branch $branch;

    private Branch $otherBranch;

    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->branch = $this->makeBranch();
        $this->otherBranch = $this->makeBranch();

        foreach (['gcash_enabled' => '1', 'gcash_qr' => 'settings/gcash/test.png', 'gcash_phone' => '09170000000'] as $key => $value) {
            Setting::create(['branch_id' => $this->branch->id, 'key' => $key, 'value' => $value, 'group' => 'payment', 'type' => 'text']);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ══════════════════ fixtures ══════════════════

    private function makeBranch(): Branch
    {
        return Branch::create([
            'name'      => 'PROOF Branch ' . uniqid(),
            'code'      => 'PRF' . strtoupper(substr(uniqid(), -6)),
            'address'   => 'PROOF address',
            'is_active' => true,
        ]);
    }

    private function user(string $role, ?Branch $branch = null, array $attrs = []): User
    {
        return User::create(array_merge([
            'name'      => 'PROOF ' . $role,
            'email'     => 'proof-' . $role . '-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => $role,
            'branch_id' => $branch?->id,
            'is_active' => true,
        ], $attrs));
    }

    private function order(array $attrs = [], int $items = 1): Order
    {
        $order = Order::create(array_merge([
            'order_number'    => 'PRF-' . strtoupper(substr(uniqid(), -8)),
            'user_id'         => null,
            'branch_id'       => $this->branch->id,
            'type'            => 'pick_up',
            'status'          => 'pending',
            'subtotal'        => 250.00,
            'discount_amount' => 0,
            'total'           => 250.00,
            'payment_method'  => 'gcash',
            'payment_status'  => 'pending',
        ], $attrs));

        for ($i = 0; $i < $items; $i++) {
            OrderItem::create([
                'order_id'     => $order->id,
                'menu_item_id' => MenuItem::query()->value('id'),
                'item_name'    => 'PROOF Line ' . $i,
                'item_price'   => 250.00,
                'quantity'     => 1,
                'subtotal'     => 250.00,
            ]);
        }

        return $order;
    }

    /** An order that already has a screenshot on the fake disk. Returns [order, path]. */
    private function orderWithProof(array $attrs = []): array
    {
        $path = 'gcash_proofs/' . bin2hex(random_bytes(20)) . '.jpg';
        Storage::disk('local')->put($path, $this->jpegBytes(40, 40));

        $order = $this->order($attrs);
        $order->gcash_proof_path = $path;
        $order->gcash_proof_uploaded_at = now();
        $order->save();

        return [$order, $path];
    }

    private function jpegBytes(int $width = 60, int $height = 80): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 220));

        ob_start();
        imagejpeg($image);

        return ob_get_clean();
    }

    private function fileFrom(string $bytes, string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'prf');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function jpeg(string $clientName = 'receipt.jpg', int $width = 60, int $height = 80): UploadedFile
    {
        return $this->fileFrom($this->jpegBytes($width, $height), $clientName);
    }

    private function png(): UploadedFile
    {
        $image = imagecreatetruecolor(30, 30);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        ob_start();
        imagepng($image);

        return $this->fileFrom(ob_get_clean(), 'receipt.png');
    }

    private function webp(): UploadedFile
    {
        $image = imagecreatetruecolor(30, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 10, 10));

        ob_start();
        imagewebp($image);

        return $this->fileFrom(ob_get_clean(), 'receipt.webp');
    }

    /** Post a screenshot the way the page's script does, as JSON. */
    private function upload(Order $order, ?UploadedFile $file)
    {
        return $this->post(
            route('customer.gcash-payment.proof.store', $order->id),
            $file ? ['screenshot' => $file] : [],
            ['Accept' => 'application/json']
        );
    }

    private function removeProof(Order $order)
    {
        return $this->delete(
            route('customer.gcash-payment.proof.destroy', $order->id),
            [],
            ['Accept' => 'application/json']
        );
    }

    private function assertNothingStored(Order $order): void
    {
        $this->assertNull($order->fresh()->gcash_proof_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ══════════════════ upload: what is accepted ══════════════════

    /** @return array<string, array{string}> */
    public static function acceptedFormats(): array
    {
        return ['jpg' => ['jpeg'], 'png' => ['png'], 'webp' => ['webp']];
    }

    /** @dataProvider acceptedFormats */
    public function test_a_signed_in_owner_can_attach_each_accepted_format(string $format): void
    {
        $customer = $this->user('customer');
        $order = $this->order(['user_id' => $customer->id]);

        $response = $this->actingAs($customer, 'customer')->upload($order, $this->{$format}());

        $response->assertOk()->assertJson(['success' => true]);

        $path = $order->fresh()->gcash_proof_path;
        $this->assertMatchesRegularExpression(self::PROOF_PATTERN, $path);
        $this->assertNotNull($order->fresh()->gcash_proof_uploaded_at);

        // Whatever came in, a real JPEG went onto the private disk.
        $stored = Storage::disk('local')->get($path);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($stored)[2]);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_guests_own_session_can_attach(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->upload($order, $this->jpeg())->assertOk();

        $this->assertMatchesRegularExpression(self::PROOF_PATTERN, $order->fresh()->gcash_proof_path);
    }

    public function test_a_plain_form_post_comes_back_to_the_payment_page(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->post(route('customer.gcash-payment.proof.store', $order->id), ['screenshot' => $this->jpeg()])
            ->assertRedirect(route('customer.gcash-payment', $order->id));

        $this->assertNotNull($order->fresh()->gcash_proof_path);
    }

    public function test_exactly_5_mb_is_accepted_and_one_kilobyte_more_is_refused(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        // Padding after the end-of-image marker: still a valid JPEG of that size.
        $pad = fn (int $kilobytes) => str_pad($this->jpegBytes(), $kilobytes * 1024, "\0");

        $this->upload($order, $this->fileFrom($pad(5121), 'big.jpg'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['screenshot' => 'larger than 5 MB']);
        $this->assertNothingStored($order);

        $this->upload($order, $this->fileFrom($pad(5120), 'big.jpg'))->assertOk();
        $this->assertNotNull($order->fresh()->gcash_proof_path);
    }

    /** @return array<string, array{string, string}> */
    public static function filesThatAreNotAcceptedImages(): array
    {
        return [
            'text renamed .jpg'  => ['just some text, honest', 'receipt.jpg'],
            'html renamed .png'  => ['<html><script>alert(1)</script></html>', 'receipt.png'],
            'pdf renamed .jpg'   => ["%PDF-1.4\n1 0 obj\n<<>>\nendobj\n", 'receipt.jpg'],
            'svg'                => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'receipt.svg'],
            'php renamed .webp'  => ['<?php echo 1;', 'receipt.webp'],
        ];
    }

    /** @dataProvider filesThatAreNotAcceptedImages */
    public function test_a_file_that_is_not_an_accepted_image_is_refused_whatever_it_is_called(string $bytes, string $name): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        // The exact wording: the `mimes` rule refuses these before any decoding.
        $this->upload($order, $this->fileFrom($bytes, $name))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['screenshot' => 'Please attach a JPG, PNG or WEBP image.']);

        $this->assertNothingStored($order);
    }

    public function test_a_gif_is_refused_even_though_it_is_a_real_image(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $image = imagecreatetruecolor(5, 5);
        ob_start();
        imagegif($image);

        $this->upload($order, $this->fileFrom(ob_get_clean(), 'receipt.png'))->assertStatus(422);

        $this->assertNothingStored($order);
    }

    /** @return array<string, array{string}> */
    public static function filesThatOnlyStartLikeImages(): array
    {
        return [
            'jpeg magic then html' => ["\xFF\xD8\xFF\xE0<html><script>alert(1)</script></html>"],
            'png magic then junk'  => ["\x89PNG\r\n\x1A\n" . str_repeat('junk', 50)],
            'webp magic then junk' => ['RIFF' . pack('V', 100) . 'WEBPVP8 ' . str_repeat('x', 100)],
        ];
    }

    /**
     * These get past the `mimes` rule, which is why re-encoding is the real check.
     *
     * The WEBP here is refused by the pixel ceiling, its junk reading as huge
     * dimensions. One with believable dimensions reaches the decoder, which is a
     * fatal error that would end this test process: see the next test.
     *
     * @dataProvider filesThatOnlyStartLikeImages
     */
    public function test_a_file_that_only_starts_like_an_image_is_refused(string $bytes): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->upload($order, $this->fileFrom($bytes, 'receipt.jpg'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['screenshot']);

        $this->assertNothingStored($order);
    }

    /**
     * What the shutdown handler does with GD's fatal error on a corrupt WEBP.
     * The fatal itself cannot happen in-process here, so this passes the
     * exception to the handler the same way HandleExceptions::renderHttpResponse()
     * does. The real thing was checked against a running server in a browser.
     */
    public function test_gds_fatal_on_a_corrupt_webp_is_answered_as_a_refusal_on_this_route_only(): void
    {
        $fatal = fn (string $message) => new \Symfony\Component\ErrorHandler\Error\FatalError(
            $message, 0, ['type' => E_ERROR, 'file' => __FILE__, 'line' => __LINE__, 'message' => $message], 0
        );

        $render = function (string $uri, string $message) use ($fatal) {
            $request = \Illuminate\Http\Request::create($uri, 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
            $route = $this->app['router']->getRoutes()->match($request);
            $request->setRouteResolver(fn () => $route);

            return $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render($request, $fatal($message));
        };

        $store = route('customer.gcash-payment.proof.store', 1, false);

        $response = $render($store, 'gd-webp cannot get webp info');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(GcashProof::UNREADABLE_MESSAGE, $response->getData(true)['errors']['screenshot'][0]);

        // Any other fatal, or the same one anywhere else, stays a fatal.
        $this->assertSame(500, $render($store, 'Allowed memory size exhausted')->getStatusCode());
        $this->assertSame(500, $render(route('customer.place-order', [], false), 'gd-webp cannot get webp info')->getStatusCode());
    }

    public function test_a_truncated_png_is_refused(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $image = imagecreatetruecolor(200, 200);
        for ($i = 0; $i < 200; $i++) {
            imageline($image, 0, $i, 199, 199 - $i, imagecolorallocate($image, $i, 255 - $i, $i % 7));
        }
        ob_start();
        imagepng($image);
        $whole = ob_get_clean();

        $this->upload($order, $this->fileFrom(substr($whole, 0, (int) (strlen($whole) * 0.6)), 'receipt.png'))
            ->assertStatus(422);

        $this->assertNothingStored($order);
    }

    /**
     * libjpeg recovers a truncated JPEG (gd.jpeg_ignore_warning, on by default),
     * so it is accepted. What matters is what gets stored: a complete new JPEG.
     */
    public function test_a_truncated_jpeg_is_stored_as_a_complete_new_file(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $whole = $this->jpegBytes(200, 200);

        $this->upload($order, $this->fileFrom(substr($whole, 0, (int) (strlen($whole) * 0.6)), 'receipt.jpg'))
            ->assertOk();

        $stored = Storage::disk('local')->get($order->fresh()->gcash_proof_path);

        $this->assertSame("\xFF\xD9", substr($stored, -2));
        $this->assertNotFalse(imagecreatefromstring($stored));
    }

    public function test_a_payload_inside_a_real_jpeg_does_not_survive_and_its_name_is_ignored(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $payload = '<script>alert(document.domain)</script>';
        $comment = "\xFF\xFE" . pack('n', strlen($payload) + 2) . $payload;
        $bytes = $this->jpegBytes();
        $withComment = substr($bytes, 0, 2) . $comment . substr($bytes, 2);

        $this->assertNotFalse(getimagesizefromstring($withComment), 'setup: must still be a valid JPEG');

        $this->upload($order, $this->fileFrom($withComment, 'payload.html'))->assertOk();

        $path = $order->fresh()->gcash_proof_path;
        $this->assertMatchesRegularExpression(self::PROOF_PATTERN, $path);
        $this->assertStringNotContainsString('script', Storage::disk('local')->get($path));
    }

    public function test_an_image_too_large_to_decode_safely_is_refused_before_decoding(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->upload($order, $this->jpeg('huge.jpg', 5000, 5000))->assertStatus(422);

        $this->assertNothingStored($order);
    }

    public function test_a_large_image_is_scaled_down_keeping_its_shape(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->upload($order, $this->jpeg('tall.jpg', 1500, 3000))->assertOk();

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($order->fresh()->gcash_proof_path));

        $this->assertSame([1200, GcashProof::MAX_EDGE], [$width, $height]);
    }

    public function test_a_transparent_png_is_flattened_onto_white(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->upload($order, $this->png())->assertOk();

        $image = imagecreatefromstring(Storage::disk('local')->get($order->fresh()->gcash_proof_path));
        $pixel = imagecolorsforindex($image, imagecolorat($image, 15, 15));

        $this->assertGreaterThan(250, $pixel['red']);
        $this->assertGreaterThan(250, $pixel['green']);
        $this->assertGreaterThan(250, $pixel['blue']);
    }

    public function test_a_sideways_phone_photo_is_turned_upright_and_its_exif_is_dropped(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        // A minimal EXIF block: one tag, Orientation = 6 (rotate 90 clockwise).
        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
        $exif = "Exif\0\0" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
        $bytes = $this->jpegBytes(40, 20);

        $this->upload($order, $this->fileFrom(substr($bytes, 0, 2) . $app1 . substr($bytes, 2), 'photo.jpg'))->assertOk();

        $stored = Storage::disk('local')->get($order->fresh()->gcash_proof_path);
        [$width, $height] = getimagesizefromstring($stored);

        $this->assertSame([20, 40], [$width, $height]);
        $this->assertStringNotContainsString('Exif', $stored);
    }

    public function test_asking_to_attach_with_no_file_is_refused(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->upload($order, null)->assertStatus(422)->assertJsonValidationErrors(['screenshot']);
    }

    // ══════════════════ upload: who ══════════════════

    public function test_only_the_owner_can_upload_or_remove(): void
    {
        $owner = $this->user('customer');
        $stranger = $this->user('customer');
        $order = $this->order(['user_id' => $owner->id]);

        // Another customer, another guest session, and staff of the very branch:
        // none of them owns it, and none learns that it exists.
        $this->actingAs($stranger, 'customer')->upload($order, $this->jpeg())->assertNotFound();
        $this->assertNothingStored($order);

        $this->app['auth']->forgetGuards();
        $this->upload($order, $this->jpeg())->assertNotFound();
        $this->assertNothingStored($order);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user('staff', $this->branch), 'admin')->upload($order, $this->jpeg())->assertNotFound();
        $this->assertNothingStored($order);

        // Control: the same request from the owner is accepted.
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner, 'customer')->upload($order, $this->jpeg())->assertOk();
        $path = $order->fresh()->gcash_proof_path;
        $this->assertNotNull($path);

        $this->app['auth']->forgetGuards();
        $this->actingAs($stranger, 'customer')->removeProof($order)->assertNotFound();
        $this->assertSame($path, $order->fresh()->gcash_proof_path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_a_guest_cannot_upload_to_an_account_order_even_if_the_session_lists_it(): void
    {
        $order = $this->order(['user_id' => $this->user('customer')->id]);
        GuestOrders::remember($order->id);

        $this->upload($order, $this->jpeg())->assertNotFound();

        $this->assertNothingStored($order);
    }

    public function test_an_order_that_does_not_use_gcash_takes_no_screenshot(): void
    {
        $order = $this->order(['payment_method' => 'cash']);
        GuestOrders::remember($order->id);

        $this->upload($order, $this->jpeg())->assertNotFound();

        $this->assertNothingStored($order);
    }

    // ══════════════════ upload: when ══════════════════

    /** @return array<string, array{string, string}> */
    public static function closedStates(): array
    {
        return [
            'approved'                => ['pending', 'paid'],
            'approved and preparing'  => ['preparing', 'paid'],
            'rejected'                => ['cancelled', 'rejected'],
            'cancelled before paying' => ['cancelled', 'pending'],
            'cancelled after paying'  => ['cancelled', 'refund_pending'],
            'refunded'                => ['cancelled', 'refunded'],
        ];
    }

    /** @dataProvider closedStates */
    public function test_uploading_and_removing_are_closed_once_the_payment_is_decided(string $status, string $paymentStatus): void
    {
        [$order, $path] = $this->orderWithProof(['status' => $status, 'payment_status' => $paymentStatus]);
        GuestOrders::remember($order->id);

        $this->upload($order, $this->jpeg())
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => GcashProof::CLOSED_MESSAGE]);

        $this->removeProof($order)->assertStatus(409);

        $this->assertSame($path, $order->fresh()->gcash_proof_path);
        $this->assertSame([$path], Storage::disk('local')->allFiles());
    }

    public function test_real_approve_and_real_reject_close_it_and_keep_the_screenshot(): void
    {
        $staff = $this->user('staff', $this->branch);

        foreach (['approve', 'reject'] as $action) {
            [$order, $path] = $this->orderWithProof(['payment_status' => 'awaiting_verification']);
            GuestOrders::remember($order->id);

            $this->upload($order, $this->jpeg())->assertOk();
            $path = $order->fresh()->gcash_proof_path;

            $this->actingAs($staff, 'admin')->put(route('admin.orders.payment.' . $action, $order->id))->assertRedirect();

            $this->app['auth']->forgetGuards();
            $this->upload($order, $this->jpeg())->assertStatus(409);

            $this->assertSame($path, $order->fresh()->gcash_proof_path);
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_a_plain_form_post_on_a_closed_order_goes_to_orders_with_the_reason(): void
    {
        [$order] = $this->orderWithProof(['payment_status' => 'paid']);
        GuestOrders::remember($order->id);

        $this->post(route('customer.gcash-payment.proof.store', $order->id), ['screenshot' => $this->jpeg()])
            ->assertRedirect(route('customer.orders'))
            ->assertSessionHasErrors(['screenshot' => GcashProof::CLOSED_MESSAGE]);
    }

    /**
     * The order was open when the request read it and closed before the swap.
     * The re-check under the lock must win, and the new file must not be left
     * behind with nothing pointing at it.
     */
    public function test_an_upload_that_loses_the_race_with_approve_leaves_nothing_behind(): void
    {
        [$order, $path] = $this->orderWithProof(['payment_status' => 'awaiting_verification']);

        $stale = $order->fresh();

        Order::whereKey($order->id)->update(['payment_status' => 'paid']);

        $this->assertTrue(GcashProof::isOpen($stale), 'setup: the stale copy must still look open');
        $this->assertFalse(GcashProof::attach($stale, $this->jpegBytes()));

        $this->assertSame($path, $order->fresh()->gcash_proof_path);
        $this->assertSame([$path], Storage::disk('local')->allFiles());
    }

    // ══════════════════ replacing and removing ══════════════════

    public function test_replacing_deletes_the_old_file(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->upload($order, $this->jpeg())->assertOk();
        $first = $order->fresh()->gcash_proof_path;

        $this->upload($order, $this->png())->assertOk();
        $second = $order->fresh()->gcash_proof_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        $this->assertSame([$second], Storage::disk('local')->allFiles());
    }

    public function test_replacing_works_while_awaiting_verification_too(): void
    {
        [$order, $path] = $this->orderWithProof(['payment_status' => 'awaiting_verification']);
        GuestOrders::remember($order->id);

        $this->upload($order, $this->jpeg())->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertNotSame($path, $order->fresh()->gcash_proof_path);
    }

    public function test_removing_deletes_the_file_and_clears_the_order(): void
    {
        [$order, $path] = $this->orderWithProof();
        GuestOrders::remember($order->id);

        $this->removeProof($order)->assertOk()->assertJson(['success' => true, 'url' => null]);

        $fresh = $order->fresh();
        $this->assertNull($fresh->gcash_proof_path);
        $this->assertNull($fresh->gcash_proof_uploaded_at);
        Storage::disk('local')->assertMissing($path);
    }

    /** A replacement that cannot be written must not cost the customer the one they have. */
    public function test_a_disk_failure_says_so_plainly_and_keeps_the_current_screenshot(): void
    {
        [$order, $path] = $this->orderWithProof();
        GuestOrders::remember($order->id);

        $real = Storage::disk('local');
        $failing = \Mockery::mock($real)->makePartial();
        $failing->shouldReceive('put')->andReturn(false);
        Storage::set('local', $failing);

        $this->upload($order, $this->jpeg())
            ->assertStatus(500)
            ->assertJson(['success' => false, 'message' => 'We could not save your screenshot. Please try again.']);

        Storage::set('local', $real);

        $this->assertSame($path, $order->fresh()->gcash_proof_path);
        $this->assertSame([$path], Storage::disk('local')->allFiles());
    }

    // ══════════════════ rate limit ══════════════════

    public function test_uploads_are_rate_limited_per_customer(): void
    {
        $customer = $this->user('customer');
        $order = $this->order(['user_id' => $customer->id]);

        $this->actingAs($customer, 'customer');

        // An empty post is cheap and still counts: the throttle runs first.
        for ($i = 0; $i < RateLimitServiceProvider::GCASH_PROOF_PER_SESSION; $i++) {
            $this->upload($order, null)->assertStatus(422);
        }

        $this->upload($order, $this->jpeg())
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Rejected', '1');

        $this->assertNothingStored($order);
    }

    public function test_the_upload_counter_is_not_shared_with_i_have_paid(): void
    {
        $customer = $this->user('customer');
        $order = $this->order(['user_id' => $customer->id]);

        $this->actingAs($customer, 'customer');

        for ($i = 0; $i <= RateLimitServiceProvider::GCASH_PROOF_PER_SESSION; $i++) {
            $this->upload($order, null);
        }

        $this->post(route('customer.gcash-payment.paid', $order->id))
            ->assertRedirect(route('customer.gcash-payment', $order->id));

        $this->assertSame('awaiting_verification', $order->fresh()->payment_status);
    }

    // ══════════════════ viewing ══════════════════

    private function assertServesTheImage($response, string $path): void
    {
        $response->assertOk();

        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('local')->get($path), $response->getContent());
    }

    public function test_the_orders_own_customer_can_view_it(): void
    {
        $customer = $this->user('customer');
        [$order, $path] = $this->orderWithProof(['user_id' => $customer->id]);

        $this->assertServesTheImage(
            $this->actingAs($customer, 'customer')->get(route('gcash-proof.show', $order->id)),
            $path
        );
    }

    public function test_the_guests_own_session_can_view_it(): void
    {
        [$order, $path] = $this->orderWithProof();
        GuestOrders::remember($order->id);

        $this->assertServesTheImage($this->get(route('gcash-proof.show', $order->id)), $path);
    }

    /** @return array<string, array{string}> */
    public static function branchRoles(): array
    {
        return ['staff' => ['staff'], 'supervisor' => ['supervisor']];
    }

    /** @dataProvider branchRoles */
    public function test_staff_and_supervisor_see_their_own_branch_and_not_another(string $role): void
    {
        [$order, $path] = $this->orderWithProof();

        $this->assertServesTheImage(
            $this->actingAs($this->user($role, $this->branch), 'admin')->get(route('gcash-proof.show', $order->id)),
            $path
        );

        $this->app['auth']->forgetGuards();

        $this->actingAs($this->user($role, $this->otherBranch), 'admin')
            ->get(route('gcash-proof.show', $order->id))
            ->assertNotFound();
    }

    public function test_the_owner_can_view_any_branch(): void
    {
        [$order, $path] = $this->orderWithProof(['branch_id' => $this->otherBranch->id]);

        $this->assertServesTheImage(
            $this->actingAs($this->user('admin'), 'admin')->get(route('gcash-proof.show', $order->id)),
            $path
        );
    }

    public function test_another_customer_a_stranger_and_a_deactivated_account_are_refused(): void
    {
        $owner = $this->user('customer');
        [$order, $path] = $this->orderWithProof(['user_id' => $owner->id]);
        $url = route('gcash-proof.show', $order->id);

        $this->get($url)->assertNotFound();

        $this->actingAs($this->user('customer'), 'customer')->get($url)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user('staff', $this->branch, ['is_active' => false]), 'admin')->get($url)->assertNotFound();

        // Control: the same URL, for the person it belongs to.
        $this->app['auth']->forgetGuards();
        $this->assertServesTheImage($this->actingAs($owner, 'customer')->get($url), $path);
    }

    public function test_a_guest_session_cannot_see_someone_elses_guest_order(): void
    {
        [$mine] = $this->orderWithProof();
        [$theirs] = $this->orderWithProof();
        GuestOrders::remember($mine->id);

        $this->get(route('gcash-proof.show', $mine->id))->assertOk();
        $this->get(route('gcash-proof.show', $theirs->id))->assertNotFound();
    }

    /** One browser can hold both logins. Being staff elsewhere costs them nothing. */
    public function test_staff_at_another_branch_still_see_their_own_customer_order(): void
    {
        $person = $this->user('staff', $this->otherBranch);
        [$order, $path] = $this->orderWithProof(['user_id' => $person->id]);

        $this->assertServesTheImage(
            $this->actingAs($person, 'admin')->actingAs($person, 'customer')->get(route('gcash-proof.show', $order->id)),
            $path
        );
    }

    public function test_a_refusal_looks_exactly_like_no_such_order_and_no_screenshot(): void
    {
        [$order] = $this->orderWithProof();
        $noScreenshot = $this->order();

        $this->actingAs($this->user('admin'), 'admin');

        // The owner may see everything, so these two 404s are about the data.
        $this->get(route('gcash-proof.show', $noScreenshot->id))->assertNotFound();
        $this->get(route('gcash-proof.show', Order::max('id') + 1000))->assertNotFound();
        $this->get(route('gcash-proof.show', $order->id))->assertOk();
    }

    public function test_a_row_that_outlived_its_file_is_a_404_not_a_500(): void
    {
        [$order, $path] = $this->orderWithProof();
        Storage::disk('local')->delete($path);

        $this->actingAs($this->user('admin'), 'admin')
            ->get(route('gcash-proof.show', $order->id))
            ->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function tamperedPaths(): array
    {
        return [
            'traversal'     => ['../../.env'],
            'inside but up' => ['gcash_proofs/../.env'],
            'another file'  => ['discount_ids/abc.jpg'],
            'wrong name'    => ['gcash_proofs/receipt.jpg'],
        ];
    }

    /** @dataProvider tamperedPaths */
    public function test_a_tampered_path_is_neither_read_nor_deleted(string $tampered): void
    {
        Storage::disk('local')->put('.env', 'SECRET');
        Storage::disk('local')->put('discount_ids/abc.jpg', 'ID');

        $order = $this->order();
        $order->gcash_proof_path = $tampered;
        $order->save();
        GuestOrders::remember($order->id);

        $this->get(route('gcash-proof.show', $order->id))->assertNotFound();

        $this->removeProof($order)->assertOk();

        Storage::disk('local')->assertExists('.env');
        Storage::disk('local')->assertExists('discount_ids/abc.jpg');
    }

    public function test_the_stored_path_stays_out_of_urls_and_serialized_orders(): void
    {
        [$order, $path] = $this->orderWithProof();

        $this->assertStringNotContainsString(basename($path), $order->fresh()->gcashProofUrl());
        $this->assertArrayNotHasKey('gcash_proof_path', $order->fresh()->toArray());
    }

    public function test_the_columns_cannot_be_mass_assigned(): void
    {
        $order = $this->order();
        $order->fill(['gcash_proof_path' => 'gcash_proofs/x.jpg', 'gcash_proof_uploaded_at' => now()]);

        $this->assertNull($order->gcash_proof_path);
        $this->assertNull($order->gcash_proof_uploaded_at);
    }

    // ══════════════════ staff: the order board ══════════════════

    private function board(?Branch $branch = null): string
    {
        return $this->actingAs($this->user('staff', $branch ?? $this->branch), 'admin')
            ->get(route('admin.home'))
            ->assertOk()
            ->getContent();
    }

    public function test_a_card_with_a_screenshot_shows_view_receipt_under_the_total_above_approve_and_reject(): void
    {
        [$order, $path] = $this->orderWithProof(['payment_status' => 'awaiting_verification']);

        $html = $this->board();

        $total = strpos($html, 'pc-row total');
        $button = strpos($html, 'data-testid="gcash-proof-view"');
        $approve = strpos($html, 'Approve Payment');
        $reject = strpos($html, 'Reject Payment');

        $this->assertNotFalse($button, 'View Receipt must be on the card');
        $this->assertTrue($total < $button && $button < $approve && $approve < $reject);
        $this->assertStringContainsString('data-proof-url="' . e($order->fresh()->gcashProofUrl()) . '"', $html);
        $this->assertStringContainsString('data-order-number="' . $order->order_number . '"', $html);
        $this->assertStringNotContainsString('data-testid="gcash-proof-none"', $html);

        // The file's name never reaches the page.
        $this->assertStringNotContainsString(basename($path), $html);
    }

    public function test_a_card_without_a_screenshot_shows_the_note_and_keeps_approve_and_reject(): void
    {
        $this->order(['payment_status' => 'awaiting_verification']);

        $html = $this->board();

        $this->assertStringContainsString('No screenshot attached', $html);
        $this->assertStringContainsString('data-testid="gcash-proof-none"', $html);
        $this->assertStringNotContainsString('data-testid="gcash-proof-view"', $html);
        $this->assertStringContainsString('Approve Payment', $html);
        $this->assertStringContainsString('Reject Payment', $html);
    }

    public function test_a_cash_card_shows_neither(): void
    {
        $this->order(['payment_method' => 'cash', 'payment_status' => 'paid']);

        $html = $this->board();

        $this->assertStringContainsString('PROOF Line', $html, 'setup: the card must be on the board');
        $this->assertStringNotContainsString('data-testid="gcash-proof-view"', $html);
        $this->assertStringNotContainsString('data-testid="gcash-proof-none"', $html);
    }

    public function test_the_board_has_the_pop_up_outside_the_refreshed_region(): void
    {
        $this->order();

        $html = $this->board();

        $this->assertSame(1, substr_count($html, 'id="gpProofModal"'));
        $this->assertGreaterThan(strpos($html, '/#pc-live-region') ?: 0, strpos($html, 'id="gpProofModal"'));
    }

    public function test_another_branchs_card_is_not_on_the_board(): void
    {
        $this->orderWithProof(['branch_id' => $this->otherBranch->id]);

        $this->assertStringNotContainsString('data-testid="gcash-proof-view"', $this->board());
    }

    public function test_the_order_detail_pop_up_is_given_the_screenshot(): void
    {
        $withProof = $this->order([], 6);
        $withProof->gcash_proof_path = 'gcash_proofs/' . str_repeat('a', 40) . '.jpg';
        $withProof->save();

        $html = $this->board();

        $this->assertStringContainsString('id="pcOrderModalProof"', $html);
        $this->assertStringContainsString('class="pc-view-order"', $html);
        $this->assertStringContainsString('data-is-gcash="1"', $html);
        $this->assertStringContainsString('data-proof-url="' . e($withProof->fresh()->gcashProofUrl()) . '"', $html);
    }

    public function test_the_order_detail_pop_up_knows_when_there_is_none(): void
    {
        $this->order([], 6);

        $html = $this->board();

        $this->assertStringContainsString('data-is-gcash="1"', $html);
        $this->assertStringContainsString('data-proof-url=""', $html);
    }

    // ══════════════════ staff: completed orders ══════════════════

    public function test_completed_orders_shows_view_receipt_the_note_and_nothing_for_cash(): void
    {
        $staff = $this->user('staff', $this->branch);

        [$withProof] = $this->orderWithProof(['status' => 'completed', 'payment_status' => 'paid']);

        $html = $this->actingAs($staff, 'admin')->get(route('admin.completed-orders'))->assertOk()->getContent();

        $this->assertStringContainsString('data-proof-url="' . e($withProof->fresh()->gcashProofUrl()) . '"', $html);
        $this->assertSame(1, substr_count($html, 'id="gpProofModal"'));
        $this->assertStringNotContainsString('data-testid="gcash-proof-none"', $html);

        $this->order(['status' => 'completed', 'payment_status' => 'paid']);

        $html = $this->actingAs($staff, 'admin')->get(route('admin.completed-orders'))->getContent();
        $this->assertStringContainsString('data-testid="gcash-proof-none"', $html);
    }

    public function test_completed_orders_shows_neither_for_a_cash_order(): void
    {
        $this->order(['status' => 'completed', 'payment_method' => 'cash', 'payment_status' => 'paid']);

        $html = $this->actingAs($this->user('staff', $this->branch), 'admin')
            ->get(route('admin.completed-orders'))
            ->getContent();

        $this->assertStringContainsString('PROOF Line', $html, 'setup: the row must be on the page');
        $this->assertStringNotContainsString('data-testid="gcash-proof-view"', $html);
        $this->assertStringNotContainsString('data-testid="gcash-proof-none"', $html);
    }

    // ══════════════════ customer: the payment page ══════════════════

    public function test_the_page_offers_it_with_the_optional_note_before_and_after_i_have_paid(): void
    {
        foreach (['pending', 'awaiting_verification'] as $paymentStatus) {
            $order = $this->order(['payment_status' => $paymentStatus]);
            GuestOrders::remember($order->id);

            $this->get(route('customer.gcash-payment', $order->id))
                ->assertOk()
                ->assertSeeInOrder([
                    'Scan the QR code above',
                    'Attach your payment screenshot',
                    'helps staff verify your payment faster',
                    'Tap to upload',
                    'JPG, PNG or WEBP, up to 5 MB',
                ]);
        }
    }

    public function test_the_page_shows_the_saved_screenshot_with_replace_and_remove(): void
    {
        [$order] = $this->orderWithProof();
        GuestOrders::remember($order->id);

        $response = $this->get(route('customer.gcash-payment', $order->id))
            ->assertOk()
            ->assertSee('Tap to replace');

        $this->assertStringContainsString('src="' . e($order->fresh()->gcashProofUrl()) . '"', $response->getContent());
    }

    public function test_a_cancelled_order_page_does_not_offer_it(): void
    {
        $order = $this->order(['status' => 'cancelled']);
        GuestOrders::remember($order->id);

        $this->get(route('customer.gcash-payment', $order->id))
            ->assertOk()
            ->assertSee('This order was cancelled')
            ->assertDontSee('Attach your payment screenshot');
    }

    // ══════════════════ the GCash flow around it is unchanged ══════════════════

    public function test_i_have_paid_works_without_a_screenshot_and_staff_can_approve_it(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->post(route('customer.gcash-payment.paid', $order->id))
            ->assertRedirect(route('customer.gcash-payment', $order->id));
        $this->assertSame('awaiting_verification', $order->fresh()->payment_status);

        $this->actingAs($this->user('staff', $this->branch), 'admin')
            ->put(route('admin.orders.payment.approve', $order->id))
            ->assertRedirect(route('admin.home'));

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->gcash_proof_path);
    }

    public function test_attaching_before_i_have_paid_survives_it_and_staff_can_reject_it(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->upload($order, $this->jpeg())->assertOk();
        $path = $order->fresh()->gcash_proof_path;
        $this->assertSame('pending', $order->fresh()->payment_status, 'attaching is not the same as saying you paid');

        $this->post(route('customer.gcash-payment.paid', $order->id));

        $fresh = $order->fresh();
        $this->assertSame('awaiting_verification', $fresh->payment_status);
        $this->assertSame($path, $fresh->gcash_proof_path);

        $this->actingAs($this->user('staff', $this->branch), 'admin')
            ->put(route('admin.orders.payment.reject', $order->id))
            ->assertRedirect(route('admin.home'));

        $fresh = $order->fresh();
        $this->assertSame('rejected', $fresh->payment_status);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame($path, $fresh->gcash_proof_path);
    }

    public function test_attaching_after_i_have_paid_is_saved_and_the_status_is_untouched(): void
    {
        $order = $this->order();
        GuestOrders::remember($order->id);

        $this->post(route('customer.gcash-payment.paid', $order->id));
        $this->upload($order, $this->jpeg())->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('awaiting_verification', $fresh->payment_status);
        $this->assertNotNull($fresh->gcash_proof_path);
    }

    public function test_a_refund_after_a_cancel_keeps_working_and_keeps_the_screenshot(): void
    {
        [$order, $path] = $this->orderWithProof(['payment_status' => 'awaiting_verification']);

        $this->actingAs($this->user('staff', $this->branch), 'admin')
            ->put(route('admin.orders.cancel', $order->id))
            ->assertRedirect();

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('refund_pending', $fresh->payment_status);
        $this->assertSame($path, $fresh->gcash_proof_path);
        Storage::disk('local')->assertExists($path);
    }
}
