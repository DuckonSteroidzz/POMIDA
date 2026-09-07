<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * public/vendor/bootstrap-icons.css sets `font-display: block`, which hides
 * icon glyphs until the icon font finishes loading (or ~3s passes). With no
 * Cache-Control/Expires header on the font/CSS files, every full-page
 * navigation re-fetched them from scratch instead of the browser's disk
 * cache — producing a visible icons-disappear-then-reappear flash on every
 * admin sidebar click. Fixed by giving static assets a long, immutable
 * cache in public/.htaccess (2026-09-02). This test pins that fix in place
 * so it can't quietly regress — it can't test real Apache behaviour, but it
 * can catch someone removing or narrowing the directives later.
 */
class StaticAssetCachingTest extends TestCase
{
    public function test_the_htaccess_caches_static_assets_long_term(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString(
            '<IfModule mod_expires.c>',
            $htaccess,
            'public/.htaccess should set Expires headers for static assets'
        );

        $this->assertStringContainsString(
            'Header set Cache-Control "public, max-age=31536000, immutable"',
            $htaccess,
            'static assets (icon font included) should be cached long-term so full-page '
            . 'navigations do not re-fetch them and flash icons on every click'
        );

        // The rule actually has to cover the icon font itself, not just CSS.
        $this->assertMatchesRegularExpression(
            '/FilesMatch[^>]*woff2?/',
            $htaccess,
            'the cache rule must cover .woff/.woff2, or the icon font itself stays uncached'
        );
    }

    public function test_the_icon_font_still_uses_a_deliberate_font_display(): void
    {
        $css = file_get_contents(public_path('vendor/bootstrap-icons.css'));

        // Not asserting a specific value — just that this stays a conscious
        // choice, not an accidental removal. If this ever changes, it should
        // be because someone decided to, not because it silently vanished.
        $this->assertMatchesRegularExpression('/font-display:\s*\w+/', $css);
    }
}
