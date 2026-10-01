<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The "New account" form's stale-error fix (October 2026).
 *
 * After a failed submit the page's own script takes server messages down as
 * the customer fixes each field, and rebuilds the password lines live. That
 * script can only do this if the server gives it two things, pinned here:
 *
 *   1. Markup: each summary line carries its field (data-field), and each
 *      inline message says which field it belongs to (data-error-for).
 *   2. Words: the password sentences the script shows are EXACTLY the ones
 *      the validator produced. Each password rule is broken on its own below,
 *      and the server's message must equal the page's entry for that rule.
 *      A Laravel upgrade or a policy change that alters a sentence fails here
 *      instead of leaving two different wordings on one screen.
 *
 * The browser behaviour itself was verified in headless Chrome (see the
 * commit message); PHPUnit cannot run the page's JavaScript.
 */
class RegisterErrorSummaryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // customer-register is 10/min per IP and every request here is 127.0.0.1.
        app('cache')->store()->flush();
    }

    private function submit(string $password, ?string $confirmation = null): array
    {
        $this->flushSession();

        $this->from(route('customer.register'))->post(route('customer.register.post'), [
            'name' => 'RES Person',
            'email' => 'res-' . uniqid() . '@example.test',
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
            'contact_number' => '09171234567',
            'terms' => '1',
        ])->assertRedirect(route('customer.register'));

        return session('errors')->get('password');
    }

    /** The `var MSG = {...};` dictionary the rendered page hands its script. */
    private function pageDictionary(): array
    {
        $html = $this->get(route('customer.register'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/var MSG\s*=\s*(\{.*?\});/s', $html, $m), 'the page no longer embeds its password messages');

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function singleRuleFailures(): array
    {
        return [
            'too short'        => ['Ab1!x', null, 'min'],
            'too long'         => ['Abcdefghij1!Abcdefghij', null, 'max'],
            'no uppercase'     => ['abcdefg1!', null, 'mixed'],
            'no symbol'        => ['Abcdefgh1', null, 'symbols'],
            'no number'        => ['Abcdefgh!', null, 'numbers'],
            'confirm mismatch' => ['Abcdefg1!', 'Abcdefg1?', 'confirmed'],
        ];
    }

    /**
     * @dataProvider singleRuleFailures
     */
    public function test_the_page_shows_the_same_sentence_the_server_produced(string $password, ?string $confirmation, string $key): void
    {
        $serverMessages = $this->submit($password, $confirmation);

        $this->assertCount(1, $serverMessages, 'this case was meant to break exactly one rule: ' . json_encode($serverMessages));
        $this->assertSame($serverMessages[0], $this->pageDictionary()[$key]);
    }

    public function test_an_empty_password_is_the_required_sentence(): void
    {
        $this->flushSession();
        $this->from(route('customer.register'))->post(route('customer.register.post'), [
            'name' => 'RES Person',
            'email' => 'res-' . uniqid() . '@example.test',
            'password' => '',
            'contact_number' => '09171234567',
            'terms' => '1',
        ]);

        $this->assertSame(session('errors')->first('password'), $this->pageDictionary()['required']);
    }

    public function test_summary_lines_and_inline_messages_carry_their_field(): void
    {
        $this->flushSession();
        $this->from(route('customer.register'))->post(route('customer.register.post'), [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'abc',
            'password_confirmation' => 'abc',
            'contact_number' => 'abc',
        ]);

        $html = $this->get(route('customer.register'))->assertOk()->getContent();

        $this->assertStringContainsString('id="registerErrorSummary"', $html);
        foreach (['name', 'email', 'password', 'contact_number', 'terms'] as $field) {
            $this->assertMatchesRegularExpression('/<li data-field="' . $field . '">/', $html, "summary line for {$field}");
            $this->assertStringContainsString('data-error-for="' . $field . '"', $html, "inline message for {$field}");
        }

        // All four password lines are there, each tagged.
        $this->assertSame(4, substr_count($html, '<li data-field="password">'));
    }

    public function test_a_clean_page_has_no_summary_box(): void
    {
        $html = $this->get(route('customer.register'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="registerErrorSummary"', $html);
    }
}
