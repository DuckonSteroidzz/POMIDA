<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The redesigned Account Settings page (docs/design-refs/admin-account-settings.html).
 *
 * The redesign changed markup only. The routes, controllers, validation and
 * field names behind the password change and the GCash settings are the ones
 * the older tests already pin; this file pins what the new page renders from
 * the real signed-in user, that the two forms still post where they always
 * did, and that the page is still the owner's alone.
 */
class AdminAccountSettingsRedesignTest extends TestCase
{
    use DatabaseTransactions;

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

    private function makeUser(string $role, string $name = 'Maria Santos'): User
    {
        return User::create([
            'name'      => $name,
            'email'     => 'redesign-' . $role . '-' . uniqid() . '@invalid.local',
            'password'  => 'Old!Passw0rd123',
            'role'      => $role,
            'is_active' => true,
            'branch_id' => 1,
        ]);
    }

    private function page(User $user): string
    {
        return $this->actingAs($user, 'admin')->get('/admin/account')->assertOk()->getContent();
    }

    public function test_the_page_renders_the_real_signed_in_user(): void
    {
        $admin = $this->makeUser('admin', 'Maria Santos');
        $html  = $this->page($admin);

        $this->assertStringContainsString('Administration', $html);
        $this->assertStringContainsString('Account Settings', $html);
        $this->assertStringContainsString('Signed-in Account', $html);
        $this->assertStringContainsString('Change Password', $html);
        $this->assertStringContainsString('GCash Payment Settings', $html);
        $this->assertStringContainsString('Account Settings at a Glance', $html);

        // Real data, not the mock-up's "Test Admin".
        $this->assertStringContainsString('Maria Santos', $html);
        $this->assertStringContainsString($admin->email, $html);
        $this->assertStringContainsString('>MS<', $html, 'avatar initials come from the name');
        $this->assertStringContainsString('Administrator', $html);
        $this->assertStringNotContainsString('Test Admin', $html);
        $this->assertStringNotContainsString('testadmin@peachycafe.com', $html);
    }

    public function test_name_email_and_access_level_are_read_only(): void
    {
        $html = $this->page($this->makeUser('admin'));

        foreach (['as_name', 'as_email', 'as_access'] as $id) {
            $this->assertMatchesRegularExpression('/id="' . $id . '"[^>]*\breadonly\b/', $html, $id . ' must be readonly');
        }
    }

    public function test_the_staff_accounts_link_is_real(): void
    {
        $html = $this->page($this->makeUser('admin'));

        $this->assertStringContainsString('Managing staff accounts?', $html);
        $this->assertStringContainsString('href="' . route('admin.users') . '"', $html);
        $this->assertStringContainsString('Go to Staff Accounts', $html);
        $this->assertStringNotContainsString('Connect this link', $html);
    }

    public function test_the_password_form_posts_to_the_existing_route_with_the_existing_field_names(): void
    {
        $html = $this->page($this->makeUser('admin'));

        $this->assertStringContainsString('action="' . route('admin.account.password.update') . '"', $html);
        $this->assertStringContainsString('name="_method" value="PUT"', $html);
        foreach (['current_password', 'password', 'password_confirmation'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html);
        }
        $this->assertSame(3, substr_count($html, 'class="js-pw-toggle"'), 'one eye toggle per password field');
        $this->assertStringContainsString('Update Password', $html);
        $this->assertStringContainsString('type="reset"', $html);
    }

    public function test_the_gcash_form_posts_to_the_existing_route_with_the_existing_field_names(): void
    {
        $html = $this->page($this->makeUser('admin'));

        $this->assertStringContainsString('action="' . route('admin.account.gcash-qr.update') . '"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="gcash_qr"', $html);
        $this->assertStringContainsString('name="gcash_phone"', $html);
        $this->assertStringContainsString('JPG, JPEG, PNG, or WEBP · Maximum 5 MB', $html);
        $this->assertStringContainsString('Save GCash Settings', $html);
    }

    public function test_the_gcash_phone_placeholder_has_no_e_g(): void
    {
        $html = $this->page($this->makeUser('admin'));

        $this->assertStringContainsString('placeholder="09XX XXX XXXX"', $html);
        $this->assertStringNotContainsString('e.g.', $html);
    }

    public function test_the_page_shows_the_saved_gcash_phone_and_qr(): void
    {
        Setting::updateOrCreate(['key' => 'gcash_phone', 'branch_id' => null], ['value' => '0917 123 4567', 'group' => 'payment', 'label' => 'GCash Phone Number', 'type' => 'text']);
        Setting::updateOrCreate(['key' => 'gcash_qr', 'branch_id' => null], ['value' => 'settings/gcash/redesign-test.png', 'group' => 'payment', 'label' => 'GCash QR Code', 'type' => 'image']);

        $html = $this->page($this->makeUser('admin'));

        $this->assertStringContainsString('value="0917 123 4567"', $html);
        $this->assertStringContainsString('redesign-test.png', $html);
        $this->assertStringContainsString('alt="Store GCash QR Code"', $html);
    }

    public function test_the_gcash_save_still_works_end_to_end(): void
    {
        Storage::fake('public');
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'admin')
            ->post('/admin/account/gcash-qr', [
                'gcash_phone' => '0999 888 7777',
                'gcash_qr'    => UploadedFile::fake()->image('qr.png', 200, 200),
            ])
            ->assertRedirect(route('admin.account'))
            ->assertSessionHas('success');

        $this->assertSame('0999 888 7777', Setting::where('key', 'gcash_phone')->whereNull('branch_id')->value('value'));
        $this->assertNotEmpty(Setting::where('key', 'gcash_qr')->whereNull('branch_id')->value('value'));
    }

    public function test_a_bad_gcash_upload_shows_its_error_in_the_gcash_card_not_the_password_card(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin, 'admin')
            ->from('/admin/account')
            ->followingRedirects()
            ->post('/admin/account/gcash-qr', [
                'gcash_phone' => str_repeat('1', 30),
            ])
            ->assertOk()
            ->getContent();

        $gcashStart   = strpos($html, 'id="gcash-settings"');
        $passwordStart = strpos($html, 'id="change-password"');
        $this->assertNotFalse($gcashStart);
        $this->assertNotFalse($passwordStart);

        $gcashCard    = substr($html, $gcashStart);
        $passwordCard = substr($html, $passwordStart, $gcashStart > $passwordStart ? $gcashStart - $passwordStart : 4000);

        $this->assertStringContainsString('gcash phone', strtolower($gcashCard));
        $this->assertStringNotContainsString('gcash phone', strtolower($passwordCard));
    }

    public function test_password_errors_appear_in_the_password_card(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin, 'admin')
            ->from('/admin/account')
            ->followingRedirects()
            ->put('/admin/account/password', [
                'current_password'      => 'Wrong!Passw0rd999',
                'password'              => 'New!Passw0rd456',
                'password_confirmation' => 'New!Passw0rd456',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('That is not your current password.', $html);
        $this->assertGreaterThan(
            strpos($html, 'id="change-password"'),
            strpos($html, 'That is not your current password.')
        );
    }

    public function test_a_mismatched_confirmation_is_reported_from_the_server(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin, 'admin')
            ->from('/admin/account')
            ->followingRedirects()
            ->put('/admin/account/password', [
                'current_password'      => 'Old!Passw0rd123',
                'password'              => 'New!Passw0rd456',
                'password_confirmation' => 'Different!Passw0rd1',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('The new password and its confirmation do not match.', $html);
    }

    public function test_a_successful_password_change_is_reported_on_the_page(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin, 'admin')
            ->from('/admin/account')
            ->followingRedirects()
            ->put('/admin/account/password', [
                'current_password'      => 'Old!Passw0rd123',
                'password'              => 'New!Passw0rd456',
                'password_confirmation' => 'New!Passw0rd456',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Your password has been updated.', $html);
    }

    /**
     * The redesign must not widen who can see or save anything: the whole page
     * is `role:admin`, so a supervisor and a staff member get none of it.
     *
     * @dataProvider nonOwnerRoles
     */
    public function test_supervisor_and_staff_get_no_part_of_the_page(string $role): void
    {
        $user = $this->makeUser($role);

        $this->actingAs($user, 'admin')->get('/admin/account')
            ->assertRedirect(route('admin.home'));

        $this->actingAs($user, 'admin')->put('/admin/account/password', [
            'current_password'      => 'Old!Passw0rd123',
            'password'              => 'New!Passw0rd456',
            'password_confirmation' => 'New!Passw0rd456',
        ])->assertRedirect(route('admin.home'));

        $this->actingAs($user, 'admin')->post('/admin/account/gcash-qr', ['gcash_phone' => '0900 000 0000'])
            ->assertRedirect(route('admin.home'));

        $this->assertNotSame('0900 000 0000', Setting::where('key', 'gcash_phone')->whereNull('branch_id')->value('value'));
    }

    public static function nonOwnerRoles(): array
    {
        return ['supervisor' => ['supervisor'], 'staff' => ['staff']];
    }
}
