{{-- Shared behaviour for the "Log out?" confirmation modal (customer More page
     and the admin/staff/supervisor sidebar). Any <form data-logout-form> that
     is submitted is held back and the page's #logoutConfirmModal opens instead.
     "Log out" in the modal submits that same form, untouched. With JS off the
     form simply posts as it always did. Cancel, Esc and a click on the backdrop
     close it; Tab stays inside it. The modal's own classes decide how it looks:
     data-show / data-hide hold the class lists to add on open / remove on open. --}}
<script>
    (function () {
        var modal = document.getElementById('logoutConfirmModal');
        if (!modal) return;

        var cancelBtn = modal.querySelector('[data-logout-cancel]');
        var okBtn = modal.querySelector('[data-logout-confirm]');
        var showCls = (modal.getAttribute('data-show') || 'show').split(' ').filter(Boolean);
        var hideCls = (modal.getAttribute('data-hide') || '').split(' ').filter(Boolean);
        var pending = null;
        var opener = null;

        function isOpen() { return modal.getAttribute('aria-hidden') === 'false'; }

        function open(form, trigger) {
            pending = form;
            opener = trigger || document.activeElement;
            showCls.forEach(function (c) { modal.classList.add(c); });
            hideCls.forEach(function (c) { modal.classList.remove(c); });
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            /* Cancel gets the focus: Enter by reflex must not log anyone out. */
            if (cancelBtn) cancelBtn.focus();
        }

        function close() {
            showCls.forEach(function (c) { modal.classList.remove(c); });
            hideCls.forEach(function (c) { modal.classList.add(c); });
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            pending = null;
            if (opener && typeof opener.focus === 'function') opener.focus();
            opener = null;
        }

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.matches || !form.matches('form[data-logout-form]')) return;
            e.preventDefault();
            open(form, e.submitter);
        });

        if (cancelBtn) cancelBtn.addEventListener('click', close);

        if (okBtn) okBtn.addEventListener('click', function () {
            var form = pending;
            if (!form) return;
            okBtn.disabled = true;
            if (cancelBtn) cancelBtn.disabled = true;
            /* prototype.submit(): skips the submit listener above (no loop). */
            HTMLFormElement.prototype.submit.call(form);
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) close();
        });

        document.addEventListener('keydown', function (e) {
            if (!isOpen()) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
                return;
            }
            if (e.key === 'Tab') {
                var focusable = [cancelBtn, okBtn].filter(function (b) { return b && !b.disabled; });
                if (!focusable.length) return;
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                } else if (!modal.contains(document.activeElement)) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        /* Back/forward cache restore: never come back with the modal stuck open
           or the Log out button disabled. */
        window.addEventListener('pageshow', function () {
            if (okBtn) okBtn.disabled = false;
            if (cancelBtn) cancelBtn.disabled = false;
            if (isOpen()) close();
        });
    })();
</script>
