{{-- "Log out?" confirmation for the customer More page. Same look as the
     Need Assistance modal beside it; behaviour lives in the shared script. --}}
<div id="logoutConfirmModal"
     class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/50 p-4"
     role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" aria-hidden="true"
     data-show="flex" data-hide="hidden">
    <div class="w-full max-w-sm rounded-3xl bg-white p-5 shadow-2xl sm:p-6">
        <h2 id="logoutConfirmTitle" class="font-display text-xl font-black text-peach-deep">Log out?</h2>
        <p class="mt-1 text-sm text-peach-deep/55">Are you sure you want to log out?</p>

        <div class="mt-5 grid grid-cols-2 gap-3">
            <button type="button" data-logout-cancel
                    class="inline-flex items-center justify-center rounded-full border border-peach-soft bg-white px-5 py-3 text-sm font-bold text-peach-deep hover:bg-peach-soft/40">
                Cancel
            </button>
            <button type="button" data-logout-confirm
                    class="inline-flex items-center justify-center gap-2 rounded-full bg-peach-red px-5 py-3 text-sm font-bold text-white hover:bg-peach-deep">
                <i class="bi bi-box-arrow-right"></i>
                Log out
            </button>
        </div>
    </div>
</div>
@include('partials.logout-confirm-script')
