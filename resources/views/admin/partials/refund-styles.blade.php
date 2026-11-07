{{-- Styles for the Refunds list and a refund's detail page. Plain CSS like
     the rest of the admin panel; tokens match Completed Orders (co-*). --}}
<link href="/vendor/gfonts.css" rel="stylesheet">
<style>
    .rf-header, .rf-card, .rf-tabs, .rf-detail { font-family: 'Karla', system-ui, sans-serif; color: #3B2A24; }

    .rf-header { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 0.75rem; margin-bottom: 1rem; }
    .rf-title { font-family: 'Fraunces', Georgia, serif; font-weight: 700; font-size: 1.75rem; letter-spacing: -0.01em; margin: 0; color: #3B2A24; }
    .rf-sub { margin: 0.15rem 0 0; font-size: 0.82rem; color: #8B7A72; }
    .rf-owed-total {
        margin: 0; display: inline-flex; align-items: center; gap: 0.4rem;
        font-size: 0.8rem; font-weight: 700; color: #7C2D12;
        background: #FDF1E6; border: 1px solid #F2C9B4; border-radius: 999px; padding: 0.4rem 0.8rem;
    }
    .rf-alert {
        margin-bottom: 1rem; padding: 0.7rem 0.9rem; border-radius: 12px;
        background: #FBE7E4; color: #8B1A1A; border: 1px solid #F6C9C1; font-size: 0.82rem; font-weight: 600;
    }

    .rf-tabs { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.8rem; }
    .rf-tab {
        display: inline-flex; align-items: center; gap: 0.4rem; min-height: 38px;
        padding: 0.4rem 0.85rem; border-radius: 999px; border: 1px solid #F0E2D5; background: #fff;
        font-size: 0.8rem; font-weight: 700; color: #5B4740; text-decoration: none;
    }
    .rf-tab:hover { border-color: #F4845F; color: #C0392B; }
    .rf-tab.is-active { background: linear-gradient(135deg, #F4845F, #EF8585); color: #fff; border-color: transparent; }
    .rf-tab-count { font-size: 0.7rem; background: rgba(0,0,0,.08); border-radius: 999px; padding: 0.05rem 0.45rem; }
    .rf-tab.is-active .rf-tab-count { background: rgba(255,255,255,.25); }

    .rf-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem;
        font-family: 'Karla', system-ui, sans-serif; font-size: 0.78rem; font-weight: 700;
        padding: 0.55rem 1rem; border-radius: 10px; border: 1px solid transparent;
        cursor: pointer; text-decoration: none; line-height: 1.1; white-space: nowrap;
    }
    .rf-btn-sm { padding: 0.4rem 0.7rem; font-size: 0.72rem; border-radius: 8px; }
    .rf-btn-solid { background: linear-gradient(135deg, #F4845F, #EF8585); color: #fff; }
    .rf-btn-solid:hover { background: linear-gradient(135deg, #EF8585, #C0392B); color: #fff; }
    .rf-btn-ghost { background: #fff; color: #C0392B; border-color: #F6B49B; }
    .rf-btn-ghost:hover { background: #FDF6EF; color: #C0392B; }
    .rf-btn-done { background: #2E7D5B; color: #fff; }
    .rf-btn-done:hover { background: #24654A; color: #fff; }
    .rf-btn.is-disabled { opacity: 0.4; pointer-events: none; }

    .rf-card { background: #fff; border: 1px solid #F0E2D5; border-radius: 16px; box-shadow: 0 10px 30px -24px rgba(59,42,36,.45); margin-bottom: 1rem; }
    .rf-filter-card { padding: 1rem 1.1rem; background: linear-gradient(180deg, #FDF6EF, #fff); }
    .rf-filter { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 160px), 1fr)); gap: 0.75rem; align-items: end; }
    .rf-field { display: flex; flex-direction: column; gap: 0.3rem; min-width: 0; }
    .rf-field-search { grid-column: span 2; }
    .rf-field-actions { flex-direction: row; gap: 0.5rem; flex-wrap: wrap; }
    .rf-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #8B7A72; }
    .rf-input {
        font-family: 'Karla', system-ui, sans-serif; font-size: 0.82rem; color: #3B2A24;
        background: #fff; border: 1px solid #F0E2D5; border-radius: 10px; padding: 0.5rem 0.6rem; width: 100%; margin: 0; min-width: 0;
    }
    .rf-input:focus { outline: none; border-color: #F4845F; box-shadow: 0 0 0 3px rgba(244,132,95,.18); }

    /* position: relative keeps the visually-hidden header (.rf-sr) inside
       this scroll box — without it that absolute span escaped the box and
       widened the whole page. */
    .rf-table-card { padding: 0; overflow-x: auto; position: relative; }
    .rf-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.8rem; }
    .rf-table thead th {
        background: #FDF1E6; font-size: 0.66rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em;
        color: #C0392B; padding: 0.7rem 0.5rem; white-space: normal; line-height: 1.3; border-bottom: 1px solid #F0E2D5; text-align: left; vertical-align: bottom;
    }
    /* One line per cell: a wide list scrolls inside its card (like Completed
       Orders) instead of breaking names and emails letter by letter. */
    .rf-table tbody td { padding: 0.7rem 0.5rem; border-bottom: 1px solid #F7EFE7; vertical-align: middle; white-space: nowrap; }
    .rf-table tbody td.rf-name { white-space: normal; min-width: 6.5rem; }
    .rf-order-no a { color: inherit; text-decoration: none; }
    .rf-order-no a:hover { text-decoration: underline; }
    /* Date over time on desktop, so the row fits a laptop without hiding
       the View / Mark Refunded buttons off to the right. */
    .rf-time { display: block; color: #8B7A72; font-size: 0.7rem; }
    .rf-table tbody tr.rf-row:hover td { background: #FFFBF7; }
    .rf-right { text-align: right !important; }
    .rf-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
    .rf-amount { font-weight: 700; }
    .rf-order-no { font-family: 'Fraunces', Georgia, serif; font-weight: 600; color: #C0392B; white-space: nowrap; }
    .rf-date { font-size: 0.75rem; color: #5B4740; white-space: nowrap; }
    .rf-muted { color: #A8978E; }
    .rf-block { display: block; font-size: 0.7rem; overflow-wrap: anywhere; }
    /* View above Mark Refunded: a narrow last column keeps both buttons on
       screen at laptop widths. */
    .rf-actions { white-space: nowrap; }
    .rf-actions > * { display: flex; }
    .rf-actions .rf-btn { width: 100%; }
    .rf-actions > * + * { margin-top: 0.35rem; }
    .rf-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }

    .rf-badge { display: inline-block; font-size: 0.68rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 999px; white-space: nowrap; }
    .rf-badge-owed { background: #FDF1E6; color: #7C2D12; border: 1px solid #F2C9B4; }
    .rf-badge-done { background: #E6F4EC; color: #2E7D5B; border: 1px solid #C6E6D5; }

    .rf-empty { text-align: center; padding: 2.5rem 1rem; color: #8B7A72; }
    .rf-empty i { font-size: 1.6rem; color: #F6B49B; }
    .rf-empty p { font-family: 'Fraunces', Georgia, serif; font-size: 1rem; font-weight: 600; margin: 0.5rem 0 0.2rem; color: #3B2A24; }
    .rf-empty span { font-size: 0.78rem; }

    .rf-pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.6rem; padding: 0.8rem 1rem; border-top: 1px solid #F0E2D5; font-size: 0.76rem; color: #8B7A72; font-weight: 600; }
    .rf-pager-nav { display: inline-flex; gap: 0.35rem; }

    /* ── Detail page ── */
    .rf-detail-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
    .rf-section { padding: 1.1rem 1.2rem; }
    .rf-section-title { font-family: 'Fraunces', Georgia, serif; font-size: 1.05rem; font-weight: 700; margin: 0 0 0.8rem; color: #3B2A24; }
    .rf-dl { display: grid; grid-template-columns: minmax(0, 11rem) minmax(0, 1fr); gap: 0.55rem 1rem; margin: 0; font-size: 0.84rem; }
    .rf-dl dt { color: #8B7A72; font-weight: 700; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; padding-top: 0.15rem; }
    .rf-dl dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
    .rf-items { list-style: none; margin: 0; padding: 0; }
    .rf-items li { display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px dashed #F0E2D5; font-size: 0.84rem; }
    .rf-items li:last-child { border-bottom: none; }
    .rf-total-line { display: flex; justify-content: space-between; gap: 0.75rem; padding-top: 0.6rem; margin-top: 0.4rem; border-top: 1px solid #F0E2D5; font-weight: 800; }
    .rf-timeline { list-style: none; margin: 0; padding: 0; }
    .rf-timeline li { position: relative; padding: 0 0 0.9rem 1.4rem; font-size: 0.84rem; }
    .rf-timeline li::before { content: ''; position: absolute; left: 0.3rem; top: 0.35rem; width: 0.55rem; height: 0.55rem; border-radius: 50%; background: #F6B49B; }
    .rf-timeline li.is-done::before { background: #2E7D5B; }
    .rf-timeline li.is-waiting::before { background: #fff; border: 2px solid #7C2D12; }
    .rf-timeline .rf-when { display: block; font-size: 0.74rem; color: #8B7A72; }
    .rf-callout { padding: 0.9rem 1rem; border-radius: 12px; font-size: 0.85rem; line-height: 1.5; }
    .rf-callout-owed { background: #FDF1E6; border: 1px solid #F2C9B4; color: #7C2D12; }
    .rf-callout-done { background: #E6F4EC; border: 1px solid #C6E6D5; color: #24654A; }
    .rf-detail-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.9rem; }

    @media (min-width: 1024px) {
        .rf-detail-grid { grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); align-items: start; }
    }

    /* ── Phone: table rows become cards ── */
    @media (max-width: 767px) {
        .rf-title { font-size: 1.4rem; }
        .rf-field-search { grid-column: auto; }
        .rf-table-card { border: none; background: transparent; box-shadow: none; overflow: visible; }
        .rf-table thead { display: none; }
        .rf-table, .rf-table tbody, .rf-table tr, .rf-table td { display: block; width: 100%; }
        .rf-table tbody tr.rf-row {
            background: #fff; border: 1px solid #F0E2D5; border-radius: 14px;
            box-shadow: 0 10px 26px -24px rgba(59,42,36,.5); padding: 0.35rem 0.15rem; margin-bottom: 0.7rem;
        }
        .rf-table tbody td {
            display: grid; grid-template-columns: 40% minmax(0, 60%); gap: 0.5rem; align-items: center;
            border-bottom: 1px dashed #F7EFE7; padding: 0.5rem 0.85rem; text-align: left !important; white-space: normal;
        }
        .rf-table tbody td:last-child { border-bottom: none; }
        .rf-table tbody td::before {
            content: attr(data-label); font-size: 0.66rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #8B7A72;
        }
        .rf-table tbody td > .rf-badge { justify-self: start; }
        .rf-time { display: inline; font-size: inherit; }
        .rf-table tbody td[data-label=""] { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .rf-table tbody td[data-label=""]::before { content: none; }
        .rf-actions > * + * { margin-top: 0; }
        .rf-actions .rf-btn { min-height: 44px; flex: 1 1 auto; }
        .rf-actions form { flex: 1 1 auto; }
        .rf-actions form .rf-btn { width: 100%; }
        .rf-empty-row td { padding: 0; }
        .rf-pager { background: #fff; border: 1px solid #F0E2D5; border-radius: 14px; }
        .rf-dl { grid-template-columns: minmax(0, 1fr); gap: 0.15rem; }
        .rf-dl dd { margin-bottom: 0.45rem; }
    }
</style>
