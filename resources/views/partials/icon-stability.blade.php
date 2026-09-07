{{--
    Icon stability — stop the Bootstrap Icons glyphs from flickering in a beat
    after the surrounding text on customer views.

    /vendor/bootstrap-icons.css declares the "bootstrap-icons" @font-face with
    `font-display: block`, so the browser only paints a glyph once its woff2 is
    in hand. Left to itself that fetch starts *after* the stylesheet is parsed,
    which is why buttons and nav links render their label first and then pop an
    icon in — a visible layout shift.

    1. PRELOAD the woff2 so the fetch runs in parallel with CSS parsing and the
       real glyphs are usually ready at first paint. `crossorigin` is mandatory
       on a font preload even for a same-origin file (the Fetch spec always uses
       CORS mode for fonts; without it Chrome double-fetches). The href is the
       exact URL bootstrap-icons.css requests — keep the cache-buster in sync if
       the vendored font is bumped.

    2. RESERVE the glyph box. `min-width: 1em` + centering on the ::before means
       every `.bi` occupies its final width from the very first paint, before
       the font resolves, so nothing reflows horizontally when it swaps in.
--}}
<link rel="preload" href="/vendor/fonts/bootstrap-icons.woff2?1bb88866b4085542c8ed5fb61b9393dd" as="font" type="font/woff2" crossorigin>
<style>
    .bi::before,
    [class^="bi-"]::before,
    [class*=" bi-"]::before {
        min-width: 1em;
        text-align: center;
    }
</style>
