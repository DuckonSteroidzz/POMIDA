{{--
    Typography stability — keep the LETTERS from jumping on navigation.

    Include this ONCE, in <head>, on every full-document page (layouts and the
    standalone blade documents alike). It does two things, both additive and
    both invisible once the page has settled:

    1. NATIVE CROSS-DOCUMENT VIEW TRANSITIONS.
       Every navigation in this app is a real server-side full-page load. In
       Chromium, `@view-transition { navigation: auto }` cross-fades the old
       document into the new one, so the sub-pixel reflow that happens while
       the web font swaps in is blended away instead of read as a "jump".
       Non-Chromium browsers ignore the rule and navigate exactly as before —
       no behaviour change, no layout change, no colour change.

    2. WEB-FONT PRELOAD.
       The three self-hosted families (Fraunces, Karla, Poppins) are declared
       in /vendor/gfonts.css with `font-display: swap`, so until the woff2 is
       in hand the browser paints a fallback and then reflows to the real
       face — that reflow is the text shift being reported. Preloading the
       Latin (U+0000–00FF) woff2 of each family starts the fetch in parallel
       with CSS parsing instead of after it, so the real face is usually ready
       before first paint and there is no visible swap.

       `crossorigin` is required on a font preload even for a same-origin file
       (the Fetch spec always uses CORS mode for fonts; without it Chrome
       double-fetches). The hrefs below are the exact URLs gfonts.css requests
       for the Latin subset — keep them in sync if the vendored fonts are
       bumped. All three files carry `Cache-Control: immutable` (public/.htaccess),
       so a warm cache satisfies the preload with no network at all.

       Every family is preloaded on every page on purpose: admin/staff screens
       use Fraunces + Karla but also render Poppins (modal buttons), and the
       customer screens use Poppins but also render Fraunces + Karla (games,
       receipts, order cards). Keeping one shared list avoids per-page drift.
--}}
<link rel="preload" href="/vendor/gfonts/6NU78FyLNQOQZAnv9bYEvDiIdE9Ea92uemAk_WBq8U_9v0c2Wa0KxC9TeA.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/vendor/gfonts/qkB9XvYC6trAT55ZBi1ueQVIjQTD-JrIH2G7nytkHRyQ8p4wUje6bg.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/vendor/gfonts/pxiEyp8kv8JHgFVrJJfecg.woff2" as="font" type="font/woff2" crossorigin>
<style>
    @view-transition { navigation: auto; }
</style>

{{--
    Touch press feedback — MOBILE ONLY.

    On a phone there is no hover, so tapping a category tile, a product card,
    the Add button, the quantity steppers or Place Order produced no visible
    reaction and the tap felt dead. Desktop already covers this with :hover.

    EVERY rule below lives inside `@media (hover: none) and (pointer: coarse)`
    so a mouse device — including a touchscreen laptop that also has a mouse —
    never matches it and desktop stays byte-for-byte identical. Nothing here
    is a new class: the selectors are the classes/attributes that already
    exist in customer/menu.blade.php, customer/item-details.blade.php and
    customer/cart.blade.php (the only pages carrying these hooks).

    iOS Safari only fires :active when the element has a touch affordance.
    Every target already has `cursor: pointer` — from the shared
    `button:not(:disabled), [onclick] { cursor: pointer }` base rule on the
    menu/item/cart pages, from the explicit `cursor-pointer` class on
    .category-card, and from the UA anchor default on the <a> cards — so no
    new markup or listener is needed here.
--}}
<style>
    @media (hover: none) and (pointer: coarse) {
        .category-card,
        .item-grid-card,
        .menu-search-card {
            transition: transform 0.1s ease, filter 0.1s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .category-card:active,
        .item-grid-card:active,
        .menu-search-card:active {
            transform: scale(0.97);
            filter: brightness(0.92);
        }

        #addToCartForm button[type="submit"],
        button[onclick^="changeQuantity"],
        button[onclick="openOrderConfirmation()"] {
            transition: transform 0.1s ease, opacity 0.1s ease;
            -webkit-tap-highlight-color: transparent;
        }
        #addToCartForm button[type="submit"]:active:not(:disabled),
        button[onclick^="changeQuantity"]:active,
        button[onclick="openOrderConfirmation()"]:active:not(:disabled) {
            transform: scale(0.95);
            opacity: 0.9;
        }

        @media (prefers-reduced-motion: reduce) {
            .category-card,
            .item-grid-card,
            .menu-search-card,
            #addToCartForm button[type="submit"],
            button[onclick^="changeQuantity"],
            button[onclick="openOrderConfirmation()"] {
                transition: none;
            }
        }
    }
</style>
