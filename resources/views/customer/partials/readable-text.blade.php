{{--
    Readable secondary text — the single source of truth for muted/helper text
    colour on every CUSTOMER-facing page. Admin views do not include this.

    Why this file exists
    --------------------
    Secondary text on the customer pages was written as Tailwind opacity
    utilities — text-peach-deep/50, text-peach-red/70, text-peach/40 and so on.
    The alpha is baked into the class name, so there was no variable to turn:
    the colour was effectively hardcoded once per element, spread across eleven
    standalone Blade documents. Measured against white, those alphas landed
    between 1.4:1 and 3.2:1 contrast — below the 4.5:1 WCAG AA minimum for body
    text, and on the small type used for labels and helper text they were
    genuinely hard to read.

    Rather than edit hundreds of individual class names (which would miss any
    page not audited, and drift again the next time someone adds a page), this
    file overrides those utilities once. Every customer page that includes it
    picks the fix up automatically, including pages added later, as long as they
    keep using the same utility names.

    How the override wins
    ---------------------
    Tailwind emits its utilities inside `@layer utilities`. Unlayered CSS — the
    plain rules below — beats any layered rule regardless of specificity, so no
    !important is needed and normal specificity still works for one-off
    exceptions written after this include.

    Ratios below are measured against the two backgrounds these classes actually
    sit on: white cards (#FFFFFF) and the peach-soft panels (#FDE8DE).
--}}
<style>
    :root {
        /* Secondary text, strongest step — replaces the /70–/85 alphas.
           11.85:1 on white, 10.03:1 on peach-soft. */
        --text-secondary-strong: #5A2920;

        /* Standard muted text — replaces the /50–/69 alphas.
           8.69:1 on white, 7.36:1 on peach-soft. */
        --text-secondary: #6B4038;

        /* Faintest step, for de-emphasised meta and placeholders — replaces
           the /25–/49 alphas. 6.62:1 on white, 5.60:1 on peach-soft. */
        --text-secondary-faint: #7F5148;

        /* Red accent kept red rather than flattened to brown, so brand
           eyebrows and accents do not lose their identity. */
        --text-accent-strong: #A32D22;  /* 7.10:1 on white */
        --text-accent: #B03123;         /* 6.35:1 on white */

        /* The light peach (#F4845F) is unreadable on white at any alpha
           (1.4–1.7:1). Warmed and darkened instead of merely nudged. */
        --text-peach-readable: #9C4A2E; /* 6.12:1 on white */

        /* On coloured/photo backgrounds, near-solid white instead of 60%. */
        --text-on-color: rgba(255, 255, 255, 0.92);
    }

    /* ── peach-deep family ── */
    .text-peach-deep\/80,
    .text-peach-deep\/70 { color: var(--text-secondary-strong); }

    .text-peach-deep\/65,
    .text-peach-deep\/60,
    .text-peach-deep\/55,
    .text-peach-deep\/50 { color: var(--text-secondary); }

    .text-peach-deep\/45,
    .text-peach-deep\/40,
    .text-peach-deep\/35,
    .text-peach-deep\/30,
    .text-peach-deep\/25 { color: var(--text-secondary-faint); }

    .placeholder\:text-peach-deep\/35::placeholder { color: var(--text-secondary-faint); }

    /* ── peach-red family ── */
    .text-peach-red\/85,
    .text-peach-red\/80,
    .text-peach-red\/75,
    .text-peach-red\/70 { color: var(--text-accent-strong); }

    .text-peach-red\/50,
    .text-peach-red\/40 { color: var(--text-accent); }

    /* ── peach family ── */
    .text-peach\/60,
    .text-peach\/50,
    .text-peach\/40 { color: var(--text-peach-readable); }

    /* ── on coloured backgrounds ── */
    .text-white\/60 { color: var(--text-on-color); }
</style>
