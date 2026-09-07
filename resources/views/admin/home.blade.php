@extends('admin.layout')

@section('title', 'Home - Peachy Admin')

@section('content')

<style>
    .pc-wrap {
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        color: var(--pc-cocoa, #5A2920);
        padding: 0.25rem 0 2rem;
    }

    @media (min-width: 1000px) {
        .pc-wrap.has-notifications {
            margin-right: 355px;
        }
    }

    .pc-wrap h1, .pc-wrap .pc-title { font-family: 'Fraunces', Georgia, serif; letter-spacing: -0.01em; }

    /* Header */
    .pc-header {
        display: flex; flex-wrap: wrap; align-items: flex-end;
        justify-content: space-between; gap: 0.75rem; margin-bottom: 1.25rem;
    }
    .pc-header .pc-title { font-size: clamp(1.4rem, 3.2vw, 2rem); font-weight: 700; color: #8B1A1A; margin: 0; }
    .pc-header .pc-sub { font-size: 0.85rem; color: #8A6A61; margin: 0.2rem 0 0; }
    .pc-live {
        display: inline-flex; align-items: center; gap: 0.45rem;
        background: #FDE8DE; color: #8B1A1A; border-radius: 999px;
        padding: 0.4rem 0.85rem; font-size: 0.75rem; font-weight: 700;
    }
    .pc-live .dot { width: 8px; height: 8px; border-radius: 50%; background: #C0392B; animation: pcPulse 1.6s ease-in-out infinite; }

    .pc-card {
        background: #fff; border: 1px solid rgba(138, 106, 97, 0.14);
        border-radius: 16px; box-shadow: 0 6px 20px rgba(90, 41, 32, 0.08);
        padding: 1.1rem 1.15rem; margin-bottom: 1.25rem;
    }
    .pc-section-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.9rem; }
    .pc-section-title {
        font-family: 'Fraunces', Georgia, serif; font-size: 1.05rem; font-weight: 700;
        color: #8B1A1A; margin: 0; display: flex; align-items: center; gap: 0.5rem;
    }
    .pc-count { background: #C0392B; color: #fff; border-radius: 999px; padding: 0.1rem 0.6rem; font-size: 0.72rem; font-weight: 700; }

    /* ── Subtle overlay notifications ── */
    .pc-toasts {
        position: fixed;
        top: 6.9rem;
        right: 1.15rem;
        z-index: 1050;
        width: min(335px, calc(100vw - 2rem));
        max-height: calc(100vh - 8.2rem);
        overflow-y: auto;
        background: #FFFDF9;
        border: 1px solid rgba(138, 106, 97, 0.14);
        border-radius: 16px;
        box-shadow: 0 8px 28px rgba(90, 41, 32, 0.10);
        padding: 0.85rem;
        display: flex;
        flex-direction: column;
        gap: 0.55rem;
        pointer-events: none;
        scrollbar-width: thin;
    }
    .pc-toasts > * { pointer-events: auto; }

    .pc-toast-head {
        display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
        background: transparent;
        color: #8B1A1A;
        border-radius: 10px;
        padding: 0.2rem 0.15rem 0.35rem;
        font-family: 'Fraunces', Georgia, serif;
        font-size: 0.98rem;
        font-weight: 700;
    }
    .pc-toast-head button {
        background: none; border: none; color: inherit; cursor: pointer;
        font-size: 0.95rem; line-height: 1; opacity: 0.75; padding: 0;
    }
    .pc-toast-head button:hover { opacity: 1; }

    .pc-toast {
        background: #FFFDF9;
        border: 1px solid rgba(138, 106, 97, 0.16);
        border-left: 3px solid #F4845F;
        border-radius: 12px;
        padding: 0.75rem 0.8rem;
        box-shadow: 0 4px 14px rgba(90, 41, 32, 0.06);
        animation: pcNotificationDrop 0.28s ease both;
    }
    .pc-toast.is-assisting { border-left-color: #2E7D5B; }

    .pc-toast-title {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 0.85rem; font-weight: 700; color: #5A2920; margin: 0;
    }
    .pc-toast-branch { font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 0.68rem; font-weight: 500; color: #8A6A61; margin-left: 0.3rem; }
    .pc-toast-meta { font-size: 0.7rem; color: #8A6A61; margin: 0.1rem 0 0.45rem; }
    .pc-toast-actions { display: flex; gap: 0.35rem; }
    .pc-toast-actions form { margin: 0; flex: 1; }
    .pc-toast-actions .pc-btn { padding: 0.32rem 0.55rem; font-size: 0.7rem; border-radius: 8px; }

    body.pc-toasts-hidden .pc-toast { display: none; }

    /* Buttons */
    .pc-btn {
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; border: none; border-radius: 10px;
        padding: 0.5rem 0.9rem; font-size: 0.76rem; font-weight: 700; color: #fff;
        cursor: pointer; transition: transform .12s ease, filter .12s ease;
        display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; width: 100%;
    }
    .pc-btn:hover { filter: brightness(1.06); transform: translateY(-1px); }
    .pc-btn-peach { background: #F4845F; }
    .pc-btn-prepare { background: #E88A6E; }
    .pc-btn-serve { background: #8B1A1A; }
    .pc-btn-done { background: #2E7D5B; }
    .pc-btn-cancel { background: transparent; color: #C0392B; border: 1.5px solid rgba(192,57,43,0.4); }
    .pc-btn-cancel:hover { background: #C0392B; color: #fff; }

    /* Orders grid */
    .pc-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(310px, 340px));
        gap: 1rem;
        align-items: stretch;
        justify-content: start;
    }

    .pc-order {
        width: 100%;
        max-width: 340px;
        display: flex; flex-direction: column; background: #fff;
        border: 1px solid rgba(138, 106, 97, 0.16); border-radius: 16px; overflow: hidden;
        box-shadow: 0 6px 20px rgba(90, 41, 32, 0.08);
        transition: transform .15s ease, box-shadow .15s ease;
        position: relative;
    }
    .pc-order:hover { transform: translateY(-2px); box-shadow: 0 12px 26px rgba(90,41,32,0.13); }
    .pc-order.needs-help { border-color: rgba(192, 57, 43, 0.5); }

    /* subtle corner flag instead of loud banner */
    .pc-flag {
        position: absolute; top: 0.6rem; right: 0.6rem; z-index: 2;
        display: inline-flex; align-items: center; gap: 0.3rem;
        background: rgba(192, 57, 43, 0.1);
        color: #C0392B;
        border: 1px solid rgba(192, 57, 43, 0.25);
        border-radius: 999px; padding: 0.15rem 0.55rem;
        font-size: 0.64rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;
    }
    .pc-flag .dot { width: 6px; height: 6px; border-radius: 50%; background: #C0392B; animation: pcPulse 1.6s ease-in-out infinite; }

    .pc-order-top { padding: 0.85rem 1rem; background: #FDE8DE; border-bottom: 1px solid rgba(138,106,97,0.12); }
    .pc-order-type {
        font-family: 'Fraunces', Georgia, serif; font-size: 0.95rem; font-weight: 700;
        color: #8B1A1A; margin: 0; display: flex; align-items: center; gap: 0.4rem;
    }
    .pc-order-num { font-size: 0.72rem; color: #8A6A61; margin: 0.15rem 0 0.5rem; letter-spacing: 0.03em; }
    .pc-status {
        display: inline-block; border-radius: 999px; padding: 0.18rem 0.7rem;
        font-size: 0.66rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #fff;
    }

    .pc-items { padding: 0.85rem 1rem; display: grid; gap: 0.35rem; flex: 1; }
    .pc-item { display: flex; justify-content: space-between; gap: 0.75rem; font-size: 0.82rem; color: #5A2920; margin: 0; }
    .pc-item .qty { color: #C0392B; font-weight: 700; }
    .pc-item .price { font-weight: 600; white-space: nowrap; }

    .pc-view-order {
        width: 100%;
        margin-top: 0.2rem;
        padding: 0.48rem 0.6rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        border: 1px dashed rgba(192, 57, 43, 0.28);
        border-radius: 9px;
        background: #FFF7F3;
        color: #8B1A1A;
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 0.7rem;
        font-weight: 700;
        cursor: pointer;
        transition: background .15s ease, border-color .15s ease, transform .12s ease;
    }

    .pc-view-order:hover {
        background: #FDE8DE;
        border-color: rgba(192, 57, 43, 0.45);
        transform: translateY(-1px);
    }

    .pc-view-order i {
        font-size: 0.72rem;
    }

    .pc-totals { padding: 0.7rem 1rem; background: #FFFDF9; border-top: 1px dashed rgba(138,106,97,0.3); display: grid; gap: 0.2rem; }
    .pc-row { display: flex; justify-content: space-between; font-size: 0.78rem; color: #8A6A61; }
    .pc-row.discount { color: #2E7D5B; }
    .pc-row.total { font-family: 'Fraunces', Georgia, serif; font-size: 1.05rem; font-weight: 700; color: #8B1A1A; margin-top: 0.2rem; }

    .pc-actions {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.5rem;
        padding: 0.85rem 1rem 0.55rem;
        align-items: stretch;
    }

    .pc-actions form {
        margin: 0;
        min-width: 0;
        display: flex;
    }

    .pc-actions .pc-btn {
        min-height: 38px;
        width: 100%;
        padding: 0.5rem 0.55rem;
        border-radius: 10px;
        font-size: 0.7rem;
        line-height: 1.1;
        white-space: normal;
        text-align: center;
        box-shadow: none;
    }

    .pc-actions .pc-btn-done {
        background: #2E7D5B;
        color: #fff;
        border: 1px solid #2E7D5B;
    }

    .pc-actions .pc-btn-done:hover {
        background: #24664A;
        border-color: #24664A;
    }

    .pc-actions .pc-btn-cancel {
        background: #fff;
        color: #C0392B;
        border: 1px solid rgba(192,57,43,0.38);
    }

    .pc-actions .pc-btn-cancel:hover {
        background: #FDE8DE;
        color: #A52F24;
        border-color: rgba(192,57,43,0.55);
    }

    .pc-actions form.pc-cancel {
        width: 100%;
        min-width: 0;
    }

    .pc-actions form.pc-cancel .pc-btn {
        width: 100%;
        min-width: 0;
        padding: 0.5rem 0.55rem;
        border-radius: 10px;
        font-size: 0.7rem;
    }

    .pc-discount-actions {
        grid-column: 1 / -1;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        width: 100%;
    }
    .pc-discount-actions-label {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.72rem;
        font-weight: 700;
        color: #8B1A1A;
        padding: 0 0.1rem;
    }
    .pc-discount-actions-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }
    .pc-discount-actions-row form {
        margin: 0;
        display: flex;
    }
    .pc-discount-actions-row .pc-btn {
        width: 100%;
        min-height: 38px;
        padding: 0.5rem 0.55rem;
        border-radius: 10px;
        font-size: 0.7rem;
        line-height: 1.1;
        white-space: normal;
        text-align: center;
    }

    .pc-time { font-size: 0.7rem; color: #8A6A61; text-align: center; padding: 0 1rem 0.85rem; margin: 0; }

    /* Empty state */
    .pc-empty { text-align: center; padding: 3rem 1rem; color: #8A6A61; }
    .pc-empty i { font-size: 2.6rem; color: #F4845F; display: block; margin-bottom: 0.6rem; }
    .pc-empty .t { font-family: 'Fraunces', Georgia, serif; font-size: 1.05rem; font-weight: 700; color: #5A2920; margin: 0; }
    .pc-empty .s { font-size: 0.82rem; margin: 0.3rem 0 0; }

    @keyframes pcPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }
    @keyframes pcNotificationDrop {
        from { opacity: 0; transform: translateY(-12px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    /* ── Discount Card Modal ── */
    .pc-discount-trigger {
        width: 100%;
        border: 1px solid rgba(192,57,43,0.18);
        background: #FDE8DE;
        color: #8B1A1A;
        border-radius: 10px;
        padding: 0.65rem 0.75rem;
        cursor: pointer;
        text-align: left;
        transition: transform .12s ease, filter .12s ease;
    }

    .pc-discount-trigger:hover {
        filter: brightness(1.03);
        transform: translateY(-1px);
    }

    .pc-discount-trigger-main {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        font-size: 0.72rem;
        font-weight: 800;
    }

    .pc-discount-trigger-main i {
        margin-right: 0.25rem;
    }

    .pc-discount-trigger-amount {
        color: #2E7D5B;
        font-size: 0.76rem;
    }

    .pc-discount-trigger-sub {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-top: 0.25rem;
        color: #8A6A61;
        font-size: 0.62rem;
    }


    /* Full Order Details Modal */
    .pc-order-modal {
        position: fixed;
        inset: 0;
        z-index: 1200;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background: rgba(59, 35, 32, 0.48);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
    }

    .pc-order-modal.is-open { display: flex; }

    .pc-order-modal-box {
        width: min(560px, 100%);
        max-height: min(760px, calc(100vh - 2rem));
        overflow: hidden;
        display: flex;
        flex-direction: column;
        background: #FFFDF9;
        border: 1px solid #F2D8CF;
        border-radius: 18px;
        box-shadow: 0 24px 70px rgba(90, 41, 32, 0.24);
        animation: pcOrderModalIn .2s ease-out;
    }

    @keyframes pcOrderModalIn {
        from { opacity: 0; transform: translateY(10px) scale(.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .pc-order-modal-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.15rem;
        background: #FDE8DE;
        border-bottom: 1px solid #F2D8CF;
    }

    .pc-order-modal-title {
        margin: 0;
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.05rem;
        color: #8B1A1A;
    }

    .pc-order-modal-subtitle {
        margin: .2rem 0 0;
        font-size: .72rem;
        color: #8A6A61;
    }

    .pc-order-modal-close {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        border: 1px solid rgba(192,57,43,.25);
        border-radius: 50%;
        background: #fff;
        color: #C0392B;
        cursor: pointer;
        display: grid;
        place-items: center;
    }

    .pc-order-modal-body {
        overflow-y: auto;
        padding: 1rem 1.15rem;
    }

    .pc-order-modal-info {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: .55rem;
        margin-bottom: .9rem;
    }

    .pc-order-modal-info-item {
        padding: .6rem .7rem;
        background: #fff7f3;
        border: 1px solid #f2d8cf;
        border-radius: 10px;
    }

    .pc-order-modal-info-item .label {
        display: block;
        font-size: .62rem;
        color: #8A6A61;
        text-transform: uppercase;
        letter-spacing: .05em;
        font-weight: 700;
    }

    .pc-order-modal-info-item .value {
        display: block;
        margin-top: .15rem;
        font-size: .75rem;
        font-weight: 700;
        color: #5A2920;
    }

    .pc-order-modal-items {
        border: 1px solid #F2D8CF;
        border-radius: 12px;
        overflow: hidden;
    }

    .pc-order-modal-item {
        display: flex;
        justify-content: space-between;
        gap: .75rem;
        padding: .65rem .75rem;
        border-bottom: 1px solid #F5E6E0;
        font-size: .76rem;
        color: #5A2920;
    }

    .pc-order-modal-item:last-child { border-bottom: 0; }

    .pc-order-modal-item .qty {
        color: #C0392B;
        font-weight: 800;
        margin-right: .25rem;
    }

    .pc-order-modal-item .price {
        white-space: nowrap;
        font-weight: 700;
    }

    .pc-order-modal-total {
        margin-top: .8rem;
        padding-top: .7rem;
        border-top: 1px dashed #D9BEB4;
        display: grid;
        gap: .25rem;
    }

    .pc-order-modal-total-row {
        display: flex;
        justify-content: space-between;
        font-size: .75rem;
        color: #8A6A61;
    }

    .pc-order-modal-total-row.final {
        margin-top: .15rem;
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1rem;
        font-weight: 800;
        color: #8B1A1A;
    }

    @media (max-width: 540px) {
        .pc-order-modal { padding: .7rem; }
        .pc-order-modal-box {
            max-height: calc(100vh - 1.4rem);
            border-radius: 16px;
        }
        .pc-order-modal-info { grid-template-columns: 1fr 1fr; }
    }

    .pc-discount-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 10000;
        background: rgba(45, 24, 20, 0.55);
        padding: 1rem;
        align-items: center;
        justify-content: center;
    }

    .pc-discount-modal.is-open {
        display: flex;
    }

    .pc-discount-modal-box {
        width: min(100%, 430px);
        max-height: min(90vh, 700px);
        overflow-y: auto;
        background: #FFFDF9;
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.25);
        border: 1px solid rgba(138,106,97,0.15);
    }

    .pc-discount-modal-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 1rem 1.1rem;
        background: #FDE8DE;
        border-bottom: 1px solid #ead8d2;
    }

    .pc-discount-modal-head h3 {
        margin: 0;
        font-family: 'Fraunces', Georgia, serif;
        color: #8B1A1A;
        font-size: 1rem;
    }

    .pc-discount-modal-close {
        width: 32px;
        height: 32px;
        border: 0;
        border-radius: 50%;
        background: rgba(192,57,43,0.1);
        color: #8B1A1A;
        cursor: pointer;
        font-size: 1.1rem;
    }

    .pc-discount-modal-body {
        padding: 1rem 1.1rem;
    }

    .pc-discount-detail {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.5rem 0;
        border-bottom: 1px dashed #ead8d2;
        font-size: 0.76rem;
    }

    .pc-discount-detail:last-child {
        border-bottom: 0;
    }

    .pc-discount-detail .label {
        color: #8A6A61;
    }

    .pc-discount-detail .value {
        color: #5A2920;
        font-weight: 700;
        text-align: right;
        word-break: break-word;
    }

    .pc-discount-id-image {
        width: 100%;
        max-height: 300px;
        object-fit: contain;
        border-radius: 10px;
        border: 1px solid #ead8d2;
        margin-top: 0.75rem;
        background: #fff;
    }

    .pc-discount-modal-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        padding: 0 1.1rem 1.1rem;
    }

    .pc-discount-modal-status {
        margin-top: 0.75rem;
        padding: 0.55rem 0.7rem;
        border-radius: 8px;
        font-size: 0.68rem;
        font-weight: 800;
    }

    .pc-discount-modal-status.pending {
        background: #FFF4D6;
        color: #B36B00;
    }

    .pc-discount-modal-status.approved {
        background: #E4F2EA;
        color: #2E7D5B;
    }

    .pc-discount-modal-status.rejected {
        background: #FDE8DE;
        color: #C0392B;
    }

    /* Responsive */
    @media (max-width: 999px) {
        .pc-wrap.has-notifications {
            margin-right: 0;
        }
    }

    @media (max-width: 768px) {
        .pc-grid {
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        }
        .pc-card { padding: 0.9rem; }
        .pc-order { min-width: 0; }
        .pc-toasts {
            top: 5.9rem;
            right: 0.75rem;
            width: min(335px, calc(100vw - 1.5rem));
            max-height: 55vh;
        }
    }

    @media (max-width: 540px) {
        .pc-grid { grid-template-columns: 1fr; gap: 0.8rem; }
        .pc-header { align-items: flex-start; flex-direction: column; }
        .pc-toasts {
            top: 5.6rem;
            left: 0.75rem;
            right: 0.75rem;
            width: auto;
            max-height: 52vh;
        }
        .pc-order-top { padding: 0.8rem 0.85rem; }
        .pc-items { padding: 0.75rem 0.85rem; }
        .pc-totals { padding: 0.65rem 0.85rem; }
        .pc-actions {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.5rem;
            padding: 0.7rem 0.85rem 0.45rem;
        }

        .pc-actions form.pc-cancel {
            width: 100%;
        }

        .pc-actions form.pc-cancel .pc-btn {
            width: 100%;
            min-width: 0;
        }

        .pc-actions .pc-btn {
            min-height: 40px;
            font-size: 0.72rem;
        }
        .pc-time { padding: 0 0.85rem 0.75rem; }
    }
    @media (prefers-reduced-motion: reduce) {
        .pc-live .dot, .pc-flag .dot, .pc-toast { animation: none; }
        .pc-order:hover, .pc-btn:hover { transform: none; }
    }

    .pc-live-info {
        display: flex;
        align-items: center;
        gap: 0.65rem;
    }

    .pc-live {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .pc-last-updated {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 108px;
        box-sizing: border-box;
        font-size: 0.68rem;
        color: #8a6f68;
        background: #fff7f3;
        border: 1px solid #f2d8cf;
        padding: 0.35rem 0.6rem;
        border-radius: 999px;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }

    @media (max-width: 600px) {
        .pc-live-info {
            gap: 0.35rem;
        }

        .pc-last-updated {
            width: 100px;
            font-size: 0.62rem;
            padding: 0.3rem 0.45rem;
        }
    }

    /*
     * The Manual Order modal's own footer buttons (Cancel / Place Order) were
     * bare inline-styled <button> tags with no class at all — nothing on this
     * page to reuse by name. Rather than leave a third button (Apply, on the
     * voucher row) as a fourth one-off inline block that could silently drift
     * from the other two, these two values are pulled out here verbatim —
     * same colours, same radius, same padding, same weight already used by
     * Cancel and Place Order below — so all three buttons in this modal share
     * one definition of "secondary" and one of "primary" instead of three
     * copies of the same numbers.
     */
    .manual-modal-btn {
        border-radius: 8px;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
    }
    .manual-modal-btn-secondary {
        border: 1px solid #ddd;
        background: white;
        color: #666;
        padding: 0.65rem 1rem;
    }
    .manual-modal-btn-primary {
        border: 0;
        background: #C0392B;
        color: white;
        padding: 0.65rem 1.1rem;
        font-weight: 800;
    }
</style>

        <div class="pc-wrap {{ isset($helpRequests) && $helpRequests->count() > 0 ? 'has-notifications' : '' }}">

            <div class="pc-header">
                <div>
                    <h1 class="pc-title">Peachy Cakes &amp; Deli Cafe</h1>
                    <p class="pc-sub">Live order board &middot; refreshes automatically</p>
                </div>
                <div class="pc-live-info">
                <span class="pc-last-updated">
                    Updated&nbsp;<span id="lastUpdated">just now</span>
                </span>

                    <span class="pc-live">
                        <span class="dot"></span>
                        Live
                    </span>
                </div>
                    </div>

            {{--
                Live order-board region — 2026-09-06.

                Everything the 10s auto-refresh needs to redraw when an order
                arrives, changes status, or is completed lives inside this one
                wrapper: the Refunds Owed band and the Active Orders board. The
                background poll (bottom of this file) fetches /admin/home and
                swaps ONLY this element's innerHTML, so the sidebar, header,
                branch selector and notification bell are never touched or
                re-armed. The id is the swap target — do not remove it.
            --}}
            <div id="pc-live-region">

            {{--
                REFUNDS OWED — added 2026-09-02 with customer self-service
                cancellation.

                A customer cancelled an order after tapping "I have paid" on
                the GCash screen, so money may genuinely have left their
                account. Nothing in this system can reverse that (there is no
                merchant API), so the refund is a person at the counter sending
                money back, and this band is the only place that outstanding
                obligation is visible.

                It sits ABOVE Active Orders on purpose. These orders are
                status 'cancelled', so they are excluded from the Active Orders
                query below and would otherwise appear nowhere on the live
                board — only in the completed-orders archive, which is a
                date-filtered history nobody scans for to-dos. Money owed back
                to a customer should not be discoverable only by going looking
                for it.

                Marking one refunded drops it from this band and leaves it in
                the archive as ordinary history.
            --}}
            @if(isset($refundPendingOrders) && $refundPendingOrders->count() > 0)
            <div class="pc-card" style="border-left:4px solid #7C2D12;">
                <div class="pc-section-head">
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <p class="pc-section-title" style="color:#7C2D12;">
                            <i class="bi bi-cash-stack"></i> Refunds Owed
                        </p>
                        <span class="pc-count" style="background:#7C2D12;">
                            {{ $refundPendingOrders->count() }}
                        </span>
                    </div>
                </div>

                <p style="margin:0 0 0.9rem;font-size:0.78rem;color:#8A6A61;line-height:1.5;">
                    These customers cancelled after marking their GCash payment as sent.
                    Send the refund manually, then mark it below so it stops showing here.
                </p>

                @foreach($refundPendingOrders as $refundOrder)
                    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.75rem;padding:0.75rem 0.9rem;margin-bottom:0.5rem;background:#FDF6F3;border:1px solid #F2DDD4;border-radius:12px;">

                        <div style="min-width:0;">
                            <p style="margin:0;font-weight:800;font-size:0.85rem;color:#8B1A1A;">
                                Order #{{ $refundOrder->order_number }}
                                <span style="font-weight:600;color:#8A6A61;">
                                    &middot; &#8369;{{ number_format((float) $refundOrder->total, 2) }}
                                </span>
                            </p>
                            <p style="margin:0.15rem 0 0;font-size:0.72rem;color:#8A6A61;">
                                {{ $refundOrder->customer->name ?? 'Guest' }}
                                &middot; cancelled
                                {{ $refundOrder->cancelled_at ? $refundOrder->cancelled_at->diffForHumans() : 'recently' }}
                            </p>
                        </div>

                        <form action="{{ route('admin.orders.payment.refunded', $refundOrder->id) }}" method="POST" style="margin:0;">
                            @csrf
                            @method('PUT')

                            <button type="submit"
                                    class="pc-btn pc-btn-done"
                                    onclick="return confirm('Confirm you have already sent the &#8369;{{ number_format((float) $refundOrder->total, 2) }} refund for order #{{ $refundOrder->order_number }}?')">
                                <i class="bi bi-check-circle"></i>
                                Mark Refunded
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
            @endif

            <div class="pc-card">
        <div class="pc-section-head">

            <div style="display:flex;align-items:center;gap:0.6rem;">
                <p class="pc-section-title">
                    <i class="bi bi-receipt-cutoff"></i> Active Orders
                </p>

                @if(isset($pendingOrders) && count($pendingOrders) > 0)
                    <span class="pc-count">
                        {{ count($pendingOrders) }}
                    </span>
                @endif
            </div>

            <button
                type="button"
                onclick="openManualOrder()"
                style="
                    border:0;
                    background:#C0392B;
                    color:white;
                    border-radius:8px;
                    padding:0.55rem 0.9rem;
                    font-size:0.78rem;
                    font-weight:700;
                    cursor:pointer;
                    display:flex;
                    align-items:center;
                    gap:0.35rem;
                "
            >
                <i class="bi bi-plus-lg"></i>
                Manual Order
            </button>

        </div>

        @if(isset($pendingOrders) && count($pendingOrders) > 0)
        <div class="pc-grid">
            @foreach($pendingOrders as $order)
            @php
            $statusColor = match($order->status) {
            'pending' => '#F4845F',
            'preparing' => '#C0392B',
            'serving' => '#2E7D5B',
            default => '#8A6A61',
            };

                $helpRequest = null;

                if (isset($helpRequests) && $order->type === 'dine_in') {
                    $helpRequest = $helpRequests->first(function($h) use ($order) {
                        return $h->branch_id == $order->branch_id
                            && $h->table_number == $order->table_number
                            && $h->order_id == $order->id;
                    });
                }

                $needsHelp = $helpRequest !== null;
            @endphp
            <div class="pc-order {{ $needsHelp ? 'needs-help' : '' }}">

            @if($needsHelp)
            <div class="pc-flag">
                <span class="dot"></span>
                Assistance
            </div>
            @endif

                <div class="pc-order-top">
                    <p class="pc-order-type">
                        @if($order->type === 'dine_in')
                        <i class="bi bi-shop"></i> Dine-in — Table {{ $order->table_number ?? 'N/A' }}
                        @elseif($order->type === 'pick_up')
                        <i class="bi bi-bag"></i> Pickup
                        @else
                        <i class="bi bi-person-walking"></i> Walk-in
                        @endif
                    </p>
                    <p class="pc-order-num">Order #{{ $order->order_number }}</p>
                    <span class="pc-status" style="background-color: {{ $statusColor }};">{{ $order->status }}</span>
                </div>

                @php


                    $orderItems = $order->items->values();


                    $visibleItems = $orderItems->take(4);


                    $hiddenItemCount = max(0, $orderItems->count() - $visibleItems->count());



                    $fullOrderItems = $orderItems->map(function ($item) {


                        return [


                            'quantity' => (int) $item->quantity,


                            'name' => $item->item_name,


                            'subtotal' => number_format((float) $item->subtotal, 2),


                        ];


                    })->values();


                @endphp



                <div class="pc-items">


                    @foreach($visibleItems as $item)


                    <p class="pc-item">


                        <span><span class="qty">{{ $item->quantity }}×</span> {{ $item->item_name }}</span>


                        <span class="price">₱{{ number_format($item->subtotal, 2) }}</span>


                    </p>


                    @endforeach



                    @if($hiddenItemCount > 0)


                    <button


                        type="button"


                        class="pc-view-order"


                        onclick="openOrderDetails(this)"


                        data-order-number="{{ $order->order_number }}"


                        data-order-type="{{ $order->type }}"


                        data-table="{{ $order->table_number ?? '' }}"


                        data-status="{{ ucfirst($order->status) }}"


                        data-subtotal="{{ number_format((float) $order->subtotal, 2) }}"


                        data-discount="{{ number_format((float) $order->discount_amount, 2) }}"


                        data-total="{{ number_format((float) $order->total, 2) }}"


                        data-created="{{ $order->created_at?->format('M d, Y h:i A') }}"


                        {{-- The full-details modal showed no payment information at
                             all, for any method. Same gap as the card, same fix. --}}
                        data-payment="{{ match (strtolower((string) ($order->payment_method ?? 'cash'))) {
                            'gcash' => 'GCash',
                            'cash'  => 'Cash',
                            default => ucfirst((string) ($order->payment_method ?: 'Cash')),
                        } }}"


                        data-payment-status="{{ strtolower((string) ($order->payment_status ?? '')) === 'paid'
                            ? 'Paid'
                            : ucfirst(str_replace('_', ' ', strtolower((string) ($order->payment_status ?: 'unpaid')))) }}"


                        data-items='@json($fullOrderItems)'


                    >


                        <span>… {{ $hiddenItemCount }} more item{{ $hiddenItemCount === 1 ? '' : 's' }}</span>


                        <i class="bi bi-chevron-right"></i>


                    </button>


                    @endif


                </div>

                @php
                    // Payment information
                    $paymentMethod = strtolower((string) ($order->payment_method ?? 'cash'));
                    $paymentStatus = strtolower((string) ($order->payment_status ?? ''));

                    $isGcash = $paymentMethod === 'gcash';
                    $gcashNeedsVerification = $isGcash && $paymentStatus === 'awaiting_verification';
                    $gcashRejected = $isGcash && $paymentStatus === 'rejected';
                    $gcashPaid = $isGcash && $paymentStatus === 'paid';

                    /*
                     * How this order was paid, for EVERY method.
                     *
                     * The Payment block below used to be wrapped in @if($isGcash),
                     * so a Cash order rendered no Payment section at all — the card
                     * jumped from the item list straight to Subtotal/Total and the
                     * counter could not tell from the board whether the money had
                     * been taken. The data was never the problem: storeManualOrder()
                     * writes payment_method and payment_status for cash and GCash
                     * alike. It was purely this condition.
                     */
                    $paymentLabel = match ($paymentMethod) {
                        'gcash' => 'GCash',
                        'cash'  => 'Cash',
                        default => ucfirst($paymentMethod ?: 'Cash'),
                    };

                    $paymentIcon = match ($paymentMethod) {
                        'gcash' => 'bi-phone',
                        'cash'  => 'bi-cash-coin',
                        default => 'bi-credit-card',
                    };

                    $paymentIsPaid = $paymentStatus === 'paid';

                    // Existing discount information
                    $discountType = strtolower((string) ($order->discount_type ?? ''));
                    // Single source of truth — see App\Models\Order::discountLabel().
                    $discountLabel = $order->discountLabel();

                    // Use the exact card saved on this order. Do not guess from
                    // the customer's first verified card.
                    $discountCard = $order->discountCard;
                    $discountStatus = strtolower((string) ($order->discount_status ?? 'approved'));

                    $discountNeedsApproval = in_array($discountType, ['pwd', 'senior'], true)
                        && $order->discount_amount > 0
                        && $discountStatus === 'pending';
                @endphp

                <div style="
        padding:0.65rem 1rem;
        background:#FFF7F3;
        border-top:1px solid #F2D8CF;
        border-bottom:1px solid #F2D8CF;
    ">
        <div style="
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:0.75rem;
        ">
            <div>
                <div style="
                    font-size:0.62rem;
                    color:#8A6A61;
                    text-transform:uppercase;
                    letter-spacing:0.05em;
                    font-weight:700;
                ">
                    Payment
                </div>

                <div style="
                    margin-top:0.15rem;
                    font-size:0.82rem;
                    font-weight:800;
                    color:#5A2920;
                ">
                    <i class="bi {{ $paymentIcon }}"></i>
                    {{ $paymentLabel }}
                </div>
            </div>

            @if($gcashNeedsVerification)
                <span style="
                    display:inline-flex;
                    align-items:center;
                    gap:0.3rem;
                    padding:0.25rem 0.6rem;
                    border-radius:999px;
                    background:#FFF4D6;
                    color:#B36B00;
                    font-size:0.62rem;
                    font-weight:800;
                ">
                    <i class="bi bi-hourglass-split"></i>
                    Awaiting Verification
                </span>

            @elseif($gcashPaid)
                <span style="
                    display:inline-flex;
                    align-items:center;
                    gap:0.3rem;
                    padding:0.25rem 0.6rem;
                    border-radius:999px;
                    background:#E4F2EA;
                    color:#2E7D5B;
                    font-size:0.62rem;
                    font-weight:800;
                ">
                    <i class="bi bi-check-circle-fill"></i>
                    Paid
                </span>

            @elseif($gcashRejected)
                <span style="
                    display:inline-flex;
                    align-items:center;
                    gap:0.3rem;
                    padding:0.25rem 0.6rem;
                    border-radius:999px;
                    background:#FDE8DE;
                    color:#C0392B;
                    font-size:0.62rem;
                    font-weight:800;
                ">
                    <i class="bi bi-x-circle-fill"></i>
                    Rejected
                </span>

            @elseif($paymentIsPaid)
                {{-- Cash (or any other method) already settled at the counter. --}}
                <span style="
                    display:inline-flex;
                    align-items:center;
                    gap:0.3rem;
                    padding:0.25rem 0.6rem;
                    border-radius:999px;
                    background:#E4F2EA;
                    color:#2E7D5B;
                    font-size:0.62rem;
                    font-weight:800;
                ">
                    <i class="bi bi-check-circle-fill"></i>
                    Paid
                </span>

            @else
                <span style="
                    display:inline-flex;
                    align-items:center;
                    gap:0.3rem;
                    padding:0.25rem 0.6rem;
                    border-radius:999px;
                    background:#FDE8DE;
                    color:#C0392B;
                    font-size:0.62rem;
                    font-weight:800;
                ">
                    {{ ucfirst($paymentStatus ?: 'Unpaid') }}
                </span>
            @endif
        </div>
    </div>

                <div class="pc-totals">
                    <div class="pc-row">
                        <span>Subtotal</span>
                        <span>₱{{ number_format($order->subtotal, 2) }}</span>
                    </div>

                    @if($order->discount_amount > 0)
                        @php
                            $modalDiscountType = $discountLabel ?: 'PWD / Senior Discount';
                            $modalDiscountName = $discountCard?->name
                                ?: ($order->discount_beneficiary_name ?: 'Not provided');
                            $modalDiscountId = $discountCard?->id_number
                                ?? $discountCard?->card_number
                                ?? $order->discount_beneficiary_card_number
                                ?? 'Not provided';
                            $modalDiscountExpiry = $discountCard?->expiration_date
                                ? \Carbon\Carbon::parse($discountCard->expiration_date)->format('M d, Y')
                                : 'Not provided';
                            /*
                             * Security review 2026-08-31 (Pass 4, item #10):
                             * this used to build a direct asset() URL into the
                             * public storage path for the stored image — a
                             * public URL to the customer's identity document
                             * that needed no authentication at all —
                             * and rendering it here put that URL into the page
                             * source, browser history and any screenshot.
                             *
                             * It is now a route keyed on the ORDER id, with no
                             * filename in it, re-authorised on every request by
                             * App\Services\DiscountIdAccess.
                             */
                            $modalDiscountImage = $order->discount_id_image
                                ? route('discount-id.show', $order->id)
                                : '';
                            $modalDiscountStatus = $discountStatus ?: 'approved';
                        @endphp

                        <button
                            type="button"
                            class="pc-discount-trigger"
                            onclick="openDiscountModal(this)"
                            data-order="{{ $order->order_number }}"
                            data-order-id="{{ $order->id }}"
                            data-approve-url="{{ route('admin.orders.discount.approve', $order->id) }}"
                            data-reject-url="{{ route('admin.orders.discount.reject', $order->id) }}"
                            data-type="{{ $modalDiscountType }}"
                            data-name="{{ $modalDiscountName }}"
                            data-id-number="{{ $modalDiscountId }}"
                            data-expiration="{{ $modalDiscountExpiry }}"
                            data-status="{{ $modalDiscountStatus }}"
                            data-image="{{ $modalDiscountImage }}"
                        >
                            <span class="pc-discount-trigger-main">
                                <span>
                                    <i class="bi bi-person-vcard"></i>
                                    {{ \Illuminate\Support\Str::upper($order->discountDisplayLabel() ?: 'Discount') }}
                                </span>
                                <span class="pc-discount-trigger-amount">
                                    -₱{{ number_format($order->discount_amount, 2) }}
                                </span>
                            </span>
                            <span class="pc-discount-trigger-sub">
                                <span>Click to view discount information</span>
                                <i class="bi bi-chevron-right"></i>
                            </span>
                        </button>
                    @endif

                    <div class="pc-row total">
                        <span>Total</span>
                        <span>₱{{ number_format($order->total, 2) }}</span>
                    </div>
                </div>
@if($helpRequest)
<div style="
    padding:0.65rem 1rem;
    background:#fff7f3;
    border-top:1px solid #f2d8cf;
    border-bottom:1px solid #f2d8cf;
">
    <div style="
        font-size:0.72rem;
        font-weight:800;
        color:#C0392B;
        margin-bottom:0.45rem;
    ">
        <i class="bi bi-exclamation-circle"></i>
        Assistance Needed
    </div>

    <div style="
        font-size:0.7rem;
        color:#8A6A61;
        margin-bottom:0.55rem;
    ">
        {{ $helpRequest->message ?: 'Customer requested assistance.' }}
    </div>

    @if($helpRequest->status === 'pending')
        <form
            action="{{ route('admin.help-requests.assist', $helpRequest->id) }}"
            method="POST"
            style="margin:0;"
        >
            @csrf
            @method('PUT')

            <button
                type="submit"
                class="pc-btn pc-btn-peach"
            >
                <i class="bi bi-person-check"></i>
                Assist Customer
            </button>
        </form>
            @elseif($helpRequest->status === 'assisting')
                <form
                    action="{{ route('admin.help-requests.resolve', $helpRequest->id) }}"
                    method="POST"
                    style="margin:0;"
                >
                    @csrf
                    @method('PUT')

                    <button
                        type="submit"
                        class="pc-btn pc-btn-done"
                    >
                        <i class="bi bi-check-circle"></i>
                        Resolve Assistance
                    </button>
                </form>
            @endif
        </div>
        @endif
                <div class="pc-actions">

                @if($gcashNeedsVerification)

                    <div class="pc-discount-actions">
                        <div class="pc-discount-actions-label">
                            <i class="bi bi-credit-card"></i>
                            GCash payment needs verification
                        </div>

                        <div class="pc-discount-actions-row">
                            <form action="{{ route('admin.orders.payment.approve', $order->id) }}" method="POST">
                                @csrf
                                @method('PUT')

                                <button type="submit"
                                        class="pc-btn pc-btn-done"
                                        onclick="return confirm('Approve this GCash payment?')">
                                    <i class="bi bi-check-circle"></i>
                                    Approve Payment
                                </button>
                            </form>

                            <form action="{{ route('admin.orders.payment.reject', $order->id) }}" method="POST">
                                @csrf
                                @method('PUT')

                                <button type="submit"
                                        class="pc-btn pc-btn-cancel"
                                        onclick="return confirm('Reject this GCash payment?')">
                                    <i class="bi bi-x-circle"></i>
                                    Reject Payment
                                </button>
                            </form>
                        </div>
                    </div>

                @elseif($discountNeedsApproval)

                    <div class="pc-discount-actions">
                        <div class="pc-discount-actions-label">
                            <i class="bi bi-person-vcard"></i>
                            PWD/Senior discount needs approval
                        </div>
                        <div class="pc-discount-actions-row">
                            <form action="{{ route('admin.orders.discount.approve', $order->id) }}"
                                method="POST">
                                @csrf
                                @method('PUT')

                                <button type="submit"
                                        class="pc-btn pc-btn-done"
                                        onclick="return confirm('Approve this PWD/Senior Citizen discount?')">
                                    <i class="bi bi-check-circle"></i>
                                    Approve Discount
                                </button>
                            </form>

                            <form action="{{ route('admin.orders.discount.reject', $order->id) }}"
                                method="POST">
                                @csrf
                                @method('PUT')

                                <button type="submit"
                                        class="pc-btn pc-btn-cancel"
                                        onclick="return confirm('Reject this discount? The order will remain pending at the full price.')">
                                    <i class="bi bi-x-circle"></i>
                                    Reject Discount
                                </button>
                            </form>
                        </div>
                    </div>

                @elseif($order->status === 'pending' && (!$isGcash || $gcashPaid))

                    <form action="{{ route('admin.orders.prepare', $order->id) }}"
                        method="POST">
                        @csrf
                        @method('PUT')

                        <button type="submit" class="pc-btn pc-btn-prepare">
                            <i class="bi bi-fire"></i>
                            Prepare
                        </button>
                    </form>

                @endif
                    @if($order->status === 'preparing')
                    <form action="{{ route('admin.orders.serve', $order->id) }}" method="POST">
                        @csrf @method('PUT')
                        <button type="submit" class="pc-btn pc-btn-serve"><i class="bi bi-cup-hot"></i> Serving</button>
                    </form>
                    @endif
                    @if(in_array($order->status, ['pending', 'preparing', 'serving']) && (!$isGcash || $gcashPaid))
                    <form action="{{ route('admin.orders.complete', $order->id) }}" method="POST" onsubmit="return confirm('Complete this order? Inventory will auto-deduct.')">
                        @csrf @method('PUT')
                        <button type="submit" class="pc-btn pc-btn-done"><i class="bi bi-check-circle"></i> Complete</button>
                    </form>
                    @endif
                    <form action="{{ route('admin.orders.cancel', $order->id) }}" method="POST" class="pc-cancel" onsubmit="return confirm('Cancel this order?')">
                        @csrf @method('PUT')
                        <button type="submit" class="pc-btn pc-btn-cancel" aria-label="Cancel order"><i class="bi bi-x-circle"></i> Cancel</button>
                    </form>
                </div>

                <p class="pc-time">{{ $order->created_at->diffForHumans() }}</p>
            </div>
            @endforeach
        </div>
        @else
        <div class="pc-empty">
            <i class="bi bi-inbox"></i>
            <p class="t">No active orders</p>
            <p class="s">New orders from customers will appear here</p>
        </div>
        @endif
    </div>

        </div>{{-- /#pc-live-region --}}

</div>


{{-- Full Order Details Modal --}}
<div id="pcOrderDetailsModal" class="pc-order-modal" aria-hidden="true">
    <div class="pc-order-modal-box" role="dialog" aria-modal="true" aria-labelledby="pcOrderModalTitle">
        <div class="pc-order-modal-head">
            <div>
                <h3 id="pcOrderModalTitle" class="pc-order-modal-title">Order Details</h3>
                <p id="pcOrderModalSubtitle" class="pc-order-modal-subtitle"></p>
            </div>
            <button type="button" class="pc-order-modal-close" onclick="closeOrderDetails()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="pc-order-modal-body">
            <div class="pc-order-modal-info">
                <div class="pc-order-modal-info-item">
                    <span class="label">Order</span>
                    <span class="value" id="pcOrderModalNumber">-</span>
                </div>
                <div class="pc-order-modal-info-item">
                    <span class="label">Type</span>
                    <span class="value" id="pcOrderModalType">-</span>
                </div>
                <div class="pc-order-modal-info-item">
                    <span class="label">Status</span>
                    <span class="value" id="pcOrderModalStatus">-</span>
                </div>
                <div class="pc-order-modal-info-item">
                    <span class="label">Payment</span>
                    <span class="value" id="pcOrderModalPayment">-</span>
                </div>
            </div>

            <div class="pc-order-modal-items" id="pcOrderModalItems"></div>

            <div class="pc-order-modal-total">
                <div class="pc-order-modal-total-row">
                    <span>Subtotal</span>
                    <span id="pcOrderModalSubtotal">₱0.00</span>
                </div>
                <div class="pc-order-modal-total-row">
                    <span>Discount</span>
                    <span id="pcOrderModalDiscount">-₱0.00</span>
                </div>
                <div class="pc-order-modal-total-row final">
                    <span>Total</span>
                    <span id="pcOrderModalTotal">₱0.00</span>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- PWD / Senior Citizen Discount Information Modal --}}
<div id="pcDiscountModal" class="pc-discount-modal" aria-hidden="true">
    <div class="pc-discount-modal-box" role="dialog" aria-modal="true" aria-labelledby="pcDiscountModalTitle">
        <div class="pc-discount-modal-head">
            <h3 id="pcDiscountModalTitle">PWD / Senior Citizen Discount</h3>
            <button type="button" class="pc-discount-modal-close" onclick="closeDiscountModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="pc-discount-modal-body">
            <div class="pc-discount-detail">
                <span class="label">Order</span>
                <span class="value" id="pcDiscountOrder">-</span>
            </div>
            <div class="pc-discount-detail">
                <span class="label">Discount Type</span>
                <span class="value" id="pcDiscountType">-</span>
            </div>
            <div class="pc-discount-detail">
                <span class="label">Name</span>
                <span class="value" id="pcDiscountName">-</span>
            </div>
            <div class="pc-discount-detail">
                <span class="label">ID / Card Number</span>
                <span class="value" id="pcDiscountIdNumber">-</span>
            </div>
            <div class="pc-discount-detail">
                <span class="label">Expiration</span>
                <span class="value" id="pcDiscountExpiration">-</span>
            </div>

            <div id="pcDiscountStatus" class="pc-discount-modal-status pending">
                Pending Verification
            </div>

            <img id="pcDiscountIdImage" class="pc-discount-id-image" src="" alt="Discount ID image" style="display:none;">
        </div>

        <div id="pcDiscountModalActions" class="pc-discount-modal-actions">
            <form id="pcDiscountApproveForm" method="POST" style="margin:0;">
                @csrf
                @method('PUT')
                <button type="submit" class="pc-btn pc-btn-done" onclick="return confirm('Approve this PWD/Senior Citizen discount?')">
                    <i class="bi bi-check-circle"></i> Approve
                </button>
            </form>

            <form id="pcDiscountRejectForm" method="POST" style="margin:0;">
                @csrf
                @method('PUT')
                <button type="submit" class="pc-btn pc-btn-cancel" onclick="return confirm('Reject this discount? The order will remain pending at the full price.')">
                    <i class="bi bi-x-circle"></i> Reject
                </button>
            </form>
        </div>
    </div>
</div>

{{-- Customer Assistance — subtle overlay notifications --}}
{{-- Wrapper is a second swap target for the live poll (see script below): it
     carries no styling, so the fixed-position panel inside is unaffected. --}}
<div id="pc-help-toasts-slot">
@if(isset($helpRequests) && $helpRequests->count() > 0)
<div class="pc-toasts" aria-live="polite">

    <div class="pc-toast-head">
        <span><i class="bi bi-bell-fill"></i> New Notifications · {{ $helpRequests->count() }}</span>
        <button type="button" onclick="document.body.classList.toggle('pc-toasts-hidden')" aria-label="Toggle notifications">
            <i class="bi bi-chevron-down"></i>
        </button>
    </div>

    @foreach($helpRequests as $help)
    <div class="pc-toast {{ $help->status === 'pending' ? '' : 'is-assisting' }}">
        <p class="pc-toast-title">
            🙋 Table {{ $help->table_number }}
            <span class="pc-toast-branch">{{ $help->branch->name ?? '' }}</span>
        </p>
        <p class="pc-toast-meta">
            {{ $help->status === 'pending' ? 'Waiting for assistance' : 'Being assisted' }}
            • {{ $help->requested_at?->diffForHumans() }}
            @if($help->order) • Order #{{ $help->order->order_number }} @endif
        </p>
        <div class="pc-toast-actions">
            @if($help->status === 'pending')
            <form action="{{ route('admin.help-requests.assist', $help->id) }}" method="POST">
                @csrf @method('PUT')
                <button type="submit" class="pc-btn pc-btn-peach">Assist</button>
            </form>
            @endif
            <form action="{{ route('admin.help-requests.resolve', $help->id) }}" method="POST">
                @csrf @method('PUT')
                <button type="submit" class="pc-btn pc-btn-done">Done ✓</button>
            </form>
        </div>
    </div>
    @endforeach

</div>
@endif
</div>{{-- /#pc-help-toasts-slot --}}

<script>
    let pageLoadedAt = Date.now();

    function updateLastUpdated() {
        const element = document.getElementById('lastUpdated');

        if (!element) {
            return;
        }

        const seconds = Math.floor(
            (Date.now() - pageLoadedAt) / 1000
        );

        if (seconds <= 0) {
            element.textContent = 'just now';
        } else if (seconds === 1) {
            element.textContent = '1s ago';
        } else {
            element.textContent = seconds + 's ago';
        }
    }

    updateLastUpdated();

    setInterval(updateLastUpdated, 1000);

    /*
     * Active Orders board auto-update — targeted background fetch, 2026-09-06.
     *
     * This used to be a whole-document reload on a 10s timer. That repaints
     * the ENTIRE document every tick — sidebar, header, branch selector,
     * notification bell, and the Bootstrap Icons font (which blanks its glyphs
     * under font-display:block until it is re-resolved). None of that needs to
     * change when an order arrives; only the order board does.
     *
     * Now: fetch the same /admin/home HTML in the background and swap ONLY the
     * innerHTML of #pc-live-region (Refunds Owed + Active Orders) and
     * #pc-help-toasts-slot. Nothing outside those two containers is touched or
     * re-armed — the sidebar never repaints, the bell's own 20s poll keeps
     * running uninterrupted.
     *
     * Interval: 10s, unchanged from the old reload cadence. Staff tolerate far
     * more latency than this before distrusting a queue, the response is one
     * already-warm route, and every counter terminal shares one public IP so a
     * faster beat would eat into the admin rate limit for no felt benefit. The
     * fast JSON polls elsewhere (notification bell, Occupied Tables on the QR
     * page) are left as they are — see OrderStatusToastTest and
     * OccupiedTablesAutoRefreshTest, which pin those on purpose.
     *
     * Idempotent: each tick replaces a fixed container's innerHTML wholesale,
     * so cards can never duplicate and orders the server has dropped disappear
     * on the next tick. Network blips: a failed or aborted fetch is swallowed
     * and the last rendered board stays exactly as it is — no spinner, no
     * blanking, no stuck state.
     */
    (function () {
        var BOARD_REFRESH_MS = 10000;
        var SWAP_IDS = ['pc-live-region', 'pc-help-toasts-slot'];
        var inFlight = false;

        function refreshBoard() {
            if (inFlight) {
                return;
            }

            // Never yank the board out from under an open Manual Order modal.
            var modal = document.getElementById('manualOrderModal');
            if (modal && modal.style.display === 'block') {
                return;
            }

            // A hidden tab does not need updating; resume on the next tick.
            if (document.hidden) {
                return;
            }

            inFlight = true;

            fetch(window.location.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store'
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('board refresh HTTP ' + response.status);
                    }
                    return response.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');

                    // A login/redirect page has no board — leave the last known
                    // state on screen rather than blanking it.
                    if (!doc.getElementById('pc-live-region')) {
                        return;
                    }

                    SWAP_IDS.forEach(function (id) {
                        var fresh = doc.getElementById(id);
                        var current = document.getElementById(id);

                        if (fresh && current && fresh.innerHTML !== current.innerHTML) {
                            current.innerHTML = fresh.innerHTML;
                        }
                    });

                    // Keep the sidebar-gap class in step without touching
                    // anything else on .pc-wrap.
                    var freshWrap = doc.querySelector('.pc-wrap');
                    var currentWrap = document.querySelector('.pc-wrap');
                    if (freshWrap && currentWrap) {
                        currentWrap.classList.toggle(
                            'has-notifications',
                            freshWrap.classList.contains('has-notifications')
                        );
                    }

                    pageLoadedAt = Date.now();
                    updateLastUpdated();
                })
                .catch(function () {
                    // Keep the last known board visible on any failure.
                })
                .then(function () {
                    inFlight = false;
                });
        }

        setInterval(refreshBoard, BOARD_REFRESH_MS);
    })();
</script>

<script>
let manualCart = {};
let manualOptionTarget = null;

function openManualOrder()
{
    document.getElementById('manualOrderModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    filterManualItems();

    // Belt and braces: whatever state the modal was left in, the discount
    // controls must be disabled unless the box is actually ticked.
    setManualDiscountEnabled(
        document.getElementById('manualDiscountToggle').checked
    );

    /*
     * A voucher price is only ever true for the basket it was quoted
     * against, so a price left over from the previous customer's order
     * must never be carried into this one. Cleared on open rather than on
     * close, because the modal is also reopened by the bounce handler
     * after a refused submission.
     */
    manualVoucher = null;
    manualVoucherMessage('', false);
    updateManualTotals();
}

function closeManualOrder()
{
    closeManualOptions();
    document.getElementById('manualOrderModal').style.display = 'none';
    document.body.style.overflow = '';
}

function toggleManualTable()
{
    const type = document.getElementById('manualOrderType').value;
    const wrapper = document.getElementById('manualTableWrapper');
    const input = document.getElementById('manualTableNumber');

    if (type === 'dine_in') {
        wrapper.style.display = 'block';
        input.required = true;
    } else {
        wrapper.style.display = 'none';
        input.required = false;
        input.value = '';
    }
}

function filterManualItems()
{
    const branch = document.getElementById('manualBranch').value;
    const search = document.getElementById('manualMenuSearch').value.trim().toLowerCase();

    document.querySelectorAll('.manual-menu-card').forEach(function(card) {
        const name = card.dataset.name || '';
        const category = card.dataset.category || '';
        const subcategory = card.dataset.subcategory || '';
        const itemBranch = card.dataset.branch || 'all';

        const branchMatch =
            branch === '' ||
            itemBranch === 'all' ||
            itemBranch === branch;

        const searchMatch =
            search === '' ||
            name.includes(search) ||
            category.includes(search) ||
            subcategory.includes(search);

        card.style.display = branchMatch && searchMatch ? '' : 'none';
    });
}

function getManualCardOptions(card)
{
    try {
        return JSON.parse(card.dataset.options || '[]');
    } catch (error) {
        return [];
    }
}

function addManualItem(id)
{
    const card = document.querySelector(
        '.manual-menu-card[data-id="' + id + '"]'
    );

    if (!card) {
        return;
    }

    const options = getManualCardOptions(card);

    if (options.length > 0) {
        openManualOptions(card, options);
        return;
    }

    addManualItemToCart(card, []);
}

function openManualOptions(card, options)
{
    manualOptionTarget = card;

    const modal = document.getElementById('manualOptionsModal');
    const title = document.getElementById('manualOptionsTitle');
    const price = document.getElementById('manualOptionsBasePrice');
    const list = document.getElementById('manualOptionsList');

    title.textContent = card.querySelector('.manual-item-name').textContent.trim();
    price.textContent = 'Base price: ₱' + (parseFloat(card.dataset.price) || 0).toFixed(2);

    list.innerHTML = '';

    options.forEach(function(option) {
        const optionPrice = parseFloat(option.additional_price) || 0;

        const label = document.createElement('label');
        label.style.cssText = `
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:0.75rem;
            padding:0.65rem 0.75rem;
            border:1px solid #ead8d2;
            border-radius:8px;
            background:#fff;
            cursor:pointer;
        `;

        label.innerHTML = `
            <span style="display:flex;align-items:center;gap:0.55rem;flex:1;">
                <input
                    type="checkbox"
                    class="manual-option-check"
                    value="${option.id}"
                    data-price="${optionPrice}"
                    style="width:17px;height:17px;accent-color:#C0392B;"
                >
                <span style="font-weight:700;color:#5A2920;font-size:0.8rem;">
                    ${escapeManualHtml(option.name)}
                </span>
            </span>
            <strong style="color:#C0392B;font-size:0.78rem;">
                ${optionPrice > 0 ? '+₱' + optionPrice.toFixed(2) : 'Free'}
            </strong>
        `;

        list.appendChild(label);
    });

    updateManualOptionPreview();

    modal.style.display = 'flex';
}

function updateManualOptionPreview()
{
    if (!manualOptionTarget) {
        return;
    }

    const basePrice = parseFloat(manualOptionTarget.dataset.price) || 0;
    let optionTotal = 0;

    document.querySelectorAll('.manual-option-check:checked').forEach(function(check) {
        optionTotal += parseFloat(check.dataset.price) || 0;
    });

    document.getElementById('manualOptionsFinalPrice').textContent =
        '₱' + (basePrice + optionTotal).toFixed(2);
}

function closeManualOptions()
{
    const modal = document.getElementById('manualOptionsModal');

    if (modal) {
        modal.style.display = 'none';
    }

    manualOptionTarget = null;
}

function confirmManualOptions()
{
    if (!manualOptionTarget) {
        return;
    }

    const selectedOptions = [];

    document.querySelectorAll('.manual-option-check:checked').forEach(function(check) {
        selectedOptions.push({
            id: parseInt(check.value, 10),
            name: check.parentElement.querySelector('span').textContent.trim(),
            price: parseFloat(check.dataset.price) || 0
        });
    });

    addManualItemToCart(manualOptionTarget, selectedOptions);
    closeManualOptions();
}

function addManualItemToCart(card, selectedOptions)
{
    const id = parseInt(card.dataset.id, 10);
    const displayName = card.querySelector('.manual-item-name').textContent.trim();
    const basePrice = parseFloat(card.dataset.price) || 0;

    const optionIds = selectedOptions
        .map(function(option) {
            return option.id;
        })
        .sort(function(a, b) {
            return a - b;
        });

    const cartKey = id + '_' + (optionIds.length ? optionIds.join('-') : 'none');

    let optionTotal = 0;

    selectedOptions.forEach(function(option) {
        optionTotal += parseFloat(option.price) || 0;
    });

    const finalPrice = basePrice + optionTotal;

    if (!manualCart[cartKey]) {
        manualCart[cartKey] = {
            key: cartKey,
            id: id,
            name: displayName,
            price: finalPrice,
            quantity: 1,
            options: selectedOptions
        };
    } else {
        manualCart[cartKey].quantity++;
    }

    renderManualCart();
}

function changeManualQuantity(key, amount)
{
    if (!manualCart[key]) {
        return;
    }

    manualCart[key].quantity += amount;

    if (manualCart[key].quantity <= 0) {
        delete manualCart[key];
    }

    renderManualCart();
}

function removeManualItem(key)
{
    delete manualCart[key];
    renderManualCart();
}

function renderManualCart()
{
    const container = document.getElementById('manualCartItems');
    const empty = document.getElementById('manualEmptyCart');

    container.innerHTML = '';

    const keys = Object.keys(manualCart);

    if (keys.length === 0) {
        empty.style.display = 'block';
        updateManualTotals();
        return;
    }

    empty.style.display = 'none';

    keys.forEach(function(key) {
        const item = manualCart[key];
        const safeKey = escapeManualHtml(key);

        const optionText = item.options.length
            ? item.options.map(function(option) {
                return escapeManualHtml(option.name);
            }).join(', ')
            : '';

        const row = document.createElement('div');

        row.style.cssText = `
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:0.75rem;
            padding:0.7rem;
            background:#faf7f5;
            border-radius:8px;
            margin-bottom:0.5rem;
            flex-wrap:wrap;
        `;

        row.innerHTML = `
            <div style="flex:1;min-width:180px;">
                <div style="
                    font-weight:800;
                    color:#5A2920;
                    font-size:0.8rem;
                ">
                    ${escapeManualHtml(item.name)}
                </div>

                ${
                    optionText
                        ? `<div style="
                            font-size:0.67rem;
                            color:#8A6A61;
                            margin-top:0.2rem;
                        ">${optionText}</div>`
                        : ''
                }

                <div style="
                    font-size:0.7rem;
                    color:#999;
                    margin-top:0.2rem;
                ">
                    ₱${item.price.toFixed(2)} each
                </div>
            </div>

            <div style="
                display:flex;
                align-items:center;
                gap:0.35rem;
            ">
                <button
                    type="button"
                    onclick="changeManualQuantity('${safeKey}', -1)"
                    style="
                        width:28px;
                        height:28px;
                        border:1px solid #ddd;
                        background:white;
                        border-radius:6px;
                        cursor:pointer;
                    "
                >
                    −
                </button>

                <strong style="
                    min-width:25px;
                    text-align:center;
                ">
                    ${item.quantity}
                </strong>

                <button
                    type="button"
                    onclick="changeManualQuantity('${safeKey}', 1)"
                    style="
                        width:28px;
                        height:28px;
                        border:0;
                        background:#C0392B;
                        color:white;
                        border-radius:6px;
                        cursor:pointer;
                    "
                >
                    +
                </button>
            </div>

            <strong style="
                min-width:75px;
                text-align:right;
                color:#C0392B;
            ">
                ₱${(item.price * item.quantity).toFixed(2)}
            </strong>

            <button
                type="button"
                onclick="removeManualItem('${safeKey}')"
                style="
                    border:0;
                    background:none;
                    color:#C0392B;
                    cursor:pointer;
                "
            >
                <i class="bi bi-trash"></i>
            </button>

            <input
                type="hidden"
                name="items[${safeKey}][menu_item_id]"
                value="${item.id}"
            >

            <input
                type="hidden"
                name="items[${safeKey}][quantity]"
                value="${item.quantity}"
            >

            ${
                item.options.map(function(option) {
                    return `
                        <input
                            type="hidden"
                            name="items[${safeKey}][options][]"
                            value="${option.id}"
                        >
                    `;
                }).join('')
            }
        `;

        container.appendChild(row);
    });

    updateManualTotals();
}

/*
 * Show or hide the PWD/Senior fields AND enable or disable them.
 *
 * Disabling is the part that matters. Hiding a control with display:none does
 * not stop the browser submitting it, and <select name="discount_type"> has no
 * empty option — so with the box unticked it still posted its first option,
 * "pwd", on every single manual order. storeManualOrder() then saw a discount
 * request carrying no beneficiary name or ID and bounced the whole submission
 * back with an error nobody could see, because the modal is closed on reload.
 * Every counter order failed that way, cash and GCash alike. Disabled controls
 * are left out of the submission entirely, so an unticked box now sends nothing.
 */
function setManualDiscountEnabled(on)
{
    document.getElementById('manualDiscountFields').style.display =
        on ? 'grid' : 'none';

    ['manualDiscountType', 'manualDiscountName', 'manualDiscountIdNumber']
        .forEach(function (id) {
            const el = document.getElementById(id);

            if (!el) {
                return;
            }

            el.disabled = !on;

            // The server refuses a discount with either field blank, so ask
            // for them here first rather than after a round trip.
            if (id !== 'manualDiscountType') {
                el.required = on;
            }
        });
}

function toggleManualDiscount()
{
    setManualDiscountEnabled(
        document.getElementById('manualDiscountToggle').checked
    );

    updateManualTotals();
}

/*
|------------------------------------------------------------------------------
| THE CUSTOMER'S VOUCHER, CHECKED BEFORE THE ORDER IS SUBMITTED
|------------------------------------------------------------------------------
|
| manualVoucher holds what the SERVER said the code is worth. It is never
| computed here: this page has no idea what a voucher's discount type, value,
| minimum order or claim state are, and inventing a formula for it is exactly
| how the cart preview and checkout drifted apart before. The only number that
| ever reaches the totals below is the one the server returned.
|
| Cleared the moment the code is edited, so a stale price can never be quoted
| for a code that is no longer in the box.
*/
let manualVoucher = null;

function manualVoucherMessage(text, ok)
{
    const box = document.getElementById('manualVoucherMessage');

    if (!box) {
        return;
    }

    if (!text) {
        box.style.display = 'none';
        box.textContent = '';
        return;
    }

    box.style.display = 'block';
    box.style.color = ok ? '#2E7D5B' : '#C0392B';
    box.textContent = text;
}

function clearManualVoucher()
{
    manualVoucher = null;
    manualVoucherMessage('', false);
    updateManualTotals();
}

function checkManualVoucher()
{
    const field = document.getElementById('manualVoucherCode');
    const code = (field.value || '').trim();

    if (code === '') {
        clearManualVoucher();
        manualVoucherMessage('Enter the customer’s voucher code first.', false);
        return;
    }

    // Priced against the SAME subtotal the order will be priced against, so
    // the minimum-order rule is judged on the real basket.
    let subtotal = 0;

    Object.keys(manualCart).forEach(function (key) {
        subtotal += manualCart[key].price * manualCart[key].quantity;
    });

    const button = document.getElementById('manualVoucherCheck');
    button.disabled = true;

    fetch('{{ route('admin.manual-order.voucher-preview') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ code: code, subtotal: subtotal }),
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
        if (data && data.success) {
            manualVoucher = { discount: parseFloat(data.discount) || 0 };
            manualVoucherMessage(data.message, true);
        } else {
            // The server's own sentence, verbatim, so staff can read the
            // actual reason out to the customer.
            manualVoucher = null;
            manualVoucherMessage(
                (data && data.message) || 'That voucher code could not be checked.',
                false
            );
        }

        updateManualTotals();
    })
    .catch(function () {
        manualVoucher = null;
        manualVoucherMessage(
            'Could not check the code just now. It will still be checked when the order is submitted.',
            false
        );
        updateManualTotals();
    })
    .finally(function () {
        button.disabled = false;
    });
}

function updateManualTotals()
{
    let subtotal = 0;

    Object.keys(manualCart).forEach(function(key) {
        const item = manualCart[key];
        subtotal += item.price * item.quantity;
    });

    document.getElementById('manualSubtotal').textContent =
        '₱' + subtotal.toFixed(2);

    /*
     * PWD / Senior preview — mirrors Order::pwdSeniorDiscountFor() on the
     * server: 20% of subtotal, floored at subtotal, rounded once.
     *
     * null, not 0, when the box is unticked. That is the same distinction
     * Order::voucherBeatsCard() draws between "no PWD/Senior was claimed at
     * all" (the voucher is unopposed) and "a PWD/Senior worth ₱0.00".
     */
    const discountOn = document.getElementById('manualDiscountToggle').checked;

    const cardDiscount = discountOn
        ? Math.round(Math.min(subtotal * 0.20, subtotal) * 100) / 100
        : null;

    /*
     * The voucher's worth as the SERVER priced it — never recomputed here.
     * null until a code has been checked and accepted.
     */
    const voucherDiscount = manualVoucher ? manualVoucher.discount : null;

    /*
     * WHICH ONE WINS — the same rule and the same tie-break as
     * Order::voucherBeatsCard(), which is what storeManualOrder() and the
     * customer's own checkout both decide it by. Strictly greater-than, so a
     * PWD/Senior discount WINS A TIE and the voucher is left unspent.
     *
     * This must agree with the server exactly: staff read this figure out and
     * take the customer's money against it, so a disagreement is a drawer that
     * does not balance at the end of the shift.
     */
    let discount = 0;

    if (voucherDiscount !== null && (cardDiscount === null || voucherDiscount > cardDiscount)) {
        discount = voucherDiscount;
    } else if (cardDiscount !== null) {
        discount = cardDiscount;
    }

    const total = Math.max(0, Math.round((subtotal - discount) * 100) / 100);

    const discountRow = document.getElementById('manualDiscountRow');

    if (discount > 0) {
        discountRow.style.display = 'flex';
        document.getElementById('manualDiscountDisplay').textContent =
            '-₱' + discount.toFixed(2);
    } else {
        discountRow.style.display = 'none';
    }

    document.getElementById('manualTotal').textContent =
        '₱' + total.toFixed(2);

    const amountPaid =
        parseFloat(document.getElementById('manualAmountPaid').value) || 0;

    const change = Math.max(0, amountPaid - total);

    document.getElementById('manualChange').textContent =
        '₱' + change.toFixed(2);
}

function escapeManualHtml(value)
{
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


function openOrderDetails(button) {
    const modal = document.getElementById('pcOrderDetailsModal');
    if (!modal) return;

    const itemsContainer = document.getElementById('pcOrderModalItems');

    document.getElementById('pcOrderModalNumber').textContent =
        button.dataset.orderNumber || '-';

    const type = button.dataset.orderType || '';
    const table = button.dataset.table || '';

    const typeLabel = type === 'dine_in'
        ? (table ? `Dine-in · Table ${table}` : 'Dine-in')
        : type === 'pick_up'
            ? 'Pickup'
            : 'Walk-in';

    document.getElementById('pcOrderModalType').textContent = typeLabel;
    document.getElementById('pcOrderModalStatus').textContent =
        button.dataset.status || '-';

    document.getElementById('pcOrderModalSubtitle').textContent =
        button.dataset.created ? `Placed ${button.dataset.created}` : '';

    const payMethod = button.dataset.payment || 'Cash';
    const payState = button.dataset.paymentStatus || 'Unpaid';

    document.getElementById('pcOrderModalPayment').textContent =
        `${payMethod} · ${payState}`;

    document.getElementById('pcOrderModalSubtotal').textContent =
        '₱' + (button.dataset.subtotal || '0.00');

    document.getElementById('pcOrderModalDiscount').textContent =
        '-₱' + (button.dataset.discount || '0.00');

    document.getElementById('pcOrderModalTotal').textContent =
        '₱' + (button.dataset.total || '0.00');

    let items = [];
    try {
        items = JSON.parse(button.dataset.items || '[]');
    } catch (error) {
        items = [];
    }

    itemsContainer.innerHTML = '';

    if (!items.length) {
        itemsContainer.innerHTML =
            '<div class="pc-order-modal-item"><span>No items found.</span></div>';
    } else {
        items.forEach(function(item) {
            const row = document.createElement('div');
            row.className = 'pc-order-modal-item';

            const name = document.createElement('span');
            const qty = document.createElement('span');
            qty.className = 'qty';
            qty.textContent = item.quantity + '×';

            name.appendChild(qty);
            name.appendChild(document.createTextNode(' ' + (item.name || '')));

            const price = document.createElement('span');
            price.className = 'price';
            price.textContent = '₱' + item.subtotal;

            row.appendChild(name);
            row.appendChild(price);
            itemsContainer.appendChild(row);
        });
    }

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeOrderDetails() {
    const modal = document.getElementById('pcOrderDetailsModal');
    if (!modal) return;

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

document.getElementById('pcOrderDetailsModal')?.addEventListener('click', function(event) {
    if (event.target === this) {
        closeOrderDetails();
    }
});

function openDiscountModal(button) {
    const modal = document.getElementById('pcDiscountModal');
    if (!modal) return;

    document.getElementById('pcDiscountOrder').textContent =
        button.dataset.order || '-';
    document.getElementById('pcDiscountType').textContent =
        button.dataset.type || 'PWD / Senior Citizen';
    document.getElementById('pcDiscountName').textContent =
        button.dataset.name || 'Not provided';
    document.getElementById('pcDiscountIdNumber').textContent =
        button.dataset.idNumber || 'Not provided';
    document.getElementById('pcDiscountExpiration').textContent =
        button.dataset.expiration || 'Not provided';

    const status = (button.dataset.status || 'approved').toLowerCase();
    const statusBox = document.getElementById('pcDiscountStatus');

    statusBox.className = 'pc-discount-modal-status ' + status;

    if (status === 'pending') {
        statusBox.innerHTML = '<i class="bi bi-hourglass-split"></i> Pending Verification';
    } else if (status === 'rejected') {
        statusBox.innerHTML = '<i class="bi bi-x-circle-fill"></i> Rejected';
    } else {
        statusBox.innerHTML = '<i class="bi bi-check-circle-fill"></i> Approved';
    }

    const image = document.getElementById('pcDiscountIdImage');
    if (button.dataset.image) {
        image.src = button.dataset.image;
        image.style.display = 'block';
    } else {
        image.removeAttribute('src');
        image.style.display = 'none';
    }

    const actions = document.getElementById('pcDiscountModalActions');
    const approveForm = document.getElementById('pcDiscountApproveForm');
    const rejectForm = document.getElementById('pcDiscountRejectForm');

    if (status === 'pending') {
        actions.style.display = 'grid';
        approveForm.action = button.dataset.approveUrl || '';
        rejectForm.action = button.dataset.rejectUrl || '';
    } else {
        actions.style.display = 'none';
    }

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeDiscountModal() {
    const modal = document.getElementById('pcDiscountModal');
    if (!modal) return;

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeDiscountModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    toggleManualTable();

    const manualModal = document.getElementById('manualOrderModal');
    const optionsModal = document.getElementById('manualOptionsModal');

    if (manualModal) {
        manualModal.addEventListener('click', function(event) {
            if (event.target === this) {
                closeManualOrder();
            }
        });
    }

    if (optionsModal) {
        optionsModal.addEventListener('click', function(event) {
            if (event.target === this) {
                closeManualOptions();
            }
        });
    }

    document.addEventListener('change', function(event) {
        if (event.target.classList.contains('manual-option-check')) {
            updateManualOptionPreview();
        }
    });

    document.getElementById('manualOrderForm')
        .addEventListener('submit', function(event) {
            const itemCount = Object.keys(manualCart).length;

            if (itemCount === 0) {
                event.preventDefault();
                alert('Please add at least one menu item.');
                return;
            }

            const total = Object.keys(manualCart)
                .reduce(function(sum, key) {
                    return sum +
                        manualCart[key].price *
                        manualCart[key].quantity;
                }, 0);

            const amountPaid =
                parseFloat(document.getElementById('manualAmountPaid').value) || 0;

            if (amountPaid < total) {
                event.preventDefault();

                alert(
                    'Amount paid must be at least ₱' +
                    total.toFixed(2)
                );
            }
        });

    /*
     * Reopen the Manual Order modal when the server rejected the submission.
     *
     * storeManualOrder() reports every refusal with back()->withErrors(), which
     * re-renders this page with the modal back at display:none — so the error
     * block inside it was never on screen and staff saw a silent no-op. The
     * order simply never existed and nothing said why.
     *
     * Reopening is only half of it: withInput() flashes the posted fields, so
     * the cart is rebuilt from old('items') against the menu cards already on
     * the page. Without that, staff would see the error but lose every line
     * they had keyed in, which is its own reason not to trust the screen.
     *
     * old('amount_paid') is the marker for "this page load is a bounced manual
     * order" — no other form on the dashboard posts that field.
     */
    // The discount controls start disabled, so an untouched checkbox submits
    // nothing. onchange alone would leave them enabled until first clicked.
    setManualDiscountEnabled(
        document.getElementById('manualDiscountToggle').checked
    );

    (function reopenRejectedManualOrder() {
        const failed = @json($errors->any() && old('amount_paid') !== null);

        if (!failed) {
            return;
        }

        const setValue = function (id, value) {
            const el = document.getElementById(id);
            if (el && value !== null && value !== undefined) {
                el.value = value;
            }
        };

        setValue('manualBranch', @json(old('branch_id')));
        setValue('manualOrderType', @json(old('order_type')));
        setValue('manualTableNumber', @json(old('table_number')));
        setValue('manualPaymentMethod', @json(old('payment_method')));
        setValue('manualAmountPaid', @json(old('amount_paid')));

        // Branch drives which menu cards are visible, so open (and therefore
        // filter) before rebuilding the cart from those cards.
        toggleManualTable();
        openManualOrder();

        const oldItems = @json(old('items')) || {};

        Object.keys(oldItems).forEach(function (key) {
            const row = oldItems[key] || {};

            const card = document.querySelector(
                '.manual-menu-card[data-id="' + row.menu_item_id + '"]'
            );

            if (!card) {
                return;
            }

            let available = [];

            try {
                available = JSON.parse(card.dataset.options || '[]');
            } catch (error) {
                available = [];
            }

            const chosenIds = (row.options || []).map(Number);

            const chosen = available
                .filter(function (option) {
                    return chosenIds.indexOf(Number(option.id)) !== -1;
                })
                .map(function (option) {
                    return {
                        id: option.id,
                        name: option.name,
                        price: parseFloat(option.additional_price) || 0
                    };
                });

            const optionTotal = chosen.reduce(function (sum, option) {
                return sum + option.price;
            }, 0);

            const nameEl = card.querySelector('.manual-item-name');

            manualCart[key] = {
                key: key,
                id: parseInt(row.menu_item_id, 10),
                name: nameEl ? nameEl.textContent.trim() : 'Item',
                price: (parseFloat(card.dataset.price) || 0) + optionTotal,
                quantity: parseInt(row.quantity, 10) || 1,
                options: chosen
            };
        });

        // Restore the discount box last: setManualDiscountEnabled() decides
        // whether those controls are submitted at all.
        const wantsDiscount = @json(old('discount_type') !== null);
        const toggle = document.getElementById('manualDiscountToggle');

        if (toggle) {
            toggle.checked = wantsDiscount;
        }

        if (wantsDiscount) {
            setValue('manualDiscountType', @json(old('discount_type')));
            setValue('manualDiscountName', @json(old('discount_beneficiary_name')));
            setValue('manualDiscountIdNumber', @json(old('discount_beneficiary_id')));
        }

        /*
         * The voucher code the order was refused with, so staff do not have to
         * ask the customer to read it out again. Deliberately restored WITHOUT
         * a price: the submission just told us this basket and this code did
         * not go together, so re-quoting a discount for it would be quoting a
         * figure the server has already refused. Staff press Check again.
         */
        setValue('manualVoucherCode', @json(old('voucher_code')));

        toggleManualDiscount();
        renderManualCart();

        const errors = document.getElementById('manualOrderErrors');

        if (errors) {
            errors.scrollIntoView({ block: 'center' });
        }
    })();
});
</script>
<div
    id="manualOrderModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        z-index:9999;
        background:rgba(0,0,0,0.55);
        padding:1rem;
        overflow-y:auto;
    "
>
    <div
        style="
            width:min(100%, 900px);
            margin:2rem auto;
            background:white;
            border-radius:16px;
            overflow:hidden;
            box-shadow:0 20px 60px rgba(0,0,0,0.25);
        "
    >

        <div
            style="
                background:#C0392B;
                color:white;
                padding:1rem 1.25rem;
                display:flex;
                align-items:center;
                justify-content:space-between;
            "
        >
            <div>
                <div style="font-size:1.05rem;font-weight:800;">
                    Manual Order
                </div>

                <div style="font-size:0.72rem;opacity:0.85;">
                    Create an order for a customer who needs assistance
                </div>
            </div>

            <button
                type="button"
                onclick="closeManualOrder()"
                style="
                    border:0;
                    background:rgba(255,255,255,0.18);
                    color:white;
                    width:34px;
                    height:34px;
                    border-radius:50%;
                    cursor:pointer;
                    font-size:1.1rem;
                "
            >
                ×
            </button>
        </div>


        <form
            method="POST"
            action="{{ route('admin.manual-order.store') }}"
            id="manualOrderForm"
        >

            @csrf

            <div style="padding:1.25rem;">

                @if($errors->any())
                    <div
                        id="manualOrderErrors"
                        style="
                            background:#f8d7da;
                            color:#721c24;
                            padding:0.75rem;
                            border-radius:8px;
                            margin-bottom:1rem;
                            font-size:0.8rem;
                        "
                    >
                        @foreach($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif


                <div
                    style="
                        display:grid;
                        grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
                        gap:0.75rem;
                        margin-bottom:1rem;
                    "
                >

                    <div>
                        <label class="form-label-custom">
                            Branch
                        </label>

                        <select
                            name="branch_id"
                            id="manualBranch"
                            class="form-control-custom"
                            required
                            onchange="filterManualItems()"
                        >
                            <option value="">
                                -- Select Branch --
                            </option>

                            @foreach($branches as $branch)
                                <option
                                    value="{{ $branch->id }}"
                                    {{ $selectedBranch !== 'all' && (int)$selectedBranch === (int)$branch->id ? 'selected' : '' }}
                                >
                                    {{ $branch->name }}
                                </option>
                            @endforeach

                        </select>
                    </div>


                    <div>
                        <label class="form-label-custom">
                            Order Type
                        </label>

                        <select
                            name="order_type"
                            id="manualOrderType"
                            class="form-control-custom"
                            required
                            onchange="toggleManualTable()"
                        >
                            <option value="pick_up">
                                Pick-up
                            </option>

                            <option value="dine_in">
                                Dine-in
                            </option>
                        </select>
                    </div>


                    <div id="manualTableWrapper" style="display:none;">
                        <label class="form-label-custom">
                            Table Number
                        </label>

                        <input
                            type="text"
                            name="table_number"
                            id="manualTableNumber"
                            class="form-control-custom"
                            autocomplete="off"
                        >
                    </div>

                </div>


                <div style="margin-bottom:1rem;">

                    <label class="form-label-custom">
                        Search Menu
                    </label>

                    <input
                        type="text"
                        id="manualMenuSearch"
                        class="form-control-custom"
                        aria-label="Search menu item"
                        autocomplete="off"
                        oninput="filterManualItems()"
                    >

                </div>


                <div
                    id="manualMenuItems"
                    style="
                        display:grid;
                        grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
                        gap:0.7rem;
                        max-height:320px;
                        overflow-y:auto;
                        padding:2px;
                    "
                >

                    @foreach($menuItems as $item)

                        <button
                            type="button"
                            class="manual-menu-card"
                            data-id="{{ $item->id }}"
                            data-name="{{ strtolower($item->name) }}"
                            data-category="{{ strtolower($item->category?->name ?? '') }}"
                            data-subcategory="{{ strtolower($item->subcategory?->name ?? '') }}"
                            data-branch="{{ $item->branch_id ?? 'all' }}"
                            data-price="{{ $item->price }}"
                            data-options="{{ $item->options->toJson() }}"
                            onclick="addManualItem({{ $item->id }})"
                            style="
                                display:flex;
                                align-items:center;
                                gap:0.55rem;
                                text-align:left;
                                border:1px solid #ead8d2;
                                background:#fff;
                                border-radius:10px;
                                padding:0.55rem;
                                cursor:pointer;
                            "
                        >

                            {{-- Same image mechanism as the rest of admin (menu-items.blade.php,
                                 ads.blade.php): App\Support\Img::url(), not the plain asset() the
                                 customer menu uses. Menu item photos are saved under public/uploads
                                 and never need the storage symlink, so both resolve identically today
                                 — but Img::url() is the one that also copes with a file that ends up
                                 on the storage disk instead, which is exactly the class of bug item 29
                                 found (missing storage:link left GCash/PWD images broken). Using the
                                 same helper as every other admin thumbnail keeps this picker from being
                                 the one place that silently breaks if that ever happens here too.
                                 Fixed 44px square, not the full-size customer card: this is a counter
                                 tool, and a thumbnail only needs to help recognition, not showcase the
                                 item. --}}
                            <div
                                style="
                                    width:44px;
                                    height:44px;
                                    flex:0 0 44px;
                                    border-radius:7px;
                                    overflow:hidden;
                                    background:#faf7f5;
                                    display:grid;
                                    place-items:center;
                                "
                            >
                                @if($item->image)
                                    <img
                                        src="{{ \App\Support\Img::url($item->image) }}"
                                        alt=""
                                        loading="lazy"
                                        style="width:100%;height:100%;object-fit:cover;"
                                    >
                                @else
                                    {{-- Same bi-image placeholder pattern established in item 23 —
                                         never a broken-image icon when there is no photo. --}}
                                    <i class="bi bi-image" style="color:#ccc;font-size:1.05rem;"></i>
                                @endif
                            </div>

                            <div style="min-width:0;flex:1;">

                                <div
                                    class="manual-item-name"
                                    style="
                                        font-weight:800;
                                        color:#5A2920;
                                        font-size:0.82rem;
                                        margin-bottom:0.15rem;
                                        overflow:hidden;
                                        text-overflow:ellipsis;
                                        white-space:nowrap;
                                    "
                                >
                                    {{ $item->name }}
                                </div>

                                <div
                                    style="
                                        color:#C0392B;
                                        font-weight:800;
                                        font-size:0.8rem;
                                    "
                                >
                                    ₱{{ number_format($item->price, 2) }}
                                </div>

                                <div
                                    style="
                                        color:#999;
                                        font-size:0.65rem;
                                        margin-top:0.15rem;
                                        overflow:hidden;
                                        text-overflow:ellipsis;
                                        white-space:nowrap;
                                    "
                                >
                                    {{ $item->category?->name ?? '' }}
                                    @if($item->subcategory)
                                        · {{ $item->subcategory->name }}
                                    @endif
                                </div>

                            </div>

                        </button>
                        </button>

                    @endforeach

                </div>


                <div
                    id="manualCart"
                    style="
                        margin-top:1rem;
                        border-top:1px solid #eee;
                        padding-top:1rem;
                    "
                >

                    <div
                        style="
                            font-size:0.85rem;
                            font-weight:800;
                            color:#5A2920;
                            margin-bottom:0.6rem;
                        "
                    >
                        Order Items
                    </div>

                    <div id="manualCartItems"></div>

                    <div
                        id="manualEmptyCart"
                        style="
                            color:#999;
                            font-size:0.78rem;
                            padding:1rem;
                            text-align:center;
                            background:#faf7f5;
                            border-radius:8px;
                        "
                    >
                        No items added yet.
                    </div>

                </div>


                {{--
                    The customer's voucher code, keyed in by staff on their
                    behalf. Some customers cannot use the app themselves —
                    elderly, not comfortable with a phone, or they simply did
                    not bring one — and staff already key in the whole order for
                    them here.

                    Never `required`, and never a `pattern`: whether a code is
                    good is decided by the server with the exact same validator
                    the customer's own checkout uses, and a second rule in the
                    markup would refuse codes the real one accepts.
                --}}
                <div style="margin-top:1rem;">

                    <label class="form-label-custom" for="manualVoucherCode">
                        Voucher Code <span style="color:#999;font-weight:400;">(optional)</span>
                    </label>

                    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-start;">
                        <input
                            type="text"
                            name="voucher_code"
                            id="manualVoucherCode"
                            class="form-control-custom"
                            autocomplete="off"
                            maxlength="64"
                            style="flex:1 1 220px;max-width:260px;text-transform:uppercase;"
                            oninput="clearManualVoucher()"
                        >

                        {{--
                            Same class as the Cancel button in this modal's own
                            footer (below) — border, white background, muted
                            text. Apply is a secondary, in-context action next
                            to a single field, not the form's primary submit,
                            so it takes the modal's SECONDARY button style
                            rather than the solid red PRIMARY one Place Order
                            uses.
                        --}}
                        <button
                            type="button"
                            id="manualVoucherCheck"
                            class="manual-modal-btn manual-modal-btn-secondary"
                            onclick="checkManualVoucher()"
                        >
                            Apply
                        </button>
                    </div>

                    {{--
                        The server's own sentence, shown verbatim — expired,
                        already used, not yet valid, below the minimum order
                        each have their own wording, so staff can read the
                        reason straight out to the customer instead of guessing
                        at a generic failure.
                    --}}
                    <div
                        id="manualVoucherMessage"
                        style="display:none;margin-top:0.4rem;font-size:0.78rem;"
                    ></div>

                </div>

                <div style="margin-top:1rem;">

                    <label class="form-label-custom" style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                        <input
                            type="checkbox"
                            id="manualDiscountToggle"
                            onchange="toggleManualDiscount()"
                            style="width:16px;height:16px;cursor:pointer;"
                        >
                        PWD / Senior Citizen Discount (20%)
                    </label>

                    <div
                        id="manualDiscountFields"
                        style="
                            display:none;
                            margin-top:0.6rem;
                            padding:0.85rem;
                            background:#faf7f5;
                            border-radius:8px;
                            display:grid;
                            grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
                            gap:0.6rem;
                        "
                    >

                        <div>
                            <label class="form-label-custom">
                                Type
                            </label>

                            {{--
                                Ships disabled on purpose: a hidden but enabled
                                <select> still posts its first option, and this
                                one has no empty option, so every manual order
                                used to carry discount_type=pwd whether staff
                                asked for a discount or not. The server then
                                rejected the order for a missing beneficiary
                                name. setManualDiscountEnabled() turns these
                                three controls back on with the checkbox.
                            --}}
                            <select
                                name="discount_type"
                                id="manualDiscountType"
                                class="form-control-custom"
                                onchange="updateManualTotals()"
                                disabled
                            >
                                <option value="pwd">PWD</option>
                                <option value="senior">Senior Citizen</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label-custom">
                                Beneficiary Name
                            </label>

                            <input
                                type="text"
                                name="discount_beneficiary_name"
                                id="manualDiscountName"
                                class="form-control-custom"
                                autocomplete="off"
                                disabled
                            >
                        </div>

                        <div>
                            <label class="form-label-custom">
                                ID Number
                            </label>

                            <input
                                type="text"
                                name="discount_beneficiary_id"
                                id="manualDiscountIdNumber"
                                class="form-control-custom"
                                autocomplete="off"
                                disabled
                            >
                        </div>

                    </div>

                </div>


                <div
                    style="
                        display:grid;
                        grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
                        gap:0.75rem;
                        margin-top:1rem;
                    "
                >

                    <div>
                        <label class="form-label-custom">
                            Payment
                        </label>

                        <select
                            name="payment_method"
                            id="manualPaymentMethod"
                            class="form-control-custom"
                            required
                        >
                            <option value="cash">
                                Cash
                            </option>

                            <option value="gcash">
                                GCash
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label-custom">
                            Amount Paid
                        </label>

                        <input
                            type="number"
                            name="amount_paid"
                            id="manualAmountPaid"
                            class="form-control-custom"
                            min="0"
                            step="0.01"
                            value="0"
                            oninput="updateManualTotals()"
                            required
                        >
                    </div>

                </div>


                <div
                    style="
                        background:#fdf4ef;
                        border-radius:10px;
                        padding:0.85rem;
                        margin-top:1rem;
                    "
                >

                    <div
                        style="
                            display:flex;
                            justify-content:space-between;
                            font-size:0.8rem;
                            color:#777;
                        "
                    >
                        <span>Subtotal</span>

                        <strong id="manualSubtotal">
                            ₱0.00
                        </strong>
                    </div>


                    <div
                        id="manualDiscountRow"
                        style="
                            display:none;
                            justify-content:space-between;
                            font-size:0.8rem;
                            color:#2E7D5B;
                            margin-top:0.35rem;
                        "
                    >
                        <span>Discount</span>

                        <strong id="manualDiscountDisplay">
                            -₱0.00
                        </strong>
                    </div>


                    <div
                        style="
                            display:flex;
                            justify-content:space-between;
                            font-size:1rem;
                            font-weight:800;
                            color:#C0392B;
                            margin-top:0.35rem;
                        "
                    >
                        <span>Total</span>

                        <strong id="manualTotal">
                            ₱0.00
                        </strong>
                    </div>


                    <div
                        style="
                            display:flex;
                            justify-content:space-between;
                            font-size:0.8rem;
                            color:#2E7D5B;
                            margin-top:0.35rem;
                        "
                    >
                        <span>Change</span>

                        <strong id="manualChange">
                            ₱0.00
                        </strong>
                    </div>

                </div>

            </div>


            <div
                style="
                    padding:0.85rem 1.25rem;
                    border-top:1px solid #eee;
                    display:flex;
                    justify-content:flex-end;
                    gap:0.5rem;
                "
            >

                <button
                    type="button"
                    class="manual-modal-btn manual-modal-btn-secondary"
                    onclick="closeManualOrder()"
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    id="manualSubmitButton"
                    class="manual-modal-btn manual-modal-btn-primary"
                >
                    <i class="bi bi-check-circle"></i>
                    Place Order
                </button>

            </div>

        </form>

    </div>
</div>

<div
    id="manualOptionsModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        z-index:10000;
        background:rgba(0,0,0,0.55);
        align-items:center;
        justify-content:center;
        padding:1rem;
    "
>
    <div
        style="
            width:min(100%, 460px);
            max-height:90vh;
            overflow-y:auto;
            background:white;
            border-radius:16px;
            box-shadow:0 20px 60px rgba(0,0,0,0.25);
        "
    >
        <div
            style="
                background:#C0392B;
                color:white;
                padding:1rem 1.15rem;
                display:flex;
                align-items:center;
                justify-content:space-between;
            "
        >
            <div>
                <div
                    id="manualOptionsTitle"
                    style="font-size:1rem;font-weight:800;"
                >
                    Menu Options
                </div>
                <div
                    id="manualOptionsBasePrice"
                    style="font-size:0.7rem;opacity:0.85;margin-top:0.15rem;"
                >
                    Base price: ₱0.00
                </div>
            </div>

            <button
                type="button"
                onclick="closeManualOptions()"
                style="
                    border:0;
                    background:rgba(255,255,255,0.18);
                    color:white;
                    width:32px;
                    height:32px;
                    border-radius:50%;
                    cursor:pointer;
                    font-size:1.1rem;
                "
            >
                ×
            </button>
        </div>

        <div style="padding:1rem;">
            <div
                style="
                    font-size:0.78rem;
                    color:#8A6A61;
                    margin-bottom:0.65rem;
                "
            >
                Select the options requested by the customer.
            </div>

            <div
                id="manualOptionsList"
                style="
                    display:grid;
                    gap:0.5rem;
                "
            ></div>

            <div
                style="
                    margin-top:1rem;
                    padding:0.75rem;
                    background:#fdf4ef;
                    border-radius:9px;
                    display:flex;
                    justify-content:space-between;
                    align-items:center;
                "
            >
                <span
                    style="
                        font-size:0.78rem;
                        color:#777;
                    "
                >
                    Item Price
                </span>

                <strong
                    id="manualOptionsFinalPrice"
                    style="
                        color:#C0392B;
                        font-size:1rem;
                    "
                >
                    ₱0.00
                </strong>
            </div>
        </div>

        <div
            style="
                padding:0.75rem 1rem;
                border-top:1px solid #eee;
                display:flex;
                justify-content:flex-end;
                gap:0.5rem;
            "
        >
            <button
                type="button"
                onclick="closeManualOptions()"
                style="
                    border:1px solid #ddd;
                    background:white;
                    color:#666;
                    border-radius:8px;
                    padding:0.6rem 1rem;
                    font-weight:700;
                    cursor:pointer;
                "
            >
                Cancel
            </button>

            <button
                type="button"
                onclick="confirmManualOptions()"
                style="
                    border:0;
                    background:#C0392B;
                    color:white;
                    border-radius:8px;
                    padding:0.6rem 1rem;
                    font-weight:800;
                    cursor:pointer;
                "
            >
                Add to Order
            </button>
        </div>
    </div>
</div>

@endsection
