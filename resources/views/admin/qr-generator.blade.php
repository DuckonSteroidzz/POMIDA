@extends('admin.layout')

@section('title', 'QR Generator - Peachy Admin')

@section('content')

<p class="page-title">QR Code Generator</p>

<style>
    /* Two-column: input controls on the left, generated output on the right.
       Collapses to a single stacked column on narrower screens so the fields
       never get squeezed. */
    .qr-split{display:grid;grid-template-columns:minmax(0,320px) minmax(0,1fr);gap:1.75rem;align-items:start;}
    @media (max-width:760px){.qr-split{grid-template-columns:1fr;}}
    .qr-split .qr-field{margin-bottom:0.9rem;}
    .qr-split .qr-field label{font-size:0.8rem;font-weight:600;color:#333;display:block;margin-bottom:0.3rem;}
    .qr-split .qr-field .form-control-custom{width:100%;margin-bottom:0;}
    .qr-split .qr-actions{margin-top:1rem;}
    .qr-split .qr-actions .btn-primary-custom{width:100%;}
    .qr-output{min-height:220px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:1.5rem;background:#FFF9F6;border:1px dashed #F0C9BA;border-radius:16px;}
    .qr-output-empty{color:#B9A69F;font-size:0.82rem;}

    /* The Occupied Tables list. The wrapper carries overflow-x:auto, so a narrow
       phone scrolls the table instead of the page. */
    .qr-table{width:100%;font-size:0.8rem;border-collapse:collapse;}
    .qr-table thead tr{background:#F4845F;color:white;}
    .qr-table th{padding:0.45rem;text-align:left;}
    .qr-table td{padding:0.45rem;}
    .qr-table tbody tr{border-bottom:1px solid #f0f0f0;}
    .qr-table .num{text-align:right;}

    /* The permanent code, wherever it is shown on this page. */
    .table-code{font-family:'Courier New',monospace;font-weight:700;letter-spacing:0.14em;color:#8B1A1A;white-space:nowrap;}

    /* Regenerate. Deliberately NOT the red of Clear: the two must never be
       reachable by the same stray click. */
    .btn-regen{background:#6B4A42;}
</style>

<div class="content-card">
    <p style="font-size:0.85rem; color:#555; margin-bottom:1.25rem;">
        Generate the printable card for each table. The card carries the table's QR
        <strong>and its permanent code</strong> — customers scan the QR, or type the code
        if their camera will not focus. The code is static: it never expires and is
        never used up during normal operation, so print it once and it works for good.
        Entering a table number that is not registered yet adds it and gives it a code.
        @if($canRegenerate)
            Use <strong>Regenerate Code</strong> beside the card preview if a table's code
            has leaked or is receiving bogus orders: it stops both the printed QR and the
            typed code for that table working immediately, issues a fresh one for that
            table only, and redraws the card — you must print and place the new card
            afterward.
        @endif
    </p>

    <div class="qr-split">
        {{-- Left column: input controls --}}
        <div>
            <div class="qr-field">
                <label for="branchSelect">Branch</label>
                <select id="branchSelect" class="form-control-custom">
                    @foreach($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="qr-field">
                <label for="tableNumber">Table Number</label>
                <input type="number" id="tableNumber" class="form-control-custom" min="1">
            </div>
            <div class="qr-actions">
                <button onclick="generateQR()" class="btn-primary-custom" style="padding:0.55rem 1.5rem;" id="generateBtn">
                    <i class="bi bi-qr-code"></i> Generate QR
                </button>
            </div>
            <p id="qrError" style="display:none; font-size:0.8rem; color:#B3261E; background:#FDECEA; border:1px solid #F5C2BE; padding:0.6rem 0.8rem; border-radius:8px; margin-top:1rem;"></p>
        </div>

        {{-- Right column: generated card preview.
             This canvas IS the artwork that gets printed and downloaded, so what
             staff see on screen is exactly what comes out of the printer. --}}
        <div class="qr-output">
            <p class="qr-output-empty" id="qrEmpty">The generated table card will appear here.</p>
            <div id="qrResult" style="display:none; width:100%; text-align:center;">
                <canvas id="cardCanvas" style="max-width:100%; width:280px; box-shadow:0 2px 12px rgba(0,0,0,0.12); border-radius:8px;"></canvas>
                <p style="font-size:0.75rem; color:#555; margin:0.9rem 0 0;">
                    Printed card for <strong id="cardSummary"></strong>
                </p>
                <div style="display:flex; gap:0.6rem; justify-content:center; flex-wrap:wrap; margin-top:1rem;">
                    <button onclick="printCard()" class="btn-primary-custom" style="padding:0.5rem 1.2rem; background:#4CAF50;" id="printBtn">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <button onclick="downloadCard()" class="btn-primary-custom" style="padding:0.5rem 1.2rem; background:#2196F3;" id="downloadBtn">
                        <i class="bi bi-download"></i> Download PNG
                    </button>
                    @if($canRegenerate)
                        {{-- Rotates the permanent code for the branch + table this card
                             was generated for, then redraws the preview from the
                             response so the printed code is never left stale. --}}
                        <button onclick="regenerateCurrentCode()" class="btn-primary-custom btn-regen" style="padding:0.5rem 1.2rem;" id="regenCardBtn">
                            <i class="bi bi-arrow-repeat"></i> Regenerate Code
                        </button>
                    @endif
                </div>
                <p id="regenMsg" style="display:none; font-size:0.78rem; padding:0.5rem 0.75rem; border-radius:8px; margin:0.9rem 0 0;"></p>
            </div>
        </div>
    </div>

    {{-- Off-screen QR the card artwork is drawn from. --}}
    <div id="qrSource" style="position:absolute; left:-10000px; top:0;"></div>

</div>

{{-- ══════════ OCCUPIED TABLES ══════════
     A table holds its dine-in session until the order is completed or
     cancelled. This is the manual release for the customer who scanned,
     browsed, and walked out without ordering. --}}
<div class="content-card" style="margin-top:1rem;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
        <p class="page-title" style="font-size:1rem; margin:0;">
            <i class="bi bi-table"></i> Occupied Tables
        </p>

        <button onclick="loadOccupancy()" class="btn-primary-custom" style="padding:0.4rem 1rem; font-size:0.78rem;" id="refreshTablesBtn">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>

    <p style="font-size:0.8rem; color:#555; margin:0.4rem 0 1rem;">
        Everyone at a table shares one session, so a second or third person scanning
        the same QR joins it rather than being turned away. A table frees itself when
        its order is completed or cancelled, and again after
        <strong>{{ \App\Services\TableOccupancy::INACTIVITY_MINUTES }} minutes</strong>
        with no sign of the customer. Clear it by hand only when you know they have gone —
        it ends the session and leaves any order exactly as it is.
    </p>

    <p id="tablesMsg" style="display:none; font-size:0.8rem; padding:0.6rem 0.8rem; border-radius:8px; margin-bottom:1rem;"></p>

    <div id="tablesWrap" style="overflow-x:auto;">
        <table class="qr-table">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Table</th>
                    <th>Order</th>
                    <th>Occupied since</th>
                    <th class="num">Action</th>
                </tr>
            </thead>
            <tbody id="tablesBody">
                <tr><td colspan="5" style="padding:0.9rem; color:#888;">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>

@endsection

@push('scripts')
<script src="/vendor/qrcode.min.js"></script>
<script>
    const CARD_ENDPOINT = "{{ route('admin.qr-generator.table-card') }}";

    // Card artwork is rendered at roughly 2x so print output stays crisp.
    const CARD_W = 680;
    const CARD_H = 900;

    let currentCard = null;

    function showError(message) {
        const el = document.getElementById('qrError');
        el.textContent = message;
        el.style.display = message ? 'block' : 'none';
    }

    /**
     * Render the QR for `text` off-screen and hand back its canvas.
     * qrcodejs normally draws to a canvas, but some builds swap in an <img>,
     * so both shapes are handled.
     */
    function renderQrCanvas(text) {
        const holder = document.getElementById('qrSource');
        holder.innerHTML = '';

        new QRCode(holder, {
            text: text,
            width: 460,
            height: 460,
            colorDark: '#2A1510',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H
        });

        return new Promise(function (resolve, reject) {
            const attempt = function (tries) {
                const canvas = holder.querySelector('canvas');
                if (canvas && canvas.width > 0) {
                    resolve(canvas);
                    return;
                }

                const img = holder.querySelector('img');
                if (img && img.src) {
                    const c = document.createElement('canvas');
                    c.width = 460;
                    c.height = 460;
                    const i = new Image();
                    i.onload = function () {
                        c.getContext('2d').drawImage(i, 0, 0, 460, 460);
                        resolve(c);
                    };
                    i.onerror = function () { reject(new Error('QR image failed to render.')); };
                    i.src = img.src;
                    return;
                }

                if (tries <= 0) {
                    reject(new Error('QR could not be rendered.'));
                    return;
                }
                setTimeout(function () { attempt(tries - 1); }, 50);
            };
            attempt(20);
        });
    }

    function roundedRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    /** Draw the full table card onto #cardCanvas. */
    function drawCard(data, qrCanvas) {
        const canvas = document.getElementById('cardCanvas');
        canvas.width = CARD_W;
        canvas.height = CARD_H;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, CARD_W, CARD_H);

        ctx.strokeStyle = '#F4845F';
        ctx.lineWidth = 6;
        roundedRect(ctx, 14, 14, CARD_W - 28, CARD_H - 28, 28);
        ctx.stroke();

        ctx.textAlign = 'center';

        ctx.fillStyle = '#8B1A1A';
        ctx.font = '700 40px Georgia, serif';
        ctx.fillText('Peachy Cakes', CARD_W / 2, 96);
        ctx.fillStyle = '#C0392B';
        ctx.font = '600 26px Georgia, serif';
        ctx.fillText('and Deli Cafe', CARD_W / 2, 132);

        ctx.fillStyle = '#6B4A42';
        ctx.font = '600 20px Arial, sans-serif';
        ctx.fillText(data.branch_name, CARD_W / 2, 176);

        // Table number is what tells staff which card goes on which table, so
        // it sits above the QR at the largest size on the card.
        ctx.fillStyle = '#FDE8DE';
        roundedRect(ctx, CARD_W / 2 - 150, 200, 300, 96, 20);
        ctx.fill();

        ctx.fillStyle = '#8B1A1A';
        ctx.font = '700 22px Arial, sans-serif';
        ctx.fillText('TABLE', CARD_W / 2, 234);
        ctx.font = '700 54px Arial, sans-serif';
        ctx.fillText(String(data.table_number), CARD_W / 2, 284);

        /*
         * The QR gives up a little size so the permanent code box below it fits
         * above the footer without overlapping. Laid out explicitly:
         *   table badge ends 296 | QR 316-666 | caption 700 | code box 716-820
         *   | footer 854 | card bottom 900
         * At the 150mm print width in printCard() a 350px QR is about 55mm on
         * paper, comfortably above the ~25mm a phone camera needs.
         */
        const qrSize = 350;
        const qrX = (CARD_W - qrSize) / 2;
        const qrY = 316;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(qrX - 10, qrY - 10, qrSize + 20, qrSize + 20);
        ctx.drawImage(qrCanvas, qrX, qrY, qrSize, qrSize);

        ctx.fillStyle = '#5A2920';
        ctx.font = '600 22px Arial, sans-serif';
        ctx.fillText('Scan to start your dine-in order', CARD_W / 2, qrY + qrSize + 34);

        /*
         * THE PERMANENT CODE, PRINTED.
         *
         * The card used to be QR-only, on the reasoning that a printed code
         * cannot be revoked. That reasoning has not changed — it is still true,
         * and it is the accepted cost of this feature — but the alternative it
         * pointed to (find a staff member, every time, forever) is not
         * something a customer with a phone that will not focus should have to
         * do. The code is now printed because it is the only thing that is any
         * use to that customer when the QR fails, which is the entire reason a
         * fallback exists.
         *
         * What makes it acceptable is the escape hatch: an admin can Regenerate
         * this table's code with the link under Print/Download once it has been
         * generated, which kills the printed one immediately. See
         * App\Services\TableEntry.
         *
         * Drawn large, monospaced and well spaced because it is read off an
         * acrylic standee at arm's length by someone squinting at it.
         */
        const codeBoxY = qrY + qrSize + 50;
        const codeBoxH = 104;

        ctx.fillStyle = '#FDE8DE';
        roundedRect(ctx, 60, codeBoxY, CARD_W - 120, codeBoxH, 18);
        ctx.fill();

        ctx.fillStyle = '#7A5A52';
        ctx.font = '600 17px Arial, sans-serif';
        ctx.fillText('Can’t scan? Enter this code', CARD_W / 2, codeBoxY + 32);

        ctx.fillStyle = '#8B1A1A';
        ctx.font = '700 44px "Courier New", monospace';
        // Spaced out so 8 characters cannot be misread as one blur.
        ctx.fillText(String(data.code).split('').join(' '), CARD_W / 2, codeBoxY + 82);

        ctx.fillStyle = '#8A7069';
        ctx.font = '400 15px Arial, sans-serif';
        ctx.fillText(data.branch_code + ' - Table ' + data.table_number, CARD_W / 2, CARD_H - 46);
    }

    async function generateQR() {
        showError('');

        const table = document.getElementById('tableNumber').value.trim();
        const branch = document.getElementById('branchSelect').value;

        if (!table) {
            showError('Please enter a table number.');
            return;
        }

        const btn = document.getElementById('generateBtn');
        btn.disabled = true;

        try {
            const res = await fetch(
                CARD_ENDPOINT + '?branch_id=' + encodeURIComponent(branch) +
                '&table_number=' + encodeURIComponent(table),
                { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }
            );

            if (!res.ok) {
                const body = await res.json().catch(function () { return {}; });
                throw new Error(body.message || 'Could not generate this table card.');
            }

            const data = await res.json();
            const qrCanvas = await renderQrCanvas(data.url);

            drawCard(data, qrCanvas);
            currentCard = data;

            document.getElementById('cardSummary').textContent =
                data.branch_name + ' - Table ' + data.table_number;
            document.getElementById('qrResult').style.display = 'block';
            var qrEmpty = document.getElementById('qrEmpty');
            if (qrEmpty) qrEmpty.style.display = 'none';
            document.getElementById('printBtn').style.display = 'inline-flex';
            document.getElementById('downloadBtn').style.display = 'inline-flex';
        } catch (e) {
            showError(e.message);
        } finally {
            btn.disabled = false;
        }
    }

    @if($canRegenerate)
    /* ══════════ Regenerate Code (the card currently on screen) ══════════
     *
     * Acts on the exact table the visible card was generated for — carried in
     * currentCard.table_id from the table-card response — and on no other. The
     * button only does anything once a card has been generated.
     */
    const REGEN_ENDPOINT = "{{ route('admin.qr-generator.regenerate-code') }}";

    function showRegenMsg(message, ok) {
        const el = document.getElementById('regenMsg');

        if (!message) {
            el.style.display = 'none';
            return;
        }

        el.textContent = message;
        el.style.display = 'block';
        el.style.background = ok ? '#E6F4EA' : '#FDECEA';
        el.style.color = ok ? '#1E7A3C' : '#B3261E';
        el.style.border = '1px solid ' + (ok ? '#B7E0C4' : '#F5C2BE');
    }

    async function regenerateCurrentCode() {
        if (!currentCard || !currentCard.table_id) {
            showRegenMsg('Generate a card first, then regenerate its code.', false);
            return;
        }

        /*
         * A deliberate step, because this one is irreversible in the physical
         * world: the moment it succeeds, the code on the acrylic standee at
         * that table is wrong, and somebody has to walk over with a new card.
         * The confirmation names the exact table, and only that one table is
         * ever affected.
         */
        if (!window.confirm(
            'Regenerate the code for ' + currentCard.branch_name + ' - Table ' +
            currentCard.table_number + '?\n\n' +
            'The code currently printed on that table stops working immediately, ' +
            'and you will need to print and place a new card for it.\n\n' +
            'No other table is affected.'
        )) {
            return;
        }

        const btn = document.getElementById('regenCardBtn');
        if (btn) btn.disabled = true;
        showRegenMsg('', true);

        try {
            const res = await fetch(REGEN_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ table_id: currentCard.table_id })
            });

            const data = await res.json().catch(function () { return {}; });

            if (!res.ok) throw new Error(data.message || 'Could not regenerate that code.');

            /*
             * REDRAW THE PRINTABLE CARD AUTOMATICALLY. The QR now encodes the
             * code too (as `k` — see App\Services\QrPayload), so its pixels
             * genuinely change on every rotation, and so does the code printed
             * beneath it. Both are stale the instant this succeeds, so the
             * on-screen card is redrawn from the response's fresh url + code.
             */
            if (String(currentCard.table_id) === String(data.table_id)) {
                /*
                 * Pull EVERY field the card renders from out of the response,
                 * not just the printed code. The QR is drawn from currentCard.url
                 * (RestaurantTable::qrUrl(), now carrying &k=<new code>); leaving
                 * that stale redrew a fresh code under a QR that still encoded the
                 * dead one, so the reprinted card scanned to "QR out of date".
                 */
                currentCard.code = data.code;
                currentCard.url = data.url;
                if (data.branch_code) currentCard.branch_code = data.branch_code;
                if (data.table_number) currentCard.table_number = data.table_number;
                const qrCanvas = await renderQrCanvas(currentCard.url);
                drawCard(currentCard, qrCanvas);
            }

            showRegenMsg(data.message, true);
        } catch (e) {
            showRegenMsg(e.message, false);
        } finally {
            if (btn) btn.disabled = false;
        }
    }
    @endif

    /* ══════════ Occupied tables ══════════ */

    const OCCUPANCY_ENDPOINT = "{{ route('admin.tables.occupancy') }}";
    const CLEAR_ENDPOINT = "{{ route('admin.tables.clear') }}";

    function showTablesMsg(message, ok) {
        const el = document.getElementById('tablesMsg');

        if (!message) {
            el.style.display = 'none';
            return;
        }

        el.textContent = message;
        el.style.display = 'block';
        el.style.background = ok ? '#E6F4EA' : '#FDECEA';
        el.style.color = ok ? '#1E7A3C' : '#B3261E';
        el.style.border = '1px solid ' + (ok ? '#B7E0C4' : '#F5C2BE');
    }

    function sinceLabel(iso) {
        if (!iso) return '—';

        const mins = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000));

        if (mins < 1) return 'just now';
        if (mins < 60) return mins + ' min ago';

        return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm ago';
    }

    async function loadOccupancy() {
        const body = document.getElementById('tablesBody');

        try {
            const res = await fetch(OCCUPANCY_ENDPOINT, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!res.ok) throw new Error('Could not load table occupancy.');

            const data = await res.json();

            if (!data.tables.length) {
                body.innerHTML = '<tr><td colspan="5" style="padding:0.9rem; color:#888;">No tables are occupied right now.</td></tr>';
                return;
            }

            body.innerHTML = data.tables.map(function (t) {
                const order = t.order_number
                    ? t.order_number + ' <span style="color:#888;">(' + t.order_status + ')</span>'
                    : '<span style="color:#888;">browsing, no order yet</span>';

                // Says where the occupancy came from, so a table held by a
                // counter order is not mistaken for a stuck customer session.
                const source = t.staff_opened
                    ? ' <span style="color:#8A6A61; font-size:0.68rem;">· counter</span>'
                    : '';

                return '<tr>' +
                    '<td>' + escapeHtml(t.branch_name || '—') + '</td>' +
                    '<td style="font-weight:700;">' + escapeHtml(t.table_number) + '</td>' +
                    '<td>' + order + source + '</td>' +
                    '<td style="color:#555;">' + sinceLabel(t.since) + '</td>' +
                    '<td class="num">' +
                        '<button class="btn-primary-custom clear-table-btn" style="padding:0.3rem 0.8rem; font-size:0.72rem; background:#C0392B;"' +
                        ' data-branch="' + t.branch_id + '" data-table="' + escapeHtml(t.table_number) + '">Clear</button>' +
                    '</td>' +
                '</tr>';
            }).join('');
        } catch (e) {
            body.innerHTML = '<tr><td colspan="5" style="padding:0.9rem; color:#B3261E;">' + escapeHtml(e.message) + '</td></tr>';
        }
    }

    function escapeHtml(value) {
        const d = document.createElement('div');
        d.textContent = value == null ? '' : String(value);
        return d.innerHTML;
    }

    document.addEventListener('click', async function (event) {
        const btn = event.target.closest('.clear-table-btn');
        if (!btn) return;

        // Freeing a table under a customer who is still mid-meal would be
        // disruptive, so this is a deliberate confirmation, not a stray click.
        if (!window.confirm('Clear Table ' + btn.dataset.table + '? Any in-progress dine-in session on it will be ended.')) {
            return;
        }

        btn.disabled = true;
        showTablesMsg('', true);

        try {
            const res = await fetch(CLEAR_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ branch_id: btn.dataset.branch, table_number: btn.dataset.table })
            });

            const data = await res.json().catch(function () { return {}; });

            if (!res.ok) throw new Error(data.message || 'Could not clear that table.');

            showTablesMsg(data.message, true);
        } catch (e) {
            showTablesMsg(e.message, false);
        } finally {
            btn.disabled = false;
            loadOccupancy();
        }
    });

    /*
     * Auto-refresh. A table that just became occupied (or was just freed by
     * its order finishing) previously only showed up after staff remembered
     * to click Refresh. The manual Refresh button stays for an immediate
     * check without waiting for the next tick.
     *
     * WHY 5 SECONDS, NOT 12
     * ---------------------
     * The 12s poll added in item 41 works — it was re-verified — but 12s is
     * longer than anyone waits before deciding a screen is stale. Staff seat a
     * party, look at the panel, see nothing, and press Refresh; the tick that
     * would have done it for them lands after they have already concluded the
     * list does not update itself, which is exactly the report. Five seconds
     * reads as live.
     *
     * Cost is small and bounded: one indexed query on a table with a handful of
     * live rows, 12 requests a minute per open tab, polling suspended entirely
     * while the tab is hidden. The endpoint carries throttle:120,1 in
     * routes/web.php so it stays cheap even with several counter screens open.
     *
     * Same setInterval + beforeunload teardown pattern already used for the
     * order-status poller on the customer Orders page.
     */
    const OCCUPANCY_POLL_MS = 5000;

    let occupancyPollTimer = null;

    function startOccupancyPoll() {
        if (occupancyPollTimer) return;
        occupancyPollTimer = setInterval(loadOccupancy, OCCUPANCY_POLL_MS);
    }

    function stopOccupancyPoll() {
        if (!occupancyPollTimer) return;
        clearInterval(occupancyPollTimer);
        occupancyPollTimer = null;
    }

    function initOccupancyPoll() {
        loadOccupancy();
        startOccupancyPoll();
    }

    /*
     * This script is pushed to the end of the body, so DOMContentLoaded has
     * usually NOT fired yet — but "usually" is not a guarantee (a slow asset,
     * a browser extension, a cached re-render can all land it late). If the
     * document is already interactive the listener would never fire and the
     * poll would silently never start, so check the state instead.
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initOccupancyPoll);
    } else {
        initOccupancyPoll();
    }

    // A hidden tab does not need to poll, and browsers throttle its timers
    // anyway. Refresh once on the way back so the list is never stale on
    // return.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopOccupancyPoll();
            return;
        }

        loadOccupancy();
        startOccupancyPoll();
    });

    window.addEventListener('beforeunload', stopOccupancyPoll);

    function downloadCard() {
        if (!currentCard) return;

        const canvas = document.getElementById('cardCanvas');
        canvas.toBlob(function (blob) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'table-card-' + currentCard.branch_code + '-table-' +
                currentCard.table_number + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        }, 'image/png');
    }

    function printCard() {
        if (!currentCard) return;

        // Prints an <img> of the same canvas rather than a copy of the DOM.
        // Copying innerHTML handed the print window an EMPTY <canvas>, because
        // canvas pixels do not survive being serialised to HTML.
        const dataUrl = document.getElementById('cardCanvas').toDataURL('image/png');
        const title = 'Table Card - ' + currentCard.branch_code + ' Table ' + currentCard.table_number;

        const win = window.open('', '', 'width=760,height=1000');

        if (!win) {
            showError('Your browser blocked the print window. Allow pop-ups for this site, or use Download PNG.');
            return;
        }

        win.document.write(
            '<!DOCTYPE html><html><head><title>' + title + '</title>' +
            '<style>@page{margin:12mm;}' +
            'html,body{margin:0;padding:0;background:#fff;}' +
            'img{display:block;width:100%;max-width:150mm;margin:0 auto;}' +
            '</style></head><body><img id="card" alt="' + title + '" src="' + dataUrl + '"></body></html>'
        );
        win.document.close();

        const img = win.document.getElementById('card');
        const go = function () { win.focus(); win.print(); };

        if (img.complete) {
            setTimeout(go, 150);
        } else {
            img.onload = function () { setTimeout(go, 150); };
        }
    }
</script>
@endpush
