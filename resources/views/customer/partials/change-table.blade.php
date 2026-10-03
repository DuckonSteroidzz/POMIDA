{{--
    Dine-In table changes on the menu and the cart. Pass $tableChangeReturnTo =
    'cart' on the cart page so every answer lands back there; anything else
    returns to the menu.

    Renders nothing unless this session has a Dine-In seat
    (App\Services\TableChange::currentSeat()), so a Pick-Up page is unchanged.

    The customer no longer changes table by typing a code: the "Change table"
    trigger and its code dialog were removed when staff got "Move table" on the
    Occupied Tables panel. What stays:

      #tableChangeConfirm  "Move to Table X?" / "Table X is already in use.
                           Join it?" — the phone-camera QR door still stages a
                           move for the customer's tap (TableChange::pending()).
      #tableChangeNotice   one message, then OK: why a table change was refused
                           (session('table_change_error') — e.g. the QR door
                           with an order still open, or a confirm that expired),
                           or that staff moved this phone to another table
                           (FollowStaffTableMove::NOTICE_KEY, shown once).

    Inline styles on purpose, like partials/idle-timeout: nothing here needs a
    Tailwind rebuild. Listeners rather than inline onclick (see the form-id
    shadowing trap on the cart page).
--}}
@php
    $tableChangeSeat = \App\Services\TableChange::currentSeat();
    $tableChangePending = $tableChangeSeat ? \App\Services\TableChange::pending() : null;
    $tableChangeError = session('table_change_error');
    $tableChangeReturn = ($tableChangeReturnTo ?? 'menu') === 'cart' ? 'cart' : 'menu';
    $tableChangeCartQty = (int) collect(session('cart', []))->sum('quantity');
    $tableMovedByStaff = $tableChangeSeat ? session()->pull(\App\Http\Middleware\FollowStaffTableMove::NOTICE_KEY) : null;
@endphp
@if($tableChangeSeat)
@if($tableChangeError || $tableMovedByStaff)
<div id="tableChangeNotice" role="alertdialog" aria-modal="true" aria-labelledby="tableChangeNoticeTitle"
     style="position:fixed;inset:0;z-index:10001;background:rgba(59,35,32,0.55);display:flex;align-items:center;justify-content:center;padding:1rem;">
    <div style="width:100%;max-width:340px;background:#FFFDF9;border:1px solid #F2DDD4;border-radius:16px;padding:22px 20px 18px;text-align:center;box-shadow:0 18px 45px rgba(59,35,32,0.22);">
        <div style="width:48px;height:48px;margin:0 auto 12px;border-radius:50%;display:grid;place-items:center;background:#FDE8DE;font-size:22px;color:#EF8585;">
            <i class="bi {{ $tableChangeError ? 'bi-info-circle' : 'bi-arrow-left-right' }}"></i>
        </div>
        <h3 id="tableChangeNoticeTitle" style="margin:0 0 8px;color:#3b2320;font-family:'Fraunces',Georgia,serif;font-size:19px;line-height:1.25;font-weight:700;">
            {{ $tableChangeError ? 'Table not changed' : "You're now at Table " . $tableChangeSeat['table_number'] }}
        </h3>
        <p id="tableChangeError" style="margin:0 auto;max-width:290px;color:#8A6A61;font-size:12px;line-height:1.55;">
            @if($tableChangeError)
                {{ $tableChangeError }}
            @else
                Our staff moved your party to Table {{ $tableChangeSeat['table_number'] }}.
                @if($tableChangeCartQty > 0)
                    Your cart is still here, and anything you order will be for Table {{ $tableChangeSeat['table_number'] }}.
                @else
                    Anything you order will be for Table {{ $tableChangeSeat['table_number'] }}.
                @endif
            @endif
        </p>
        <div style="display:flex;justify-content:center;margin-top:16px;">
            <button type="button" data-table-change-close
                    style="height:38px;padding:0 24px;border-radius:999px;background:#EF8585;color:#fff;border:1px solid #EF8585;font-size:12px;font-weight:700;cursor:pointer;">
                OK
            </button>
        </div>
    </div>
</div>
@endif

@if($tableChangePending)
@php
    $tableChangeTo = $tableChangePending['table_number'];
    $tableChangeFrom = $tableChangeSeat['table_number'];
@endphp
<div id="tableChangeConfirm" role="alertdialog" aria-modal="true" aria-labelledby="tableChangeConfirmTitle"
     style="position:fixed;inset:0;z-index:10001;background:rgba(59,35,32,0.55);display:flex;align-items:center;justify-content:center;padding:1rem;">
    <div style="width:100%;max-width:340px;background:#FFFDF9;border:1px solid #F2DDD4;border-radius:16px;padding:22px 20px 18px;text-align:center;box-shadow:0 18px 45px rgba(59,35,32,0.22);">
        <div style="width:48px;height:48px;margin:0 auto 12px;border-radius:50%;display:grid;place-items:center;background:#FDE8DE;font-size:22px;color:#EF8585;">
            <i class="bi {{ $tableChangePending['occupied'] ? 'bi-people' : 'bi-arrow-left-right' }}"></i>
        </div>
        <h3 id="tableChangeConfirmTitle" style="margin:0 0 8px;color:#3b2320;font-family:'Fraunces',Georgia,serif;font-size:19px;line-height:1.25;font-weight:700;">
            @if($tableChangePending['occupied'])
                Table {{ $tableChangeTo }} is already in use. Join it?
            @else
                Move to Table {{ $tableChangeTo }}?
            @endif
        </h3>
        <p style="margin:0 auto;max-width:290px;color:#8A6A61;font-size:12px;line-height:1.55;">
            @if($tableChangePending['occupied'])
                You'll share Table {{ $tableChangeTo }}'s session with the people already seated there.
            @else
                You'll leave Table {{ $tableChangeFrom }} and sit at Table {{ $tableChangeTo }}.
            @endif
            @if($tableChangeCartQty > 0)
                Your cart ({{ $tableChangeCartQty }} {{ $tableChangeCartQty === 1 ? 'item' : 'items' }}) stays on this phone, and anything you order will be for Table {{ $tableChangeTo }}.
            @else
                Your cart is empty. Anything you order will be for Table {{ $tableChangeTo }}.
            @endif
        </p>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:16px;flex-wrap:wrap;">
            <form method="POST" action="{{ route('customer.table-change.cancel') }}" style="margin:0;">
                @csrf
                <input type="hidden" name="return_to" value="{{ $tableChangeReturn }}">
                <button type="submit"
                        style="height:38px;padding:0 18px;border-radius:999px;background:#fff;color:#8A6A61;border:1px solid #F2DDD4;font-size:12px;font-weight:700;cursor:pointer;">
                    Stay at Table {{ $tableChangeFrom }}
                </button>
            </form>
            <form method="POST" action="{{ route('customer.table-change.confirm') }}" style="margin:0;">
                @csrf
                <input type="hidden" name="return_to" value="{{ $tableChangeReturn }}">
                <button type="submit"
                        style="height:38px;padding:0 20px;border-radius:999px;background:#EF8585;color:#fff;border:1px solid #EF8585;font-size:12px;font-weight:700;cursor:pointer;">
                    {{ $tableChangePending['occupied'] ? 'Join' : 'Move to' }} Table {{ $tableChangeTo }}
                </button>
            </form>
        </div>
    </div>
</div>
@endif

<script>
(function () {
    'use strict';

    var notice = document.getElementById('tableChangeNotice');

    if (!notice) { return; }

    function close() {
        notice.remove();
    }

    notice.querySelectorAll('[data-table-change-close]').forEach(function (el) {
        el.addEventListener('click', close);
    });

    notice.addEventListener('click', function (event) {
        if (event.target === notice) { close(); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && document.body.contains(notice)) { close(); }
    });
})();
</script>
@endif
