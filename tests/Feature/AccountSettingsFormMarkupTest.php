<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Account page's details form must be a real <form>.
 *
 * From eec9d21 until Oct 2026 the opening tag read `<formaction="…"` (no
 * space). Browsers parse that as an unknown element, not a form, so Name,
 * Email, Contact, Address, Password, the PWD/Senior IDs and Save Changes sat
 * in no form at all and Save did nothing. Every PUT /customer/account test
 * still passed, because none of them reads the page. This one parses the
 * rendered HTML the way a browser would and asks which form each field
 * belongs to.
 */
class AccountSettingsFormMarkupTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_account_details_fields_and_save_button_sit_in_the_update_form(): void
    {
        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());

        $user = User::create([
            'name'              => 'Form Markup',
            'email'             => 'form-markup-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        $html = $this->actingAs($user, 'customer')->get('/customer/account')->assertOk()->getContent();

        $this->assertStringNotContainsString('<formaction=', $html);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $update = route('customer.account.update');

        foreach (['name', 'email', 'password'] as $field) {
            $input = $xpath->query('//input[@name="' . $field . '"]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $input, "no {$field} input on the page");
            $this->assertSame($update, $this->formActionOf($input), "the {$field} field is not inside the account update form");
        }

        $save = $xpath->query('//button[@type="submit"][contains(normalize-space(.), "Save Changes")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $save);
        $this->assertSame($update, $this->formActionOf($save), 'Save Changes is not inside the account update form');
    }

    private function formActionOf(DOMElement $element): ?string
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (strtolower($node->tagName) === 'form') {
                return $node->getAttribute('action');
            }
        }

        return null;
    }
}
