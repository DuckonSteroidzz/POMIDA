<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Admin sidebar icons flickering/disappearing on click (2026-09-02).
 *
 * INVESTIGATION
 * -------------
 * Not a Blade/Livewire re-render: this codebase has no Livewire and no
 * Alpine anywhere in the admin tree (grepped the whole directory — neither
 * dependency is even in composer.json), and the sidebar is plain
 * server-rendered <a href> links with no JS touching it at all. Every
 * sidebar click is a genuine full-page navigation, so a real repaint is
 * inherent to this app's architecture, not a bug in the usual sense.
 *
 * What made a repaint read as icons specifically "disappearing" (labels
 * staying put, icons blanking) rather than an ordinary page flash is
 * bootstrap-icons.css's `font-display: block`, which hides icon glyphs
 * until the font is ready. An earlier pass found the font had NO
 * Cache-Control header at all, so every navigation re-fetched it from
 * scratch — fixed with a long-lived header in public/.htaccess (pinned by
 * StaticAssetCachingTest).
 *
 * This pass verified that fix against a REAL Apache instance rather than
 * `php artisan serve` (which never applies .htaccess and was the wrong
 * thing to trust the first time) — confirmed 200 with `Cache-Control:
 * public, max-age=31536000, immutable` on both bootstrap-icons.css and its
 * .woff2. With the font now cached, what is left is the residual latency of
 * the browser only starting the font fetch after parsing the CSS that
 * declares it — a preload hint collapses that into one parallel step. This
 * is the additional, non-hacky improvement for this pass; it cannot be
 * exercised by a PHPUnit test (it is genuinely a browser-timing effect), so
 * these tests pin the markup shape a preload hint requires to work at all,
 * which a manual repro can't catch as easily as a broken tag or a missing
 * attribute silently doing nothing.
 */
class AdminSidebarIconFlickerTest extends TestCase
{
    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    public function test_the_icon_font_is_preloaded_ahead_of_the_stylesheet_that_declares_it(): void
    {
        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        $preloadPos = strpos($html, 'rel="preload"');
        $this->assertNotFalse($preloadPos, 'the admin layout has no preload hint for the icon font');

        $cssPos = strpos($html, 'href="/vendor/bootstrap-icons.css"');
        $this->assertNotFalse($cssPos);

        // Ordering matters: a preload discovered AFTER the stylesheet that
        // needs it defeats the point — the browser would already be parsing
        // the CSS by the time it learns the font is coming.
        $this->assertLessThan(
            $cssPos,
            $preloadPos,
            'the preload hint must appear before the bootstrap-icons.css link, or it cannot start the fetch any earlier'
        );
    }

    public function test_the_preload_targets_the_actual_font_file_with_the_required_attributes(): void
    {
        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<link[^>]+rel="preload"[^>]+href="\/vendor\/fonts\/bootstrap-icons\.woff2(\?[0-9a-f]+)?"[^>]*>/',
            $html,
            'the preload does not point at the actual icon font file'
        );

        // The preload href must match the URL bootstrap-icons.css actually
        // requests byte-for-byte — query string included. A mismatch means the
        // browser downloads the font twice and the preload never satisfies the
        // @font-face, defeating the point entirely.
        preg_match('/url\(\"\.\/fonts\/bootstrap-icons\.woff2(\?[0-9a-f]+)?\"\)/', file_get_contents(public_path('vendor/bootstrap-icons.css')), $cssMatch);
        $this->assertNotEmpty($cssMatch, 'could not find the woff2 url in bootstrap-icons.css');
        $this->assertStringContainsString(
            'href="/vendor/fonts/bootstrap-icons.woff2' . ($cssMatch[1] ?? '') . '"',
            $html,
            'the preload href must match the exact woff2 URL (query string included) that bootstrap-icons.css requests'
        );

        // Required even for a same-origin font — the Fetch spec always uses
        // CORS mode for fonts, and Chrome silently double-fetches without
        // this, which would make the preload pure waste rather than harmless.
        $this->assertMatchesRegularExpression(
            '/<link[^>]+rel="preload"[^>]+crossorigin[^>]*>/',
            $html,
            'a font preload without crossorigin is silently ignored/duplicated by Chrome'
        );

        $this->assertMatchesRegularExpression(
            '/<link[^>]+rel="preload"[^>]+as="font"[^>]*>/',
            $html,
            'a font preload without as="font" is not prioritised as a font fetch'
        );
    }

    public function test_the_woff_fallback_is_not_preloaded_since_no_supported_browser_requests_it(): void
    {
        // bootstrap-icons.css lists woff2 first, and every browser Bootstrap
        // Icons still targets supports it — preloading the .woff as well
        // would just add a fetch nothing uses.
        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('bootstrap-icons.woff"', $html);
    }

    /**
     * Confirms the investigation finding, not just the fix: nothing in the
     * admin tree can re-render the sidebar out of a JS framework's own
     * accord, because no such framework is in this project at all. If this
     * ever starts failing, an actual Livewire/Alpine re-render bug becomes
     * possible here and the flicker's cause needs re-investigating from
     * scratch rather than assumed to still be the font.
     */
    public function test_no_js_reactivity_framework_is_present_to_re_render_the_sidebar(): void
    {
        $composerLock = file_get_contents(base_path('composer.json'));

        $this->assertStringNotContainsString('livewire/livewire', $composerLock);

        $layoutSource = file_get_contents(resource_path('views/admin/layout.blade.php'));

        $this->assertStringNotContainsString('wire:', $layoutSource);
        $this->assertStringNotContainsString('x-data', $layoutSource);
    }
}
