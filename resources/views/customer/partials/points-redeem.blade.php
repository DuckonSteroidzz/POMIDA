{{--
    "Redeem your points" (QA, Nov 5 2026), on Spin & Win and on Vouchers & Rewards.

    Spinning only earns points. A prize is no longer awarded by the spin; the
    customer trades points for it here, whenever they like, through
    customer.points.redeem (App\Services\PointsRedeem). Each row's status comes
    from App\Support\WheelPrizes, the same rule the server re-checks on Redeem.

    Expects:
      $redeemPrizes  WheelPrizes::forVisitor() for this visitor
      $redeemReload  reload the page after a redeem (so a list of held vouchers
                     on the page shows the new one); the Spin & Win page of a
                     signed-in customer has none, so it updates in place
    The page marks its points figure(s) with data-points-balance; this keeps
    them in step. Plain CSS, so nothing here depends on a Tailwind rebuild.
--}}
@once
<style>
    .redeem-title {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.1563rem;
        font-weight: 800;
        color: #8B1A1A;
        margin: 0 0 .35rem;
    }
    .redeem-note {
        margin: 0 0 .85rem;
        font-size: 0.875rem;
        line-height: 1.4;
        color: #5b4740;
    }
    .redeem-list { display: flex; flex-direction: column; gap: .6rem; }
    .redeem-item {
        display: flex;
        align-items: flex-start;
        gap: .65rem;
        background: #FFFBF7;
        border: 1px solid #FDE8DE;
        border-radius: 12px;
        padding: .7rem .8rem;
    }
    .redeem-item.is-ready { border-color: #9FD3B6; background: #F3FAF6; }
    .redeem-cost {
        flex-shrink: 0;
        background: linear-gradient(135deg,#2E7D5B,#1f5c40);
        color: #fff;
        font-size: 0.8125rem;
        font-weight: 800;
        padding: .3rem .55rem;
        border-radius: 999px;
        white-space: nowrap;
    }
    .redeem-body { min-width: 0; flex: 1; font-size: 0.875rem; line-height: 1.35; color: #5b4740; }
    .redeem-name { margin: 0; }
    .redeem-name strong { color: #8B1A1A; font-size: 0.9375rem; }
    .redeem-code { color: #8A6A61; font-weight: 600; overflow-wrap: anywhere; }
    .redeem-desc { margin: .15rem 0 0; color: #8A6A61; overflow-wrap: anywhere; }
    .redeem-status {
        display: inline-block;
        margin-top: .4rem;
        padding: .15rem .55rem;
        border-radius: 999px;
        background: #F5F0EC;
        color: #5b4740;
        font-size: 0.8125rem;
        font-weight: 700;
    }
    .redeem-status.is-need_points { background: #FDE8DE; color: #8B1A1A; }
    .redeem-status[hidden], .redeem-btn[hidden] { display: none !important; }
    .redeem-btn {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        min-height: 44px;
        margin-top: .45rem;
        padding: .45rem 1.05rem;
        border: 0;
        border-radius: 999px;
        background: linear-gradient(135deg,#2E7D5B,#1f5c40);
        color: #fff;
        font-size: 0.875rem;
        font-weight: 800;
        cursor: pointer;
    }
    .redeem-btn:hover { filter: brightness(1.06); }
    .redeem-btn:disabled { background: #ccc; cursor: not-allowed; }
    .redeem-empty { margin: 0; font-size: 0.875rem; color: #8A6A61; text-align: center; padding: .25rem 0; }

    .redeem-modal {
        position: fixed;
        inset: 0;
        z-index: 9998;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background: rgba(74,59,54,.55);
    }
    .redeem-modal.is-open { display: flex; }
    .redeem-dialog {
        width: 100%;
        max-width: 380px;
        max-height: calc(100vh - 2rem);
        overflow-y: auto;
        background: #fff;
        border-radius: 18px;
        padding: 1.4rem 1.25rem 1.2rem;
        text-align: center;
        box-shadow: 0 20px 50px -20px rgba(139,26,26,.5);
    }
    .redeem-dialog-title {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.25rem;
        font-weight: 900;
        color: #8B1A1A;
        margin: 0 0 .4rem;
    }
    .redeem-dialog-text { margin: 0 0 1rem; font-size: 0.875rem; line-height: 1.45; color: #8A6A61; }
    .redeem-actions { display: flex; flex-direction: column; gap: .5rem; }
    .redeem-primary, .redeem-secondary {
        min-height: 44px;
        width: 100%;
        border-radius: 999px;
        font-size: 0.9375rem;
        font-weight: 800;
        cursor: pointer;
    }
    .redeem-primary { border: 0; background: linear-gradient(135deg,#F4845F,#C0392B); color: #fff; }
    .redeem-primary:disabled { background: #ccc; cursor: not-allowed; }
    .redeem-secondary { border: 1px solid #EAD8D2; background: #fff; color: #8B1A1A; }
    .redeem-keep {
        margin: 0 0 .6rem;
        font-size: 0.8125rem;
        font-weight: 900;
        letter-spacing: .09em;
        text-transform: uppercase;
        color: #C0392B;
    }
    .redeem-code-box {
        margin: 0 0 .6rem;
        padding: .6rem .5rem;
        border: 2px dashed #F4845F;
        border-radius: 10px;
        background: #FFF7F3;
        color: #C0392B;
        font-size: 1.5rem;
        font-weight: 900;
        letter-spacing: 2px;
        word-break: break-all;
    }
    .redeem-points-line { margin: 0 0 1rem; font-size: 0.875rem; font-weight: 700; color: #8B1A1A; line-height: 1.45; }
</style>
@endonce

<h2 class="redeem-title" id="redeemTitle">🎁 Redeem your points</h2>
<p class="redeem-note" data-testid="points-rule">Spin to earn points. Redeem your points for a voucher anytime.</p>

<div class="redeem-list" id="redeemList" data-testid="points-redeem">
    @forelse($redeemPrizes as $prize)
    @php
        $rv = $prize['voucher'];
        $ready = $prize['status'] === \App\Support\WheelPrizes::REDEEMABLE;
    @endphp
    <div class="redeem-item {{ $ready ? 'is-ready' : '' }}" data-prize-id="{{ $rv->id }}"
         data-prize-cost="{{ (int) $rv->points_required }}"
         data-prize-name="{{ \App\Support\WheelPrizes::rewardName($rv) }}">
        <span class="redeem-cost">{{ (int) $rv->points_required }} pts</span>
        <div class="redeem-body">
            <p class="redeem-name"><strong>{{ \App\Support\WheelPrizes::rewardName($rv) }}</strong> <span class="redeem-code">· {{ $rv->code }}</span></p>
            @if($rv->description)
                <p class="redeem-desc">{{ $rv->description }}</p>
            @endif
            <span class="redeem-status is-{{ $prize['status'] }}" data-prize-status @if($ready) hidden @endif>{{ $prize['label'] }}</span>
            <button type="button" class="redeem-btn" data-redeem-id="{{ $rv->id }}" @unless($ready) hidden @endunless>
                <i class="bi bi-gift"></i> Redeem for {{ (int) $rv->points_required }} pts
            </button>
        </div>
    </div>
    @empty
    <p class="redeem-empty">No vouchers to redeem right now. Keep spinning to build up your points!</p>
    @endforelse
</div>

{{-- Step 1: confirm. The server is the only thing that decides; this only
     makes sure a tap was meant. --}}
<div class="redeem-modal" id="redeemConfirm" role="dialog" aria-modal="true" aria-labelledby="redeemConfirmTitle">
    <div class="redeem-dialog">
        <p class="redeem-dialog-title" id="redeemConfirmTitle">Redeem this voucher?</p>
        <p class="redeem-dialog-text" id="redeemConfirmText"></p>
        <div class="redeem-actions">
            <button type="button" class="redeem-primary" id="redeemConfirmYes">Yes, redeem</button>
            <button type="button" class="redeem-secondary" id="redeemConfirmNo">Cancel</button>
        </div>
    </div>
</div>

{{-- Step 2: the code (or why the server said no). A guest has no account to
     keep the code in, so for them it is the only copy and says so. --}}
<div class="redeem-modal" id="redeemResult" role="dialog" aria-modal="true" aria-labelledby="redeemResultTitle">
    <div class="redeem-dialog">
        <p class="redeem-dialog-title" id="redeemResultTitle"></p>
        <p class="redeem-dialog-text" id="redeemResultDesc"></p>
        <p class="redeem-keep" id="redeemResultKeep" hidden></p>
        <div class="redeem-code-box" id="redeemResultCode" data-testid="redeem-code" hidden></div>
        <p class="redeem-dialog-text" id="redeemResultNote"></p>
        <p class="redeem-points-line" id="redeemResultPoints"></p>
        <div class="redeem-actions">
            <button type="button" class="redeem-primary" id="redeemResultCopy" hidden><i class="bi bi-clipboard"></i> Copy Code</button>
            <button type="button" class="redeem-secondary" id="redeemResultDone">Done</button>
        </div>
    </div>
</div>

<script>
    (function () {
        var list = document.getElementById('redeemList');
        if (!list) return;

        var url = @json(route('customer.points.redeem'));
        var token = @json(csrf_token());
        var reloadAfter = @json((bool) ($redeemReload ?? false));
        var signedIn = @json(\Illuminate\Support\Facades\Auth::guard('customer')->check());

        var confirmBox = document.getElementById('redeemConfirm');
        var yes = document.getElementById('redeemConfirmYes');
        var no = document.getElementById('redeemConfirmNo');
        var result = document.getElementById('redeemResult');
        var busy = false;
        var pendingId = null;
        var redeemed = false;

        function each(nodes, fn) { Array.prototype.forEach.call(nodes, fn); }

        function setBalance(total) {
            if (typeof total === 'undefined' || total === null) return;
            each(document.querySelectorAll('[data-points-balance]'), function (el) { el.textContent = total; });
        }

        /* Repaint every row from the server's statuses ({id, status, label}). */
        function render(prizes) {
            (prizes || []).forEach(function (p) {
                var row = list.querySelector('[data-prize-id="' + p.id + '"]');
                if (!row) return;
                var ready = p.status === 'redeemable';
                var chip = row.querySelector('[data-prize-status]');
                var btn = row.querySelector('[data-redeem-id]');
                row.classList.toggle('is-ready', ready);
                if (chip) {
                    chip.className = 'redeem-status is-' + p.status;
                    chip.textContent = p.label;
                    chip.hidden = ready;
                }
                if (btn) {
                    btn.hidden = !ready;
                    btn.disabled = false;
                }
            });
        }

        window.PointsRedeem = { render: render, setBalance: setBalance };

        function open(box) { box.classList.add('is-open'); }
        function close(box) { box.classList.remove('is-open'); }

        list.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-redeem-id]');
            if (!btn || busy) return;

            var row = btn.closest('[data-prize-id]');
            pendingId = btn.getAttribute('data-redeem-id');
            document.getElementById('redeemConfirmText').textContent =
                'Use ' + row.getAttribute('data-prize-cost') + ' pts for ' + row.getAttribute('data-prize-name') + '?';
            yes.disabled = false;
            no.disabled = false;
            yes.textContent = 'Yes, redeem';
            open(confirmBox);
            yes.focus();
        });

        no.addEventListener('click', function () { if (!busy) close(confirmBox); });
        confirmBox.addEventListener('click', function (e) { if (e.target === confirmBox && !busy) close(confirmBox); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && confirmBox.classList.contains('is-open') && !busy) close(confirmBox);
        });

        yes.addEventListener('click', function () {
            /* One request per press, however fast the taps come. The server
               would refuse a second anyway (row lock + once per customer). */
            if (busy || !pendingId) return;
            busy = true;
            yes.disabled = true;
            no.disabled = true;
            yes.textContent = 'Redeeming…';
            each(list.querySelectorAll('[data-redeem-id]'), function (b) { b.disabled = true; });
            if (window.primeCelebrationAudio) window.primeCelebrationAudio();

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ voucher_id: Number(pendingId) })
            })
                .then(function (r) {
                    return r.json().then(function (body) {
                        return { status: r.status, body: body };
                    }, function () {
                        return { status: r.status, body: null };
                    });
                })
                .then(function (res) {
                    finish();
                    var body = res.body || {};
                    if (body.prizes) render(body.prizes);
                    setBalance(body.total_points);

                    if (res.status === 200 && body.success && body.voucher) {
                        redeemed = true;
                        showCode(body.voucher, body.total_points);
                        return;
                    }

                    showProblem(res.status === 419
                        ? 'Your session expired. Refresh the page and try again. Your points were not used.'
                        : (body.message || 'Something went wrong. Your points were not used.'));
                })
                .catch(function () {
                    finish();
                    showProblem('Could not reach the server. Refresh the page to see your points and vouchers.');
                });
        });

        function finish() {
            busy = false;
            pendingId = null;
            close(confirmBox);
            each(list.querySelectorAll('[data-redeem-id]'), function (b) { b.disabled = false; });
        }

        function showCode(v, total) {
            document.getElementById('redeemResultTitle').textContent = '🎉 Voucher redeemed!';
            document.getElementById('redeemResultDesc').textContent = v.name + (v.description ? ' · ' + v.description : '');
            var keep = document.getElementById('redeemResultKeep');
            keep.textContent = signedIn ? 'Your 1-time code' : 'Save this code now';
            keep.hidden = false;
            var code = document.getElementById('redeemResultCode');
            code.textContent = v.claim_code || v.code;
            code.hidden = false;
            document.getElementById('redeemResultNote').textContent = signedIn
                ? 'Saved in Vouchers & Rewards. Type this 1-time code into the voucher box at checkout. It works once.'
                : 'You have no account, so this code is the only way to use this voucher. Type it into the voucher box in your cart. It works once.';
            document.getElementById('redeemResultPoints').textContent =
                v.points_spent + ' points used. You now have ' + total + ' points.';
            document.getElementById('redeemResultCopy').hidden = false;
            open(result);
            if (window.celebrateWin) window.celebrateWin();
        }

        function showProblem(message) {
            document.getElementById('redeemResultTitle').textContent = "Couldn't redeem";
            document.getElementById('redeemResultDesc').textContent = message;
            document.getElementById('redeemResultKeep').hidden = true;
            document.getElementById('redeemResultCode').hidden = true;
            document.getElementById('redeemResultNote').textContent = '';
            document.getElementById('redeemResultPoints').textContent = '';
            document.getElementById('redeemResultCopy').hidden = true;
            open(result);
        }

        document.getElementById('redeemResultDone').addEventListener('click', function () {
            close(result);
            if (redeemed && reloadAfter) window.location.reload();
        });

        document.getElementById('redeemResultCopy').addEventListener('click', function () {
            var btn = this;
            var text = document.getElementById('redeemResultCode').textContent;
            var done = function () {
                btn.innerHTML = '✓ Copied!';
                setTimeout(function () { btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy Code'; }, 1800);
            };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(done, done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (err) {}
                document.body.removeChild(ta);
                done();
            }
        });
    })();
</script>
