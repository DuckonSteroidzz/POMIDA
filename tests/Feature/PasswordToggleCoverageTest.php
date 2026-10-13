<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every real password field on a customer page, and the admin/staff login,
 * has a Show/Hide eye button wired to it (October 2026).
 *
 * The toggle is pure front-end (it flips the input's type), so PHPUnit can only
 * pin the markup: each visible password input has an id, and a button whose
 * data-password-toggle points at that id and starts as "Show password". The
 * behaviour itself was checked in a real browser at 390px.
 *
 * Also pins the descender fix on the Dine-In guest banner's underlined links.
 */
class PasswordToggleCoverageTest extends TestCase
{
    private function source(string $relative): string
    {
        return file_get_contents(resource_path('views/' . $relative));
    }

    /** @return array<int, array{string, int}> view => number of password fields */
    public static function passwordPages(): array
    {
        return [
            'customer login' => ['customer/login.blade.php', 1],
            'customer register' => ['customer/register.blade.php', 2],
            'customer reset password' => ['customer/new-password.blade.php', 2],
            'customer account settings' => ['customer/account-settings.blade.php', 2],
            'admin/staff login' => ['admin/login.blade.php', 1],
        ];
    }

    /**
     * @dataProvider passwordPages
     */
    public function test_each_password_field_has_a_matching_toggle(string $relative, int $fields): void
    {
        $html = $this->source($relative);

        $this->assertSame(
            $fields,
            preg_match_all('/type="password"/', $html),
            "$relative: unexpected number of password inputs"
        );

        $this->assertSame(
            $fields,
            preg_match_all('/data-password-toggle="([^"]+)"/', $html, $m),
            "$relative: every password input needs one toggle"
        );

        foreach ($m[1] as $targetId) {
            $this->assertMatchesRegularExpression(
                '/id="' . preg_quote($targetId, '/') . '"/',
                $html,
                "$relative: toggle points at missing id $targetId"
            );
        }

        $this->assertSame($fields, substr_count($html, 'aria-label="Show password"'));
        $this->assertSame($fields, substr_count($html, 'aria-pressed="false"'));
    }

    public function test_register_and_account_toggles_swap_label_and_icon_without_touching_the_value(): void
    {
        foreach (['customer/register.blade.php', 'customer/account-settings.blade.php'] as $relative) {
            $html = $this->source($relative);

            $this->assertStringContainsString("'Hide password'", $html);
            $this->assertStringContainsString("'Show password'", $html);
            $this->assertStringContainsString('bi-eye-slash', $html);
            $this->assertStringNotContainsString('console.log(target.value', $html);
            $this->assertStringContainsString('width: 44px', $html);
            $this->assertStringContainsString('height: 44px', $html);
        }
    }

    public function test_dinein_popup_underlined_link_leaves_room_for_descenders(): void
    {
        $popup = $this->source('customer/partials/dinein-rewards-popup.blade.php');

        $this->assertMatchesRegularExpression(
            '/\.dinr__login a\s*\{[^}]*text-decoration-skip-ink:\s*auto[^}]*text-underline-offset:\s*[^;}]+[^}]*\}/',
            $popup
        );
        $this->assertMatchesRegularExpression('/\.dinr__login a\s*\{[^}]*line-height:\s*1\.6/', $popup);
    }
}
