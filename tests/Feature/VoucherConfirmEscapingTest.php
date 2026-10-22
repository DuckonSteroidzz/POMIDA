<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Vouchers tab's Issue and Delete confirmations used to print the voucher
 * code inside the inline script: onsubmit="return confirm('… {{ code }} …')".
 * Blade escapes for HTML, and the browser decodes the attribute before running
 * it, so a quote in a code (any string up to 50 characters is accepted) closed
 * the JavaScript string — the confirm broke, or the rest of the code ran as
 * script on every manager's and staff member's Vouchers page. The text now
 * rides in data-confirm and the script only reads it, as on the Ads tab.
 */
class VoucherConfirmEscapingTest extends TestCase
{
    use DatabaseTransactions;

    private const CODE = "X'); window.__pwned=1;//\"</script>";
    private const DESCRIPTION = "Bob's \"deal\" </script><script>window.__pwned=2</script>";

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::selectOne('select database() as d')->d);
    }

    private function voucher(): Voucher
    {
        return Voucher::create([
            'code'            => self::CODE . Str::upper(Str::random(4)),
            'description'     => self::DESCRIPTION,
            'discount_type'   => 'fixed',
            'discount_value'  => 20,
            'max_uses'        => 50,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
            'expires_at'      => null,
            'branch_id'       => null,
        ]);
    }

    private function user(string $role): User
    {
        return User::create([
            'name'              => 'VCE ' . $role,
            'email'             => 'vce-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : 1,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_quotes_and_script_tags_in_a_voucher_cannot_break_the_confirmations(): void
    {
        $voucher = $this->voucher();

        foreach (['admin', 'staff'] as $role) {
            $html = $this->actingAs($this->user($role), 'admin')
                ->get('/admin/vouchers?branch=all&per_page=100&q=' . urlencode('window.__pwned'))
                ->assertOk()->getContent();

            $this->assertStringContainsString('data-voucher-row="' . $voucher->id . '"', $html, "$role: voucher not listed");

            // Issue (every role): the text is an HTML-escaped attribute value...
            $this->assertStringContainsString(
                'data-confirm="Issue a new code for ' . e($voucher->code) . '? It counts against this voucher\'s usage limit."',
                $html,
                "$role: issue confirmation"
            );

            // ...and no voucher text is inside any inline script.
            $this->assertStringNotContainsString("confirm('Issue a new code", $html);
            $this->assertStringNotContainsString("confirm('Delete voucher", $html);
            $this->assertStringNotContainsString('window.__pwned', $this->scriptText($html), "$role: voucher text reached a script");

            // The payloads never appear unescaped anywhere.
            $this->assertStringNotContainsString(self::CODE, $html);
            $this->assertStringNotContainsString('</script><script>window.__pwned=2', $html);
        }

        // Delete (owner only).
        $html = $this->actingAs($this->user('admin'), 'admin')
            ->get('/admin/vouchers?branch=all&per_page=100&q=' . urlencode('window.__pwned'))->getContent();
        $this->assertStringContainsString(
            'data-confirm="Delete voucher ' . e($voucher->code) . '?" onsubmit="return confirm(this.dataset.confirm)"',
            $html
        );
        // Table row + phone card, each with an Issue and a Delete form.
        $this->assertSame(4, substr_count($html, 'onsubmit="return confirm(this.dataset.confirm)"'), 'issue + delete confirmations both read data-confirm');
    }

    /** The text of every inline <script> and on* handler on the page. */
    private function scriptText(string $html): string
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $html, $blocks);
        preg_match_all('/\son[a-z]+="([^"]*)"/', $html, $handlers);

        return implode("\n", array_merge($blocks[1], array_map('html_entity_decode', $handlers[1])));
    }
}
