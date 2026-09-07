{{--
    Show/hide toggle for password inputs.

    WHAT THIS IS, AND WHAT IT IS EMPHATICALLY NOT
    ---------------------------------------------
    This reveals only what the person is typing into the field in front of
    them, by flipping the input's `type` between "password" and "text". It is
    pure front-end.

    It does NOT — and cannot — fetch, request or display a STORED password.
    Stored passwords are bcrypt hashes; there is no endpoint that returns one
    and no way to reverse one. If you are ever asked to add a "show the
    current password" button somewhere, the answer is that it is not possible,
    not that it has not been built yet.

    USAGE
    -----
    Wrap the input in .pw-field and add a sibling button:

        <div class="pw-field">
            <input type="password" id="some-id" ...>
            <button type="button" class="js-pw-toggle" data-target="some-id"
                    aria-label="Show password" aria-pressed="false">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
        </div>

    ACCESSIBILITY
    -------------
    The control is a real <button type="button">, so it is reachable and
    operable by keyboard for free, and type="button" keeps it from submitting
    the form it sits in. aria-pressed reflects the current state and
    aria-label changes with it, so a screen reader announces both what the
    button does and whether the password is currently visible.
--}}

<style>
    .pw-field {
        position: relative;
        display: block;
    }

    /* Room for the button so a long password never slides underneath it. */
    .pw-field > input {
        width: 100%;
        padding-right: 2.5rem;
    }

    .pw-field > .js-pw-toggle {
        position: absolute;
        top: 50%;
        right: 0.5rem;
        transform: translateY(-50%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.75rem;
        height: 1.75rem;
        padding: 0;
        border: none;
        border-radius: 6px;
        background: transparent;
        color: #888;
        cursor: pointer;
        line-height: 1;
    }

    .pw-field > .js-pw-toggle:hover {
        color: #333;
        background: rgba(0, 0, 0, 0.05);
    }

    /* Never remove the focus ring — this is a keyboard-operable control. */
    .pw-field > .js-pw-toggle:focus-visible {
        outline: 2px solid #C0392B;
        outline-offset: 1px;
    }
</style>

<script>
    (function () {
        'use strict';

        /*
         * Delegated from the document so this works for fields that are added
         * or revealed after load — the per-staff "Set Password" rows on the
         * Staff Accounts page are hidden until their row is expanded, and a
         * listener bound at load time would miss them.
         */
        document.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('.js-pw-toggle') : null;

            if (!button) {
                return;
            }

            var input = document.getElementById(button.getAttribute('data-target'));

            if (!input) {
                return; // Nothing to toggle; fail quietly rather than throwing.
            }

            var revealing = input.type === 'password';

            input.type = revealing ? 'text' : 'password';

            button.setAttribute('aria-pressed', revealing ? 'true' : 'false');
            button.setAttribute('aria-label', revealing ? 'Hide password' : 'Show password');
            button.setAttribute('title', revealing ? 'Hide password' : 'Show password');

            var icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', !revealing);
                icon.classList.toggle('bi-eye-slash', revealing);
            }

            /*
             * Keep the caret where it was. Flipping `type` moves it to the end
             * in several browsers, which is maddening mid-word.
             */
            if (typeof input.selectionStart === 'number') {
                var start = input.selectionStart;
                var end = input.selectionEnd;
                try {
                    input.setSelectionRange(start, end);
                } catch (e) {
                    /* Some input types refuse setSelectionRange; not important. */
                }
            }
        });

        /*
         * The "Set Password" expander on the Staff Accounts page. Kept here
         * rather than in that view so the two behaviours that touch the same
         * rows stay together.
         */
        document.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('.js-toggle-pw-row') : null;

            if (!button) {
                return;
            }

            var row = document.getElementById(button.getAttribute('data-target'));

            if (!row) {
                return;
            }

            var opening = row.hidden;
            row.hidden = !opening;
            button.setAttribute('aria-expanded', opening ? 'true' : 'false');

            if (opening) {
                var first = row.querySelector('input[type="password"], input[type="text"]');
                if (first) {
                    first.focus();
                }
            }
        });
    })();
</script>
