{{--
    The pop-up behind every View Receipt button. Include once per page.

    The image is asked for only when the pop-up opens, so a busy board does not
    load a picture per card every refresh. Outside #pc-live-region on purpose:
    the board's background refresh replaces the cards, and must not close this.
--}}
<div id="gpProofModal" class="gp-modal" aria-hidden="true" data-testid="gcash-proof-modal">
    <div class="gp-modal-box" role="dialog" aria-modal="true" aria-labelledby="gpProofTitle">
        <div class="gp-modal-head">
            <div>
                <h3 id="gpProofTitle" class="gp-modal-title">GCash Receipt</h3>
                <p id="gpProofSubtitle" class="gp-modal-subtitle"></p>
            </div>
            <button type="button" id="gpProofClose" class="gp-modal-close" onclick="closeGcashProof()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="gp-modal-body">
            <img id="gpProofImage" class="gp-modal-image" alt="Customer's GCash payment screenshot" data-testid="gcash-proof-image">
            <p id="gpProofMissing" class="gp-none" hidden>This screenshot could not be loaded.</p>
        </div>

        <div class="gp-modal-foot">
            <a id="gpProofFull" href="#" target="_blank" rel="noopener">Open full size</a>
        </div>
    </div>
</div>

@push('styles')
<style>
    .gp-view-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        width: min(220px, 100%);
        margin: 0.5rem auto 0;
        padding: 0.5rem 0.75rem;
        background: #fff;
        border: 1.5px solid #C0392B;
        border-radius: 6px;
        color: #C0392B;
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 0.76rem;
        font-weight: 700;
        line-height: 1.1;
        cursor: pointer;
    }
    .gp-view-btn:hover { background: #C0392B; color: #fff; }

    .gp-none {
        margin: 0.4rem 0 0;
        font-size: 0.7rem;
        color: #8A6A61;
        text-align: center;
    }

    .gp-modal {
        position: fixed;
        inset: 0;
        z-index: 1300;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background: rgba(59, 35, 32, 0.6);
    }
    .gp-modal.is-open { display: flex; }

    .gp-modal-box {
        width: min(640px, 100%);
        max-height: calc(100vh - 2rem);
        max-height: calc(100dvh - 2rem);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        background: #FFFDF9;
        border: 1px solid #F2D8CF;
        border-radius: 14px;
        box-shadow: 0 24px 70px rgba(90, 41, 32, 0.24);
    }

    .gp-modal-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.8rem 1rem;
        background: #FDE8DE;
        border-bottom: 1px solid #F2D8CF;
    }
    .gp-modal-title {
        margin: 0;
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.05rem;
        color: #8B1A1A;
    }
    .gp-modal-subtitle { margin: 0.15rem 0 0; font-size: 0.72rem; color: #8A6A61; }
    .gp-modal-close {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        display: grid;
        place-items: center;
        border: 1px solid rgba(192, 57, 43, 0.25);
        border-radius: 50%;
        background: #fff;
        color: #C0392B;
        cursor: pointer;
    }

    /* Scrolls rather than shrinks: a phone screenshot is tall, and fitted to the
       screen's height its reference number would be too small to read. */
    .gp-modal-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; background: #fff; }
    .gp-modal-image { display: block; width: 100%; height: auto; }

    .gp-modal-foot {
        padding: 0.55rem 1rem;
        text-align: center;
        border-top: 1px solid #F2D8CF;
        font-size: 0.75rem;
    }
    .gp-modal-foot a { color: #C0392B; font-weight: 700; }

    .pc-order-modal-proof { margin-top: 0.9rem; }
    .pc-order-modal-proof .label {
        display: block;
        margin-bottom: 0.35rem;
        font-size: 0.62rem;
        color: #8A6A61;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
    }
    .pc-order-modal-proof img {
        display: block;
        width: 100%;
        height: auto;
        border: 1px solid #F2D8CF;
        border-radius: 10px;
    }
    .pc-order-modal-proof .gp-none { text-align: left; margin: 0; }

    @media print {
        .gp-view-btn, .gp-none, .gp-modal { display: none !important; }
    }
</style>
@endpush

@push('scripts')
<script>
(function () {
    var modal = document.getElementById('gpProofModal');
    var image = document.getElementById('gpProofImage');
    var missing = document.getElementById('gpProofMissing');
    var opener = null;

    // The admin content column is a stacking context: inside it, the 390px top
    // bar would sit over this pop-up.
    document.body.appendChild(modal);

    image.addEventListener('error', function () {
        // removeAttribute below also lands here on some browsers.
        if (image.getAttribute('src')) {
            image.style.display = 'none';
            missing.hidden = false;
        }
    });

    window.openGcashProof = function (button) {
        var url = button.dataset.proofUrl;

        opener = button;
        image.style.display = '';
        missing.hidden = true;
        image.src = url;

        document.getElementById('gpProofFull').href = url;
        document.getElementById('gpProofSubtitle').textContent =
            button.dataset.orderNumber ? 'Order #' + button.dataset.orderNumber : '';

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.dataset.gpOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        document.getElementById('gpProofClose').focus();
    };

    window.closeGcashProof = function () {
        if (!modal.classList.contains('is-open')) {
            return;
        }

        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        image.removeAttribute('src');
        document.body.style.overflow = document.body.dataset.gpOverflow || '';

        // The board's refresh may have replaced the card meanwhile.
        if (opener && opener.isConnected) {
            opener.focus();
        }

        opener = null;
    };

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            window.closeGcashProof();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            window.closeGcashProof();
        }
    });
})();
</script>
@endpush
