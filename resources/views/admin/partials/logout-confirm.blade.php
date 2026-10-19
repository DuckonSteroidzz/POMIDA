{{-- "Log out?" confirmation for the admin / staff / supervisor sidebar. Styled
     like the other pchy-modal dialogs; behaviour lives in the shared script. --}}
<style>
    .pc-logout-overlay { display: none; position: fixed; inset: 0; background: rgba(74,59,54,.5); z-index: 3000; align-items: center; justify-content: center; padding: 1rem; }
    .pc-logout-overlay.show { display: flex; }
    .pc-logout-box { background: #fff; border-radius: 18px; padding: 1.3rem; width: 100%; max-width: 380px; box-shadow: 0 20px 50px -20px rgba(139,26,26,.5); }
    .pc-logout-title { font-family: 'Poppins', sans-serif; font-size: 1.05rem; font-weight: 700; color: #8B1A1A; margin: 0 0 .35rem; }
    .pc-logout-text { font-size: .9rem; color: #7a6a64; margin: 0 0 1.1rem; }
    .pc-logout-foot { display: flex; gap: .6rem; }
    .pc-logout-foot button { flex: 1; border-radius: 8px; padding: .65rem 1rem; font-size: .85rem; font-weight: 700; cursor: pointer; font-family: 'Poppins', sans-serif; }
    .pc-logout-cancel { background: #fff; color: #C0392B; border: 1.5px solid #F6B49B; }
    .pc-logout-cancel:hover { background: #fff4ec; }
    .pc-logout-ok { background: #C0392B; color: #fff; border: 1.5px solid #C0392B; }
    .pc-logout-ok:hover { background: #8B1A1A; border-color: #8B1A1A; }
    .pc-logout-foot button:focus-visible { outline: 3px solid rgba(244,132,95,.55); outline-offset: 2px; }
</style>
<div id="logoutConfirmModal" class="pc-logout-overlay"
     role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" aria-hidden="true"
     data-show="show" data-hide="">
    <div class="pc-logout-box">
        <h2 id="logoutConfirmTitle" class="pc-logout-title">Log out?</h2>
        <p class="pc-logout-text">Are you sure you want to log out?</p>
        <div class="pc-logout-foot">
            <button type="button" class="pc-logout-cancel" data-logout-cancel>Cancel</button>
            <button type="button" class="pc-logout-ok" data-logout-confirm>Log out</button>
        </div>
    </div>
</div>
@include('partials.logout-confirm-script')
