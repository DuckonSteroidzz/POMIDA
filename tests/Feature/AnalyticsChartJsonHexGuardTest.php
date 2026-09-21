<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3b F10 — analytics.blade.php:1026-1028 are the only 3 raw-Blade
 * {!! json_encode(...) !!} sites in the app. All three currently feed from
 * generated date strings and numerics (AnalyticsService), so there is no
 * live exploit today — this is defense-in-depth, in case a future change
 * routes user-controlled text through the same three lines without noticing
 * they skip Blade's usual {{ }} escaping.
 *
 * JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT escapes the
 * characters that matter for a value embedded directly inside a <script>
 * block: '<' and '>' (so a value can never close the script tag or open a
 * new one), '&' (entity confusion in some parsers), and quote characters
 * (so a value can never break out of a JSON string it sits inside). It does
 * NOT touch the delimiting quotes json_encode itself always wraps a string
 * in — confirmed in test_the_flags_do_not_disturb_ordinary_values below —
 * so the chart's own date/number payloads render byte-identical either way.
 */
class AnalyticsChartJsonHexGuardTest extends TestCase
{
    use DatabaseTransactions;

    private const EXPECTED_FLAGS = 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT';

    public static function jsonEncodeCallsProvider(): array
    {
        return [
            'actualLabels'   => ['const actualLabels ='],
            'actualValues'   => ['const actualValues ='],
            'forecastPoints' => ['const forecastPoints ='],
        ];
    }

    /** @dataProvider jsonEncodeCallsProvider */
    public function test_each_chart_json_encode_call_carries_the_hex_flags(string $constDeclaration): void
    {
        $source = file_get_contents(resource_path('views/admin/analytics.blade.php'));

        $lineStart = strpos($source, $constDeclaration);
        $this->assertNotFalse($lineStart, "could not find '{$constDeclaration}' in analytics.blade.php — did it move?");

        $lineEnd = strpos($source, ';', $lineStart);
        $line = substr($source, $lineStart, $lineEnd - $lineStart);

        $this->assertStringContainsString(
            self::EXPECTED_FLAGS,
            $line,
            "{$constDeclaration} lost its JSON_HEX_* flags"
        );
    }

    /**
     * Confirms what the flags actually DO, independent of this app's
     * templates — the guard this pass adds, proven at the PHP level.
     */
    public function test_the_hex_flags_neutralise_script_breaking_characters(): void
    {
        $dangerous = ['<script>alert(1)</script>', 'Tom & Jerry', "O'Brien", 'He said "hi"'];

        $guarded = json_encode($dangerous, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $this->assertStringNotContainsString('<script>', $guarded);
        $this->assertStringNotContainsString('</script>', $guarded);
        $this->assertStringNotContainsString('&', $guarded);
        $this->assertStringNotContainsString("'", $guarded);

        // The value survives, just neutralised — nothing is silently dropped.
        $this->assertStringContainsString('u003Cscript', $guarded);
    }

    /**
     * And the flags must not corrupt the ordinary values this chart actually
     * carries — the wrapping quotes json_encode puts around every string are
     * untouched by these flags, so a plain date string renders identically.
     */
    public function test_the_flags_do_not_disturb_ordinary_values(): void
    {
        $dates = ['2026-03-08', '2026-03-09'];

        $this->assertSame(
            json_encode($dates),
            json_encode($dates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'the hex flags changed the encoding of an ordinary value with no special characters'
        );
    }
}
