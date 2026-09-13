<?php

namespace Tests\Feature;

use App\Support\PasswordPolicy;
use Tests\TestCase;

/**
 * Customer registration form:
 *
 *  (a) real-time validation feedback — a bi-check-lg tick appears on each field
 *      that passes the SAME rule AuthController::register() enforces on submit,
 *      driven on input/blur. No new business rule is introduced client-side.
 *  (b) the "Open daily / 7am – 9pm" store-hours block is gone from the brand
 *      panel (nothing else — JS or CSS — depended on it).
 */
class RegistrationFormFeedbackTest extends TestCase
{
    private function html(): string
    {
        return $this->get(route('customer.register'))->assertOk()->getContent();
    }

    // ── (b) store-hours removal ──────────────────────────────────────────────

    public function test_the_brand_panel_no_longer_shows_store_hours(): void
    {
        $html = $this->html();

        // The brand-panel block ("Open daily / 7am – 9pm") and its CSS are gone.
        // (The Terms-of-Service modal's separate "counter hours" line is a
        // different element and deliberately untouched.)
        $this->assertStringNotContainsString('brand-footer', $html, 'the brand-footer block and its CSS must be gone');
        $this->assertStringNotContainsString('Open daily', $html, 'the brand-panel store-hours label must be gone');
        $this->assertStringNotContainsString('class="hours"', $html);

        $brandPanel = substr($html, strpos($html, 'brand-panel">'), 900);
        $this->assertStringNotContainsString('7am', $brandPanel, 'no store hours anywhere in the brand panel');

        // The rest of the brand panel is untouched.
        $this->assertStringContainsString('brand-list', $html);
        $this->assertStringContainsString('Peachy Cakes', $html);
    }

    // ── (a) real-time validation ─────────────────────────────────────────────

    public function test_every_validated_field_carries_a_check_icon_using_the_shared_icon_set(): void
    {
        $html = $this->html();

        // Bootstrap Icons is now loaded on this page (it was not before).
        $this->assertStringContainsString('/vendor/bootstrap-icons.css', $html);

        // One tick per field that has a server-side rule to mirror:
        // name, email, password, password_confirmation, contact_number.
        $this->assertSame(
            5,
            substr_count($html, 'bi bi-check-lg field-valid-icon'),
            'each validated field needs exactly one bi-check-lg checkmark'
        );
    }

    public function test_the_valid_state_css_is_a_companion_to_the_existing_invalid_convention(): void
    {
        $html = $this->html();

        // .is-valid on the input mirrors the existing .field input.is-invalid.
        $this->assertMatchesRegularExpression('/\.field input\.is-valid\s*\{/', $html);
        // The tick only shows once the field is valid.
        $this->assertMatchesRegularExpression('/\.field\.is-valid \.field-valid-icon\s*\{[^}]*display:\s*inline-block/s', $html);
        // Uses the success green already in the codebase — no new colour token.
        $this->assertStringContainsString('#155724', $html);
    }

    public function test_the_client_checks_mirror_the_server_rules(): void
    {
        $html = $this->html();

        $this->assertStringContainsString("getElementById('registerForm')", $html);
        $this->assertStringContainsString('id="registerForm"', $html);

        // contact_number: digits_between:10,13
        $this->assertStringContainsString('/^[0-9]{10,13}$/', $html);
        // password: PasswordPolicy min length rendered from the constant itself,
        // so the client can never drift from the policy.
        $this->assertStringContainsString('v.length >= ' . PasswordPolicy::MIN_LENGTH, $html);
        // password_confirmation: 'confirmed'
        $this->assertStringContainsString('v === pw.value', $html);
        // feedback is wired to both input and blur
        $this->assertStringContainsString("addEventListener('input'", $html);
        $this->assertStringContainsString("addEventListener('blur'", $html);
    }
}
