<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\AdminSignIn;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use App\Support\BranchGcash;
use App\Support\PasswordPolicy;
use App\Support\SignInLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Account Settings for Admin, Supervisor and Staff (Defect Report 5, item 3 —
 * Oct 2026; design: docs/design-refs/admin-account-settings-all-roles.html).
 *
 * Replaces AdminAccountSettingsRedesignTest, which pinned the previous,
 * owner-only design. What this pins:
 *
 *  - what each role's page shows (and does not): staff get no GCash card and
 *    no "Managing staff accounts?" box; a supervisor's GCash is read-only and
 *    always their own branch; the owner edits the Viewing-bar branch;
 *  - changing your own password works for all three roles, with the same
 *    rules as before, throttled, and keeps you signed in;
 *  - saving GCash is refused server-side for anyone but the owner;
 *  - sign-ins are recorded at login, pruned to 20 per user, and each user
 *    sees only their own, three per page;
 *  - the design's sample people, devices and cities never reach the page.
 */
class AccountSettingsAllRolesTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Old!Passw0rd123';
    private const NEW_PASSWORD = 'New!Passw0rd456';

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    /** Words from the design's placeholder data that must never be rendered. */
    private const PLACEHOLDERS = ['Janelle', 'janelle', 'peachycafe.ph', 'Quezon City', 'Makati', 'Cebu City', 'Pasig', 'Galaxy Tab', 'MacBook Air', 'e.g.'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ══════════ helpers ══════════

    private function branch(?string $name = null): Branch
    {
        return Branch::create([
            'name' => $name ?? 'ACS Branch ' . uniqid(),
            'code' => 'ACS' . strtoupper(substr(uniqid(), -6)),
            'address' => 'ACS test address',
            'is_active' => true,
        ]);
    }

    private function user(string $role, ?int $branchId = null, string $name = 'Rowena Dimaculangan'): User
    {
        return User::create([
            'name' => $name,
            'email' => 'acs-' . $role . '-' . uniqid() . '@invalid.local',
            'password' => self::PASSWORD,
            'role' => $role,
            'is_active' => true,
            'branch_id' => $role === 'admin' ? $branchId : ($branchId ?? $this->branch()->id),
        ]);
    }

    private function setGcash(Branch $branch, string $enabled, ?string $qr, ?string $number): void
    {
        foreach (['gcash_enabled' => $enabled, 'gcash_qr' => $qr, 'gcash_phone' => $number] as $key => $value) {
            Setting::updateOrCreate(
                ['branch_id' => $branch->id, 'key' => $key],
                ['value' => $value ?? '', 'group' => 'payment', 'label' => $key, 'type' => 'text']
            );
        }
    }

    private function page(User $user, string $query = ''): string
    {
        return $this->actingAs($user, 'admin')->get('/admin/account' . $query)->assertOk()->getContent();
    }

    private function signIn(User $user, array $attributes = []): AdminSignIn
    {
        return AdminSignIn::create(array_merge([
            'user_id' => $user->id,
            'signed_in_at' => now(),
            'browser' => 'Chrome',
            'os' => 'Windows',
            'device_type' => 'desktop',
            'ip_address' => '112.204.31.7',
        ], $attributes));
    }

    /** The slice of the page from one card's id to the next card's id. */
    private function card(string $html, string $id): string
    {
        $start = strpos($html, 'id="' . $id . '"');
        $this->assertNotFalse($start, "card #$id is missing");
        $next = strpos($html, 'class="acs-card"', $start + 10);

        return substr($html, $start, $next === false ? null : $next - $start);
    }

    public static function roles(): array
    {
        return ['admin' => ['admin'], 'supervisor' => ['supervisor'], 'staff' => ['staff']];
    }

    // ══════════ 1. what each role sees ══════════

    /** @dataProvider roles */
    public function test_every_role_opens_their_own_page_with_their_real_details(string $role): void
    {
        $branch = $this->branch('ACS Real Branch ' . uniqid());
        $user = $this->user($role, $branch->id, 'Rowena Dimaculangan');
        $other = $this->user($role, $branch->id, 'Other Person Elsewhere');

        $html = $this->page($user);

        // Header
        $this->assertStringContainsString('Administration', $html);
        $this->assertStringContainsString('<h1>Account Settings</h1>', $html);
        $this->assertStringContainsString('href="' . route('admin.home') . '"', $html);
        $this->assertStringContainsString('href="#change-password"', $html);

        // Banner + Account Details: the signed-in user only
        $this->assertStringContainsString('Rowena Dimaculangan', $html);
        $this->assertStringContainsString($user->email, $html);
        $this->assertStringContainsString('>RD<span class="acs-online-dot"', $html);
        $this->assertStringNotContainsString($other->email, $html);
        $this->assertStringNotContainsString('Other Person Elsewhere', $html);

        $label = ['admin' => 'Administrator', 'supervisor' => 'Supervisor', 'staff' => 'Staff'][$role];
        $this->assertMatchesRegularExpression('/data-profile-role>.*?' . $label . '/s', $html);
        $this->assertMatchesRegularExpression('/id="acc_role"[^>]*value="' . $label . '"/', $html);
        $this->assertStringContainsString('Read-only', $html);
        foreach (['acc_name', 'acc_email', 'acc_role'] as $id) {
            $this->assertMatchesRegularExpression('/id="' . $id . '"[^>]*\breadonly\b/', $html, "$id must be read-only");
        }

        // Assigned Branch for the branch-locked roles only
        if ($role === 'admin') {
            $this->assertStringNotContainsString('id="acc_branch"', $html);
        } else {
            $this->assertMatchesRegularExpression('/id="acc_branch"[^>]*value="' . preg_quote($branch->name, '/') . '"/', $html);
        }

        // Change Password + Recent Sign-ins for everyone
        $this->assertStringContainsString('id="change-password"', $html);
        $this->assertStringContainsString('id="recent-sign-ins"', $html);
        $this->assertStringContainsString('Recent Sign-ins', $html);

        // Sidebar link for every role
        $this->assertMatchesRegularExpression('/href="' . preg_quote(route('admin.account'), '/') . '"\s+class="nav-link-item active"/', $html);
    }

    /** @dataProvider roles */
    public function test_no_placeholder_names_devices_or_cities_are_rendered(string $role): void
    {
        $user = $this->user($role);
        $this->signIn($user);

        $html = $this->page($user);

        foreach (self::PLACEHOLDERS as $word) {
            $this->assertStringNotContainsString($word, $html, "placeholder '$word' leaked onto the $role page");
        }
    }

    public function test_only_roles_that_can_open_staff_accounts_get_the_managing_staff_box(): void
    {
        foreach (['admin' => true, 'supervisor' => true, 'staff' => false] as $role => $shown) {
            $html = $this->page($this->user($role));

            if ($shown) {
                $this->assertStringContainsString('Managing staff accounts?', $html, $role);
                $this->assertStringContainsString('Go to Staff Accounts', $html, $role);
                $this->assertMatchesRegularExpression('/data-staff-accounts-link>.*?href="' . preg_quote(route('admin.users'), '/') . '"/s', $html, $role);
            } else {
                $this->assertStringNotContainsString('Managing staff accounts?', $html, $role);
                $this->assertStringNotContainsString('href="' . route('admin.users') . '"', $html, $role);
            }
        }
    }

    public function test_staff_get_no_gcash_card_at_all(): void
    {
        $branch = $this->branch();
        $this->setGcash($branch, '1', 'settings/gcash/acs-staff.png', '09175550000');

        $html = $this->page($this->user('staff', $branch->id));

        $this->assertStringNotContainsString('id="gcash-settings"', $html);
        $this->assertStringNotContainsString('GCash Payment', $html);
        $this->assertStringNotContainsString('acs-staff.png', $html);
        $this->assertStringNotContainsString('555 0000', $html);
        $this->assertStringNotContainsString(route('admin.account.gcash-qr.update'), $html);
    }

    public function test_a_supervisor_sees_their_own_branch_gcash_read_only(): void
    {
        $own = $this->branch('ACS Supervisor Own ' . uniqid());
        $this->setGcash($own, '1', 'settings/gcash/acs-own.png', '09171112222');
        $supervisor = $this->user('supervisor', $own->id);

        $card = $this->card($this->page($supervisor), 'gcash-settings');

        $this->assertStringContainsString('data-gcash-mode="view"', $card);
        $this->assertStringContainsString($own->name, $card);
        $this->assertStringContainsString('acs-own.png', $card);
        $this->assertStringContainsString('+63 917 111 2222', $card);
        $this->assertStringContainsString('GCash On', $card);
        $this->assertStringContainsString('Only the owner can change GCash details.', $card);

        // No way to change anything: no form, no inputs, no save button.
        $this->assertStringNotContainsString('<form', $card);
        $this->assertStringNotContainsString('<input', $card);
        $this->assertStringNotContainsString('Save GCash Details', $card);
        $this->assertStringNotContainsString(route('admin.account.gcash-qr.update'), $card);
    }

    public function test_a_supervisor_is_locked_to_their_own_branch_even_with_a_crafted_branch(): void
    {
        $own = $this->branch('ACS Locked Own ' . uniqid());
        $other = $this->branch('ACS Locked Other ' . uniqid());
        $this->setGcash($own, '0', null, '09171230000');
        $this->setGcash($other, '1', 'settings/gcash/acs-other.png', '09179990000');
        $supervisor = $this->user('supervisor', $own->id);

        $html = $this->actingAs($supervisor, 'admin')
            ->withSession(['selected_branch_id' => $other->id])
            ->get('/admin/account?branch=' . $other->id)
            ->assertOk()
            ->getContent();

        $card = $this->card($html, 'gcash-settings');
        $this->assertStringContainsString($own->name, $card);
        $this->assertStringContainsString('+63 917 123 0000', $card);
        $this->assertStringContainsString('GCash Off', $card);
        $this->assertStringNotContainsString($other->name, $html);
        $this->assertStringNotContainsString('acs-other.png', $html);
        $this->assertStringNotContainsString('999 0000', $html);

        // The Viewing bar shows their branch, locked: no picker.
        $this->assertMatchesRegularExpression('/Viewing:\s*<span class="value">\s*' . preg_quote($own->name, '/') . '/', $html);
        $this->assertStringNotContainsString('data-pc-branch-select', $html);
    }

    public function test_staff_see_their_branch_in_a_locked_viewing_bar(): void
    {
        $own = $this->branch('ACS Staff Own ' . uniqid());
        $other = $this->branch('ACS Staff Other ' . uniqid());
        $staff = $this->user('staff', $own->id);

        $html = $this->page($staff, '?branch=' . $other->id);

        $this->assertMatchesRegularExpression('/Viewing:\s*<span class="value">\s*' . preg_quote($own->name, '/') . '/', $html);
        $this->assertStringNotContainsString('data-pc-branch-select', $html);
        $this->assertStringNotContainsString($other->name, $html);
    }

    public function test_the_owner_gets_the_editable_gcash_card_from_the_design(): void
    {
        $branch = $this->branch('ACS Owner Edit ' . uniqid());
        $this->setGcash($branch, '1', 'settings/gcash/acs-edit.png', '09174445555');

        $html = $this->actingAs($this->user('admin'), 'admin')
            ->withSession(['selected_branch_id' => $branch->id])
            ->get('/admin/account')
            ->assertOk()
            ->getContent();
        $card = $this->card($html, 'gcash-settings');

        $this->assertStringContainsString('data-gcash-mode="edit"', $card);
        $this->assertStringContainsString('action="' . route('admin.account.gcash-qr.update') . '"', $card);
        $this->assertStringContainsString('enctype="multipart/form-data"', $card);
        $this->assertStringContainsString('name="branch_id" value="' . $branch->id . '"', $card);
        $this->assertStringContainsString('QR at checkout', $card);
        $this->assertStringContainsString('● QR ready', $card);
        $this->assertStringContainsString('acs-edit.png', $card);
        $this->assertStringContainsString('<span class="acs-num-prefix">+63</span>', $card);
        $this->assertStringContainsString('placeholder="912 345 6789"', $card);
        $this->assertStringContainsString('value="917 444 5555"', $card);
        $this->assertStringContainsString('name="gcash_qr"', $card);
        $this->assertStringContainsString('PNG, JPG, WEBP · up to 5 MB', $card);
        $this->assertStringContainsString('Save GCash Details', $card);
        $this->assertStringNotContainsString('e.g.', $html);

        // The Viewing bar is the owner's picker, and this page drives it.
        $this->assertStringContainsString('data-pc-branch-select', $html);
        $this->assertStringContainsString('window.pcBranchSelectHandler', $html);
    }

    public function test_the_owner_with_all_branches_selected_cannot_save(): void
    {
        $html = $this->actingAs($this->user('admin'), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get('/admin/account')
            ->assertOk()
            ->getContent();
        $card = $this->card($html, 'gcash-settings');

        $this->assertStringContainsString('Select a branch to manage its GCash', $card);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*disabled/', $card);
        $this->assertStringNotContainsString('name="branch_id"', $card);
    }

    public function test_the_owner_switches_the_gcash_branch_with_the_viewing_bar(): void
    {
        $a = $this->branch('ACS Switch A ' . uniqid());
        $b = $this->branch('ACS Switch B ' . uniqid());
        $this->setGcash($a, '0', null, '09170000001');
        $this->setGcash($b, '0', null, '09170000002');
        $owner = $this->user('admin');

        $html = $this->actingAs($owner, 'admin')
            ->withSession(['selected_branch_id' => $a->id])
            ->get('/admin/account?branch=' . $b->id)
            ->assertOk()
            ->assertSessionHas('selected_branch_id', $b->id)
            ->getContent();

        $card = $this->card($html, 'gcash-settings');
        $this->assertStringContainsString($b->name, $card);
        $this->assertStringContainsString('value="917 000 0002"', $card);
        $this->assertStringNotContainsString('917 000 0001', $card);
        $this->assertMatchesRegularExpression('/Viewing:\s*<span class="value">\s*' . preg_quote($b->name, '/') . '/', $html);

        // "all" goes back to All Branches; a junk value changes nothing.
        $this->actingAs($owner, 'admin')->get('/admin/account?branch=all')->assertSessionHas('selected_branch_id', 'all');
        $this->actingAs($owner, 'admin')->withSession(['selected_branch_id' => $a->id])
            ->get('/admin/account?branch=nope')->assertSessionHas('selected_branch_id', $a->id);
    }

    // ══════════ 2. change your own password — all three roles ══════════

    /** @dataProvider roles */
    public function test_a_wrong_current_password_is_refused_in_the_password_card(string $role): void
    {
        $user = $this->user($role);

        $html = $this->actingAs($user, 'admin')->from('/admin/account')->followingRedirects()
            ->put('/admin/account/password', [
                'current_password' => 'Wrong!Passw0rd9',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertOk()->getContent();

        $this->assertStringContainsString('That is not your current password.', $this->card($html, 'change-password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    /** @dataProvider roles */
    public function test_a_mismatched_confirmation_is_refused(string $role): void
    {
        $user = $this->user($role);

        $html = $this->actingAs($user, 'admin')->from('/admin/account')->followingRedirects()
            ->put('/admin/account/password', [
                'current_password' => self::PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => 'Other!Passw0rd7',
            ])->assertOk()->getContent();

        $this->assertStringContainsString('The new password and its confirmation do not match.', $this->card($html, 'change-password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    /** @dataProvider roles */
    public function test_the_same_password_as_the_current_one_is_refused(string $role): void
    {
        $user = $this->user($role);

        $html = $this->actingAs($user, 'admin')->from('/admin/account')->followingRedirects()
            ->put('/admin/account/password', [
                'current_password' => self::PASSWORD,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])->assertOk()->getContent();

        $this->assertStringContainsString('The new password must be different from your current one.', $this->card($html, 'change-password'));
    }

    /** @dataProvider roles */
    public function test_the_password_policy_is_the_same_as_before(string $role): void
    {
        $user = $this->user($role);

        foreach (['Sh0rt!a', 'alllowercase1!', 'NoNumbers!!', 'NoSymbol123A', 'Way!Too!Long!Passw0rd123'] as $weak) {
            $this->actingAs($user, 'admin')->from('/admin/account')->put('/admin/account/password', [
                'current_password' => self::PASSWORD,
                'password' => $weak,
                'password_confirmation' => $weak,
            ])->assertSessionHasErrors('password');
        }

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->assertStringContainsString(PasswordPolicy::describe(), $this->page($user));
    }

    /** @dataProvider roles */
    public function test_a_correct_change_succeeds_and_keeps_the_user_signed_in(string $role): void
    {
        $user = $this->user($role);

        $html = $this->actingAs($user, 'admin')->from('/admin/account')->followingRedirects()
            ->put('/admin/account/password', [
                'current_password' => self::PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertOk()->getContent();

        $this->assertStringContainsString('Your password has been updated.', $this->card($html, 'change-password'));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertAuthenticatedAs($user->fresh(), 'admin');
    }

    public function test_the_password_change_only_ever_touches_the_signed_in_account(): void
    {
        $staff = $this->user('staff');
        $owner = $this->user('admin');
        $ownerHash = $owner->fresh()->password;

        $this->actingAs($staff, 'admin')->put('/admin/account/password', [
            'id' => $owner->id,
            'user_id' => $owner->id,
            'email' => $owner->email,
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertSame($ownerHash, $owner->fresh()->password);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $staff->fresh()->password));
    }

    public function test_the_password_change_is_throttled(): void
    {
        $route = Route::getRoutes()->getByName('admin.account.password.update');
        $this->assertContains('throttle:admin-account-password', $route->gatherMiddleware());

        $staff = $this->user('staff');
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($staff, 'admin')->from('/admin/account')->put('/admin/account/password', [
                'current_password' => 'Wrong!Passw0rd9',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertRedirect('/admin/account');
        }

        $this->actingAs($staff, 'admin')->from('/admin/account')->put('/admin/account/password', [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(429);

        $this->assertTrue(Hash::check(self::PASSWORD, $staff->fresh()->password));
    }

    // ══════════ 3. GCash save is the owner's, server-side ══════════

    /** @dataProvider nonOwners */
    public function test_a_non_owner_cannot_save_gcash_even_by_posting_directly(string $role): void
    {
        Storage::fake('public');
        $branch = $this->branch();
        $this->setGcash($branch, '1', 'settings/gcash/acs-keep.png', '09171234567');
        $user = $this->user($role, $branch->id);

        $this->actingAs($user, 'admin')
            ->post(route('admin.account.gcash-qr.update'), [
                'branch_id' => $branch->id,
                'gcash_phone' => '918 000 0000',
                'gcash_qr' => UploadedFile::fake()->image('qr.png', 120, 120),
            ])
            ->assertRedirect(route('admin.home'));

        $this->assertSame("You don't have permission to access that.", session('error'));
        $this->assertSame('09171234567', BranchGcash::for($branch)['number']);
        $this->assertSame('settings/gcash/acs-keep.png', BranchGcash::for($branch)['qr']);
    }

    public static function nonOwners(): array
    {
        return ['supervisor' => ['supervisor'], 'staff' => ['staff']];
    }

    public function test_the_owner_saves_the_plus_63_number_in_the_stored_format(): void
    {
        Storage::fake('public');
        $branch = $this->branch('ACS Owner Save ' . uniqid());
        $owner = $this->user('admin');

        $html = $this->actingAs($owner, 'admin')
            ->withSession(['selected_branch_id' => $branch->id])
            ->followingRedirects()
            ->post(route('admin.account.gcash-qr.update'), [
                'branch_id' => $branch->id,
                'gcash_phone' => '917 333 4444',
                'gcash_qr' => UploadedFile::fake()->image('qr.png', 200, 200),
            ])
            ->assertOk()
            ->getContent();

        $this->assertSame('09173334444', BranchGcash::for($branch)['number']);
        $this->assertNotNull(BranchGcash::for($branch)['qr']);

        // The confirmation is shown once, inside the GCash card.
        $message = 'GCash payment details for ' . $branch->name . ' saved.';
        $this->assertStringContainsString($message, $this->card($html, 'gcash-settings'));
        $this->assertSame(1, substr_count($html, $message));
        $this->assertStringContainsString('value="917 333 4444"', $html);
    }

    public function test_a_bad_number_is_reported_in_the_gcash_card_without_e_g(): void
    {
        $branch = $this->branch();
        $before = BranchGcash::for($branch)['number'];

        $html = $this->actingAs($this->user('admin'), 'admin')
            ->withSession(['selected_branch_id' => $branch->id])
            ->from('/admin/account')
            ->followingRedirects()
            ->post(route('admin.account.gcash-qr.update'), ['branch_id' => $branch->id, 'gcash_phone' => '817 123 4567'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(SettingsController::NUMBER_MESSAGE, $this->card($html, 'gcash-settings'));
        $this->assertStringNotContainsString(SettingsController::NUMBER_MESSAGE, $this->card($html, 'change-password'));
        $this->assertStringNotContainsString('e.g.', $html);
        $this->assertSame($before, BranchGcash::for($branch)['number']);
    }

    // ══════════ 4. recording sign-ins ══════════

    /** @dataProvider roles */
    public function test_a_successful_sign_in_is_recorded(string $role): void
    {
        $user = $this->user($role);

        $this->withHeaders(['User-Agent' => self::SAFARI_IPHONE])
            ->withServerVariables(['REMOTE_ADDR' => '112.204.31.7'])
            ->post('/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.home'));

        $rows = AdminSignIn::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('Safari', $row->browser);
        $this->assertSame('iOS', $row->os);
        $this->assertSame('mobile', $row->device_type);
        $this->assertSame('112.204.31.7', $row->ip_address);
        $this->assertTrue($row->signed_in_at->diffInSeconds(now(), true) < 60);
        $this->assertSame($row->id, session(SignInLog::SESSION_KEY . '.id'));
    }

    public function test_a_failed_or_refused_sign_in_is_not_recorded(): void
    {
        $staff = $this->user('staff');
        $customer = User::create([
            'name' => 'ACS Customer',
            'email' => 'acs-customer-' . uniqid() . '@invalid.local',
            'password' => self::PASSWORD,
            'role' => 'customer',
            'is_active' => true,
        ]);

        $this->post('/admin/login', ['email' => $staff->email, 'password' => 'Wrong!Passw0rd9']);
        $this->post('/admin/login', ['email' => $customer->email, 'password' => self::PASSWORD]);

        $this->assertSame(0, AdminSignIn::whereIn('user_id', [$staff->id, $customer->id])->count());
    }

    public function test_only_the_newest_20_sign_ins_per_user_are_kept(): void
    {
        $user = $this->user('staff');
        $bystander = $this->user('staff');
        for ($i = 25; $i >= 1; $i--) {
            $this->signIn($user, ['signed_in_at' => now()->subDays($i), 'browser' => 'Old ' . $i]);
        }
        for ($i = 1; $i <= 22; $i++) {
            $this->signIn($bystander, ['signed_in_at' => now()->subDays($i)]);
        }

        $this->withHeaders(['User-Agent' => self::CHROME_WINDOWS])
            ->post('/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.home'));

        $kept = AdminSignIn::where('user_id', $user->id)->orderByDesc('signed_in_at')->orderByDesc('id')->get();
        $this->assertCount(SignInLog::KEEP_PER_USER, $kept);
        $this->assertSame('Chrome', $kept->first()->browser, 'the new sign-in is kept');
        $this->assertSame('Old 19', $kept->last()->browser, 'the oldest ones are the ones removed');
        $this->assertSame(0, AdminSignIn::where('user_id', $user->id)->where('browser', 'Old 20')->count());

        // Another user's history is not touched by this user's pruning.
        $this->assertSame(22, AdminSignIn::where('user_id', $bystander->id)->count());
    }

    // ══════════ 5. the Recent Sign-ins card ══════════

    public function test_each_user_sees_only_their_own_sign_ins(): void
    {
        $me = $this->user('supervisor');
        $someoneElse = $this->user('admin');
        $this->signIn($me, ['browser' => 'Firefox', 'os' => 'Linux', 'ip_address' => '203.177.10.20']);
        $this->signIn($someoneElse, ['browser' => 'Opera', 'os' => 'macOS', 'ip_address' => '49.145.88.1']);

        $card = $this->card($this->page($me), 'recent-sign-ins');

        $this->assertStringContainsString('Firefox · Linux', $card);
        $this->assertStringContainsString('203.177.x.x', $card);
        $this->assertStringNotContainsString('203.177.10.20', $card, 'the full IP is never shown');
        $this->assertStringNotContainsString('Opera', $card);
        $this->assertStringNotContainsString('49.145', $card);
    }

    public function test_the_card_pages_three_at_a_time(): void
    {
        $user = $this->user('staff');
        for ($i = 1; $i <= 7; $i++) {
            $this->signIn($user, ['signed_in_at' => now()->subHours($i), 'browser' => 'Browser' . $i]);
        }

        $card = $this->card($this->page($user), 'recent-sign-ins');

        $this->assertStringContainsString('Showing 1–3 of 7 devices', $card);
        $this->assertSame(7, substr_count($card, 'data-sign-in-row data-page='));
        $this->assertSame(4, preg_match_all('/data-sign-in-row data-page="[23]"\s+hidden/', $card));
        $this->assertSame(3, preg_match_all('/data-sign-in-row data-page="1"\s*>/', $card));

        // Newest first: page 1 is Browser1..3.
        $this->assertLessThan(strpos($card, 'Browser4'), strpos($card, 'Browser1'));
        $this->assertMatchesRegularExpression('/data-page="1"[^>]*>.*?Browser1/s', $card);

        // Prev, three numbered pages, Next.
        $this->assertMatchesRegularExpression('/data-page="prev" aria-label="Previous page" disabled/', $card);
        foreach ([1, 2, 3] as $p) {
            $this->assertStringContainsString('aria-label="Page ' . $p . '"', $card);
        }
        $this->assertStringNotContainsString('aria-label="Page 4"', $card);
        $this->assertMatchesRegularExpression('/data-page="next" aria-label="Next page"\s*>/', $card);
    }

    public function test_one_sign_in_reads_as_one_device_and_none_shows_an_empty_state(): void
    {
        $one = $this->user('staff');
        $this->signIn($one);
        $card = $this->card($this->page($one), 'recent-sign-ins');
        $this->assertStringContainsString('Showing 1–1 of 1 device', $card);
        $this->assertMatchesRegularExpression('/data-page="next" aria-label="Next page" disabled/', $card);

        $none = $this->user('staff');
        $card = $this->card($this->page($none), 'recent-sign-ins');
        $this->assertStringContainsString('No sign-ins recorded yet.', $card);
        $this->assertStringNotContainsString('acs-pager', $card);
    }

    public function test_the_current_sign_in_is_tagged_this_device_and_drives_the_banner(): void
    {
        $user = $this->user('admin');
        $older = $this->signIn($user, ['signed_in_at' => now()->subDay(), 'browser' => 'Edge']);
        $current = $this->signIn($user, ['signed_in_at' => now()->subMinutes(5)]);

        $html = $this->actingAs($user, 'admin')
            ->withSession([SignInLog::SESSION_KEY => ['id' => $current->id, 'at' => $current->signed_in_at->toIso8601String()]])
            ->get('/admin/account')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-this-device'));
        $this->assertMatchesRegularExpression('/Chrome · Windows.*?data-this-device>This device/s', $this->card($html, 'recent-sign-ins'));
        $this->assertStringContainsString('Signed in 5 minutes ago', $html);
        $this->assertNotNull($older);
    }

    public function test_a_session_older_than_the_log_shows_no_made_up_sign_in_time(): void
    {
        $html = $this->page($this->user('staff'));

        $this->assertStringNotContainsString('data-signed-in-ago', $html);
        $this->assertStringNotContainsString('data-this-device', $html);
    }

    public function test_times_are_shown_in_manila_time(): void
    {
        // 2026-10-30 04:00 UTC = 12:00 noon in Manila.
        $now = Carbon::parse('2026-10-30 04:00:00', 'UTC');

        $this->assertSame('Just now', SignInLog::whenLabel($now->copy()->subSeconds(20), $now));
        $this->assertSame('1 minute ago', SignInLog::whenLabel($now->copy()->subMinute(), $now));
        $this->assertSame('2 minutes ago', SignInLog::whenLabel($now->copy()->subMinutes(2), $now));
        $this->assertSame('Today, 9:15 AM', SignInLog::whenLabel(Carbon::parse('2026-10-30 01:15:00', 'UTC'), $now));
        // 2026-10-29 12:14 UTC is 8:14 PM on the 29th in Manila.
        $this->assertSame('Yesterday, 8:14 PM', SignInLog::whenLabel(Carbon::parse('2026-10-29 12:14:00', 'UTC'), $now));
        // 2026-10-23 17:30 UTC is already Oct 24 in Manila.
        $this->assertSame('Oct 24, 1:30 AM', SignInLog::whenLabel(Carbon::parse('2026-10-23 17:30:00', 'UTC'), $now));
        $this->assertSame('Dec 31, 2025, 10:02 AM', SignInLog::whenLabel(Carbon::parse('2025-12-31 02:02:00', 'UTC'), $now));

        $this->assertSame('just now', SignInLog::signedInAgo($now->copy()->subSeconds(10), $now));
        $this->assertSame('2 hours ago', SignInLog::signedInAgo($now->copy()->subHours(2), $now));
    }

    public function test_user_agents_and_ips_are_summarised(): void
    {
        $cases = [
            [self::CHROME_WINDOWS, 'Chrome', 'Windows', 'desktop'],
            [self::SAFARI_IPHONE, 'Safari', 'iOS', 'mobile'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', 'Edge', 'Windows', 'desktop'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox', 'Windows', 'desktop'],
            ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 'Chrome', 'Android', 'mobile'],
            ['Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Safari/537.36', 'Samsung Internet', 'Android', 'tablet'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Safari', 'macOS', 'desktop'],
            ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0 Mobile/15E148 Safari/604.1', 'Chrome', 'iPadOS', 'tablet'],
            ['', 'Unknown browser', 'Unknown OS', 'desktop'],
        ];

        foreach ($cases as [$ua, $browser, $os, $device]) {
            $this->assertSame(['browser' => $browser, 'os' => $os, 'device' => $device], SignInLog::parseUserAgent($ua), $ua);
        }

        $this->assertSame('112.204.x.x', SignInLog::maskIp('112.204.31.7'));
        $this->assertSame('2001:db8:x:x', SignInLog::maskIp('2001:db8:85a3::8a2e:370:7334'));
        $this->assertSame('IP unknown', SignInLog::maskIp(null));
        $this->assertSame('IP unknown', SignInLog::maskIp('not-an-ip'));
    }

    public function test_deleting_an_account_deletes_its_sign_ins(): void
    {
        $user = $this->user('staff');
        $this->signIn($user);

        $user->delete();

        $this->assertSame(0, AdminSignIn::where('user_id', $user->id)->count());
    }
}
