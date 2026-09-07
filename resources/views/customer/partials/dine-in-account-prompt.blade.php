{{--
    Shared "Welcome to Dine In" account chooser, plus the hidden form it
    submits.

    WHY THIS IS A PARTIAL
    ----------------------
    A customer can now submit a table code from two pages — the landing page
    (/welcome) and the dedicated Dine In page (/customer/dineinqr) — since the
    in-browser camera scanner that used to be the only thing on the second page
    is gone. Both submissions must trigger the IDENTICAL modal and post to the
    IDENTICAL endpoint (customer.qr.process), or the two pages would drift:
    one might quietly ask fewer questions than the other, or post to a
    different action. Before this was factored out there was exactly one copy,
    living only on dineinqr.blade.php; welcome.blade.php had no code entry at
    all. A second hand-written copy would only be one edit away from diverging
    from the first.

    WHAT THIS DOES NOT DO
    ----------------------
    It does not touch table validation. Submitting the form posts table_code to
    AuthController::processQr(), which resolves it through the permanent code
    (App\Services\TableEntry) exactly as before — nothing here changes that,
    decides it, or duplicates it.

    HOST PAGE CONTRACT
    -------------------
    The including page defines the CSS custom properties every screen in this
    app already sets on :root (--deep-red, --terracotta, --peach, --light-peach,
    --cream, --muted) and calls, on submitting its own code field:

        window.dineInPrompt.open(code);

    which stores the code on the hidden form and shows the modal. Clicking one
    of the three buttons submits the form with `next` set accordingly — the
    server decides whether the code was valid at all; a bad code redirects the
    customer straight back to whichever page they submitted from (see
    AuthController::processQr()'s `back()` calls), so no wrong assumption is
    baked in here about what "the code is good" means.
--}}
<style>
    .account-prompt {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.25rem;
        background: rgba(45, 20, 16, 0.58);
        z-index: 50;
    }

    .account-prompt.show {
        display: flex;
    }

    .account-prompt-card {
        width: min(100%, 430px);
        padding: 2rem;
        border-radius: 1.5rem;
        background: var(--cream);
        box-shadow: 0 25px 70px rgba(45, 20, 16, 0.35);
        text-align: center;
    }

    .account-prompt-card h2 {
        color: var(--deep-red);
        font-size: 1.65rem;
        margin-bottom: 0.65rem;
    }

    .account-prompt-card p {
        color: var(--muted);
        line-height: 1.6;
        margin-bottom: 1.25rem;
    }

    .account-prompt-actions {
        display: grid;
        gap: 0.7rem;
    }

    .account-prompt-actions button {
        min-height: 48px;
        border: 0;
        border-radius: 999px;
        padding: 0.75rem 1.2rem;
        font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-size: 0.95rem;
        font-weight: 700;
        cursor: pointer;
    }

    .account-prompt-primary {
        background: var(--deep-red);
        color: #fff;
    }

    .account-prompt-primary:hover {
        background: var(--terracotta);
    }

    .account-prompt-secondary {
        background: var(--light-peach);
        color: var(--deep-red);
    }

    .account-prompt-secondary:hover {
        background: #f8d3c5;
    }

    .account-prompt-guest {
        background: transparent;
        color: var(--muted);
        border: 1px solid rgba(139, 26, 26, 0.15) !important;
    }

    .account-prompt-guest:hover {
        color: var(--deep-red);
        background: rgba(253, 232, 222, 0.45);
    }
</style>

{{-- Dine-In account choice, shown once a table code has been typed. --}}
<div
    id="accountPrompt"
    class="account-prompt"
    role="dialog"
    aria-modal="true"
    aria-labelledby="accountPromptTitle"
>
    <div class="account-prompt-card">
        <h2 id="accountPromptTitle">Welcome to Dine In</h2>
        <p>
            Would you like to log in or create an account?
            An account lets you access features such as your order history
            while keeping this order connected to your Dine-In table.
        </p>

        <div class="account-prompt-actions">
            <button type="button" class="account-prompt-primary" data-next="login">
                Log In
            </button>

            <button type="button" class="account-prompt-secondary" data-next="register">
                Sign Up
            </button>

            <button type="button" class="account-prompt-guest" data-next="guest">
                Continue as Guest
            </button>
        </div>
    </div>
</div>

{{-- Posts straight to the same table-entry door the rest of the app already
     validates through — see App\Services\TableEntry and
     AuthController::processQr(). --}}
<form
    id="qrForm"
    method="POST"
    action="{{ route('customer.qr.process') }}"
    style="display: none;"
>
    @csrf

    <input type="hidden" id="tableCodeField" name="table_code" value="{{ old('table_code') }}">
    <input type="hidden" id="qrNext" name="next" value="guest">
</form>

<script>
    (function () {
        var accountPrompt = document.getElementById('accountPrompt');
        var qrForm = document.getElementById('qrForm');
        var tableCodeField = document.getElementById('tableCodeField');
        var qrNext = document.getElementById('qrNext');

        document.querySelectorAll('[data-next]').forEach(function (button) {
            button.addEventListener('click', function () {
                qrNext.value = button.dataset.next || 'guest';
                accountPrompt.classList.remove('show');
                qrForm.submit();
            });
        });

        function closeOnBackdrop(event) {
            if (event.target === accountPrompt) {
                accountPrompt.classList.remove('show');
            }
        }

        accountPrompt.addEventListener('click', closeOnBackdrop);

        window.dineInPrompt = {
            /** Store the code the customer typed and show the chooser. */
            open: function (code) {
                tableCodeField.value = code;
                accountPrompt.classList.add('show');
            }
        };

        {{-- The server bounced a submission back with an error: the code the
             customer typed was wrong, so there is nothing to confirm and the
             modal must not reappear on its own. Each host page is responsible
             for showing session('error') next to its own input. --}}
    })();
</script>
