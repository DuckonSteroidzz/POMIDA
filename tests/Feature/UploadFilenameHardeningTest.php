<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Image uploads: the STORED filename must not be attacker-chosen.
 *
 * THE HOLE THIS PINS
 * ------------------
 * Every upload path in this app validated the file's *content* correctly
 * (`image|mimes:jpeg,png,jpg,webp|max:2048`) and then built the stored
 * filename out of `getClientOriginalName()`, sanitised with
 * `preg_replace('/[^A-Za-z0-9\.]/', '_', ...)`. That sanitiser keeps letters,
 * digits and DOTS — so it keeps the attacker's EXTENSION.
 *
 * Content validation does not save you here, because Laravel's `mimes` rule
 * compares the extension it GUESSES from the MIME type, not the one the client
 * sent. A genuine JPEG called `payload.html` therefore passes `image` and
 * `mimes:jpeg,...` (guessed extension `jpg`), and Laravel's own
 * `shouldBlockPhpUpload()` only rejects php/phtml/phar — not `.html`, `.svg`
 * or `.xhtml`.
 *
 * The file then lands in `public/uploads/...`, which is served directly by the
 * web server BY EXTENSION. `1234_payload.html` is handed to the browser as
 * text/html, so any markup carried inside those JPEG bytes executes on the
 * application's own origin. The resize step does not scrub it either:
 * Intervention picks its encoder from the extension, has none for `.html`,
 * throws, and `resizeMenuImage()`'s catch deliberately keeps the ORIGINAL
 * bytes ("storing original upload as-is").
 *
 * WHAT AN ATTACKER GETS
 * ---------------------
 * Menu-item and advertisement uploads are `role:admin,supervisor`, so a
 * Supervisor — not the Owner — can plant a same-origin HTML/JS page and send
 * its URL to the Owner. Session cookies are HttpOnly, but same-origin script
 * can read a page, lift its CSRF token and drive any owner-only endpoint as
 * the Owner. That is privilege escalation, not just a defaced page.
 *
 * THE FIX
 * -------
 * App\Support\UploadedImageName::for() builds the stored name from a random
 * token plus an extension derived from the VALIDATED IMAGE CONTENT, ignoring
 * the client's name entirely. Nothing attacker-supplied reaches the filesystem.
 */
class UploadFilenameHardeningTest extends TestCase
{
    use DatabaseTransactions;

    /** Absolute paths this test created, removed in tearDown whatever happens. */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'pomida_db_testing',
            DB::selectOne('select database() as d')->d,
            'Upload hardening tests must only ever run against pomida_db_testing.'
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * A real JPEG whose bytes also contain an HTML/JS payload.
     *
     * The markup goes in a JPEG COM (comment) segment, so the file is a
     * structurally valid image — getimagesize() reads it, which is what the
     * `image` rule uses — while still containing the script a browser would
     * run if the file were ever served as text/html.
     */
    private function jpegCarryingScript(string $name): UploadedFile
    {
        $payload = '<script>fetch("/admin/users")</script>';

        // Smallest valid JPEG, with a COM segment holding the payload.
        $com = "\xFF\xFE" . pack('n', strlen($payload) + 2) . $payload;

        $jpeg = "\xFF\xD8"                                   // SOI
            . $com                                           // COM (our payload)
            . "\xFF\xDB\x00\x43\x00" . str_repeat("\x08", 64) // DQT
            . "\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00" // SOF0 1x1
            . "\xFF\xC4\x00\x14\x00\x01" . str_repeat("\x00", 15) . "\x00" // DHT
            . "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00"      // SOS
            . "\xFF\xD9";                                     // EOI

        /*
         * The temp file's OWN name is deliberately neutral — Windows refuses
         * names ending in a dot, which would make the `takeover.html.` case
         * error out before it ever reached the endpoint. The hostile name is
         * what we hand UploadedFile as the CLIENT name below, which is the
         * only one the controller reads.
         */
        $tmp = tempnam(sys_get_temp_dir(), 'pomida_upl');
        file_put_contents($tmp, $jpeg);
        $this->created[] = $tmp;

        // A real temp file, NOT UploadedFile::fake() — the point of this test is
        // what the validator and the filesystem do with genuine image bytes
        // carrying a hostile client filename.
        return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
    }

    private function supervisor(Branch $branch): User
    {
        return User::create([
            'name'              => 'Upl Supervisor ' . uniqid(),
            'email'             => 'upl_sup_' . uniqid() . '@example.test',
            'password'          => 'Sup3rvisor!23',
            'role'              => 'supervisor',
            'branch_id'         => $branch->id,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function branch(): Branch
    {
        return Branch::create([
            'name'      => 'Upl Branch ' . uniqid(),
            'code'      => 'UPL' . strtoupper(substr(uniqid(), -7)),
            'address'   => 'Upload test address',
            'is_active' => true,
        ]);
    }

    private function category(): Category
    {
        return Category::create([
            'name'      => 'Upl Category ' . uniqid(),
            'is_active' => true,
        ]);
    }

    /** Every file currently sitting in a public upload directory. */
    private function snapshotDir(string $relative): array
    {
        $dir = public_path($relative);

        return is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];
    }

    private function newFilesIn(string $relative, array $before): array
    {
        return array_values(array_diff($this->snapshotDir($relative), $before));
    }

    // ══════════ The core guarantee ══════════

    /**
     * The headline case: a genuine JPEG named `payload.html`.
     *
     * Before the fix this stored `<time>_payload.html` in public/ verbatim,
     * script bytes intact. It must now store a name with a real image
     * extension and nothing of the attacker's choosing in it.
     */
    public function test_a_valid_jpeg_named_dot_html_is_not_stored_with_an_html_extension(): void
    {
        $branch = $this->branch();
        $category = $this->category();
        $before = $this->snapshotDir('uploads/menu-items');

        $response = $this->actingAs($this->supervisor($branch), 'admin')
            ->post(route('admin.new-menu-item.post'), [
                'category_id' => $category->id,
                'name'        => 'Upl Item ' . uniqid(),
                'price'       => 120,
                'branch_id'   => $branch->id,
                'image'       => $this->jpegCarryingScript('payload.html'),
            ]);

        $new = $this->newFilesIn('uploads/menu-items', $before);
        foreach ($new as $f) {
            $this->created[] = public_path('uploads/menu-items/' . $f);
        }

        $response->assertSessionHasNoErrors();

        // Exactly one file was written, and it is NOT servable as HTML.
        $this->assertCount(1, $new, 'The upload should have written exactly one file.');

        $stored = $new[0];
        $this->assertStringEndsNotWith('.html', $stored, "Stored as '$stored' — a browser would run this as HTML.");
        $this->assertSame(
            'jpg',
            strtolower(pathinfo($stored, PATHINFO_EXTENSION)),
            "Stored as '$stored'; the extension must come from the image content, not the client filename."
        );

        // And nothing the attacker chose survives in the name.
        $this->assertStringNotContainsString('payload', $stored);
    }

    /**
     * The whole class of dangerous extensions, not just .html.
     *
     * .svg and .xhtml are script-capable when served as documents; .phtml and
     * .phar are executable on a stock XAMPP/Apache host. Laravel's own
     * shouldBlockPhpUpload() covers the php* family, so those are expected to
     * be refused outright — either outcome (refused, or stored safely) is
     * acceptable; what is NOT acceptable is a stored file that keeps the
     * dangerous extension.
     *
     * @dataProvider dangerousNames
     */
    public function test_no_dangerous_extension_ever_reaches_the_public_directory(string $clientName): void
    {
        $branch = $this->branch();
        $category = $this->category();
        $before = $this->snapshotDir('uploads/menu-items');

        $this->actingAs($this->supervisor($branch), 'admin')
            ->post(route('admin.new-menu-item.post'), [
                'category_id' => $category->id,
                'name'        => 'Upl Item ' . uniqid(),
                'price'       => 120,
                'branch_id'   => $branch->id,
                'image'       => $this->jpegCarryingScript($clientName),
            ]);

        $new = $this->newFilesIn('uploads/menu-items', $before);
        foreach ($new as $f) {
            $this->created[] = public_path('uploads/menu-items/' . $f);
        }

        // Refusing the upload entirely is a perfectly good answer.
        if ($new === []) {
            $this->assertTrue(true, 'Upload refused outright, which is safe.');

            return;
        }

        foreach ($new as $stored) {
            $this->assertContains(
                strtolower(pathinfo($stored, PATHINFO_EXTENSION)),
                ['jpg', 'jpeg', 'png', 'webp'],
                "Client sent '$clientName' and the server stored '$stored'."
            );
        }
    }

    public static function dangerousNames(): array
    {
        return [
            'html'        => ['takeover.html'],
            'htm'         => ['takeover.htm'],
            'xhtml'       => ['takeover.xhtml'],
            'svg'         => ['takeover.svg'],
            'phtml'       => ['takeover.phtml'],
            'phar'        => ['takeover.phar'],
            'double ext'  => ['takeover.jpg.html'],
            'trailing dot' => ['takeover.html.'],
        ];
    }

    /**
     * Path traversal, kept as a standing guarantee.
     *
     * The old sanitiser already turned separators into underscores, so this was
     * not exploitable before either — but the fix replaced that sanitiser
     * wholesale, so the property needs its own pin so a future rewrite cannot
     * quietly lose it.
     */
    public function test_a_traversing_filename_cannot_escape_the_upload_directory(): void
    {
        $branch = $this->branch();
        $category = $this->category();
        $before = $this->snapshotDir('uploads/menu-items');

        $this->actingAs($this->supervisor($branch), 'admin')
            ->post(route('admin.new-menu-item.post'), [
                'category_id' => $category->id,
                'name'        => 'Upl Item ' . uniqid(),
                'price'       => 120,
                'branch_id'   => $branch->id,
                'image'       => $this->jpegCarryingScript('../../../../evil.jpg'),
            ]);

        $new = $this->newFilesIn('uploads/menu-items', $before);
        foreach ($new as $f) {
            $this->created[] = public_path('uploads/menu-items/' . $f);
        }

        $this->assertCount(1, $new);
        $this->assertStringNotContainsString('..', $new[0]);
        $this->assertFileDoesNotExist(public_path('../evil.jpg'));
        $this->assertFileDoesNotExist(base_path('evil.jpg'));
    }

    /**
     * Two uploads of the SAME client filename in the same second must not
     * collide.
     *
     * The old name was `time() . '_' . $clientName`, which is second-granular:
     * two uploads of `logo.jpg` inside one second produced the same path and
     * the second silently OVERWROTE the first. That is an integrity bug with a
     * security edge — one branch's supervisor could replace another's image —
     * so the replacement name has to be collision-free by construction.
     */
    public function test_two_uploads_of_the_same_filename_do_not_overwrite_each_other(): void
    {
        $branch = $this->branch();
        $category = $this->category();
        $supervisor = $this->supervisor($branch);
        $before = $this->snapshotDir('uploads/menu-items');

        foreach ([1, 2] as $n) {
            $this->actingAs($supervisor, 'admin')
                ->post(route('admin.new-menu-item.post'), [
                    'category_id' => $category->id,
                    'name'        => 'Upl Item ' . $n . ' ' . uniqid(),
                    'price'       => 120,
                    'branch_id'   => $branch->id,
                    'image'       => $this->jpegCarryingScript('logo.jpg'),
                ]);
        }

        $new = $this->newFilesIn('uploads/menu-items', $before);
        foreach ($new as $f) {
            $this->created[] = public_path('uploads/menu-items/' . $f);
        }

        $this->assertCount(2, $new, 'Two uploads of the same client filename must produce two distinct files.');
    }

    /** The advertisement upload is the same code shape, so it gets the same pin. */
    public function test_the_advertisement_upload_is_hardened_too(): void
    {
        $branch = $this->branch();
        $before = $this->snapshotDir('uploads/ads');

        $response = $this->actingAs($this->supervisor($branch), 'admin')
            ->post(route('admin.ads.store'), [
                'title'     => 'Upl Ad ' . uniqid(),
                'placement' => 'menu',
                'branch_id' => $branch->id,
                'image'     => $this->jpegCarryingScript('ad-takeover.html'),
            ]);

        $new = $this->newFilesIn('uploads/ads', $before);
        foreach ($new as $f) {
            $this->created[] = public_path('uploads/ads/' . $f);
        }

        /*
         * Assert the upload actually happened before judging its name —
         * without this the test passes vacuously whenever an unrelated
         * validation rule rejects the request, which is exactly how it hid
         * the ads hole on its first run.
         */
        $response->assertSessionHasNoErrors();
        $this->assertCount(1, $new, 'The advertisement upload should have written exactly one file.');

        foreach ($new as $stored) {
            $this->assertContains(
                strtolower(pathinfo($stored, PATHINFO_EXTENSION)),
                ['jpg', 'jpeg', 'png', 'webp'],
                "Advertisement upload stored '$stored'."
            );
        }
    }
}
