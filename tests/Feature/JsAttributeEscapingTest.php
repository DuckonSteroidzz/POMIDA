<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Client-side escapers that feed an HTML ATTRIBUTE must escape quotes.
 *
 * THE TRAP
 * --------
 * The idiomatic JS escaper in this project is
 *
 *     var d = document.createElement('div');
 *     d.textContent = value;
 *     return d.innerHTML;
 *
 * which is correct and sufficient for TEXT content: it escapes &, < and >. It
 * does NOT escape `"` or `'`, because those need no escaping between tags.
 *
 * Three of these escapers are also used inside QUOTED ATTRIBUTES built by string
 * concatenation, e.g.
 *
 *     'data-table="' + escapeHtml(t.table_number) + '">Clear</button>'
 *
 * There a double quote in the value closes the attribute early and lets the
 * attacker append further attributes to the tag.
 *
 * WAS IT EXPLOITABLE?
 * -------------------
 * No — stated plainly because the distinction matters for the report. The one
 * attribute carrying attacker-influenced data was qr-generator's `data-table`,
 * fed from a manual order's table_number. `orders.table_number` is
 * varchar(10), and `<`/`>` were already escaped, so there was neither room for
 * a payload nor a way to break out of the tag. It was a latent defect, not a
 * live hole, and it is fixed because an escaping decision should not silently
 * depend on how wide a column happens to be.
 *
 * WHY A SOURCE SCAN
 * -----------------
 * These are JavaScript functions inside Blade files; there is no PHP entry point
 * to call. Rendering them in a real browser is possible (the project does that
 * elsewhere with puppeteer-core) but far too heavy for a property this simple.
 * So this asserts the property over the source, in the same style as
 * SqlInjectionTest's scan.
 */
class JsAttributeEscapingTest extends TestCase
{
    /**
     * The escapers whose output reaches a quoted HTML attribute.
     *
     * Each entry is [view path, function name]. Verified by grepping for
     * `="' + <fn>(` at the time of writing; the last test below re-derives that
     * list from the source so a NEW attribute use cannot slip past unnoticed.
     */
    private const ATTRIBUTE_ESCAPERS = [
        ['resources/views/admin/qr-generator.blade.php', 'escapeHtml'],
        ['resources/views/admin/partials/notification-bell.blade.php', 'esc'],
        ['resources/views/customer/partials/notification-bell.blade.php', 'esc'],
    ];

    private function source(string $relative): string
    {
        $path = base_path($relative);

        $this->assertFileExists($path, "{$relative} has moved — this guard needs updating, not deleting");

        return (string) file_get_contents($path);
    }

    /**
     * The body of a named JS function, from `function name(` to its first
     * closing brace at the start of a line at the same indent. Crude but
     * sufficient for these small helpers, and it fails loudly rather than
     * silently matching nothing.
     */
    private function functionBody(string $source, string $fn, string $where): string
    {
        $start = strpos($source, "function {$fn}(");

        $this->assertNotFalse($start, "{$where}: no function {$fn}( found — the escaper was renamed or removed");

        $body = substr($source, $start, 700);

        $end = strpos($body, "\n    }");
        if ($end !== false) {
            $body = substr($body, 0, $end);
        }

        return $body;
    }

    public function test_every_attribute_escaper_also_escapes_quotes(): void
    {
        foreach (self::ATTRIBUTE_ESCAPERS as [$view, $fn]) {
            $body = $this->functionBody($this->source($view), $fn, $view);

            $this->assertStringContainsString(
                '&quot;',
                $body,
                "{$view}: {$fn}() output is interpolated into a quoted HTML attribute but the function does "
                . 'not escape double quotes, so a value containing " can close the attribute and add its own.'
            );

            $this->assertStringContainsString(
                '&#039;',
                $body,
                "{$view}: {$fn}() does not escape single quotes."
            );
        }
    }

    /**
     * The list above must not go stale.
     *
     * Re-derives every `="' + fn(` attribute interpolation from the views and
     * asserts each escaper it finds is one this file already checks. A new
     * attribute built with an unlisted escaper fails here, which is the whole
     * point — the previous guard would simply not have known about it.
     */
    public function test_no_attribute_interpolation_uses_an_unchecked_escaper(): void
    {
        $checked = [];
        foreach (self::ATTRIBUTE_ESCAPERS as [$view, $fn]) {
            $checked[$view][] = $fn;
        }

        $views = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/views'), \FilesystemIterator::SKIP_DOTS)
        );

        $unchecked = [];
        $seen = 0;

        foreach ($views as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $source = (string) file_get_contents($file->getPathname());

            // ="' + someFn(   — a quoted attribute whose value comes from a call.
            if (! preg_match_all('/="\'\s*\+\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $m)) {
                continue;
            }

            foreach ($m[1] as $fn) {
                $seen++;

                if (! in_array($fn, $checked[$relative] ?? [], true)) {
                    $unchecked[] = "{$relative}: {$fn}() builds an attribute value but is not in ATTRIBUTE_ESCAPERS";
                }
            }
        }

        // Control: if the pattern stopped matching, the loop body never ran and
        // this test would pass while checking nothing.
        $this->assertGreaterThan(
            0,
            $seen,
            'found no attribute interpolations at all — the detection pattern has broken, so this guard is vacuous'
        );

        $this->assertSame(
            [],
            array_unique($unchecked),
            "New attribute interpolation with an escaper this guard does not know about. Either add it to "
            . "ATTRIBUTE_ESCAPERS (and make sure it escapes quotes) or use textContent instead of "
            . "building HTML:\n  " . implode("\n  ", array_unique($unchecked))
        );
    }
}
