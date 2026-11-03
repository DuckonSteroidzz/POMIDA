{{--
    Live password checklist for the forgot-password flow's last step (Defect
    Report 6). Each line ticks itself as the person types, so nobody has to find
    the rules by submitting and failing.

    Only a guide: App\Support\PasswordPolicy on the server still decides, and
    the patterns below match the ones Laravel's Password rule uses (\p{Ll}, \p{Lu},
    \pN, and \p{Z}|\p{S}|\p{P} for a symbol). A browser that cannot run them
    simply leaves the lines as a plain list of the rules.

    Uses the colour variables both auth shells define. Expects #password and
    #password_confirmation on the page.
--}}
<style>
    .pw-checklist { list-style: none; margin: 0.65rem 0 0; padding: 0.7rem 0.85rem; border-radius: 0.8rem; background: rgba(244, 132, 95, 0.08); font-size: 0.875rem; line-height: 1.35; color: var(--muted); }
    .pw-checklist li { display: flex; align-items: flex-start; gap: 0.5rem; padding: 0.16rem 0; }
    .pw-checklist i { flex: 0 0 auto; }
    .pw-checklist li.is-met { color: #276749; font-weight: 600; }
    .pw-checklist-note { margin: 0.4rem 0 0; font-size: 0.8125rem; line-height: 1.35; color: var(--muted); }
</style>

<ul class="pw-checklist" id="pwChecklist" aria-label="Password requirements">
    <li data-rule="length"><i class="bi bi-circle" aria-hidden="true"></i><span>{{ \App\Support\PasswordPolicy::MIN_LENGTH }} to {{ \App\Support\PasswordPolicy::MAX_LENGTH }} characters</span></li>
    <li data-rule="upper"><i class="bi bi-circle" aria-hidden="true"></i><span>An uppercase letter</span></li>
    <li data-rule="lower"><i class="bi bi-circle" aria-hidden="true"></i><span>A lowercase letter</span></li>
    <li data-rule="number"><i class="bi bi-circle" aria-hidden="true"></i><span>A number</span></li>
    <li data-rule="symbol"><i class="bi bi-circle" aria-hidden="true"></i><span>A special character, like ! or #</span></li>
    <li data-rule="match"><i class="bi bi-circle" aria-hidden="true"></i><span>Both passwords match</span></li>
</ul>
{{-- Not a tick-off line: this page never knows (or shows) the current password,
     so the server alone decides. It says so up front instead. --}}
@unless($errors->has('password'))
<p class="pw-checklist-note">It must also be different from your current password.</p>
@endunless

@push('scripts')
<script>
    (function () {
        var field = document.getElementById('password');
        var confirmField = document.getElementById('password_confirmation');
        var list = document.getElementById('pwChecklist');

        if (!field || !confirmField || !list) {
            return;
        }

        var min = {{ \App\Support\PasswordPolicy::MIN_LENGTH }};
        var max = {{ \App\Support\PasswordPolicy::MAX_LENGTH }};
        var rules;

        try {
            rules = {
                upper: new RegExp('\\p{Lu}', 'u'),
                lower: new RegExp('\\p{Ll}', 'u'),
                number: new RegExp('\\p{N}', 'u'),
                symbol: new RegExp('\\p{Z}|\\p{S}|\\p{P}', 'u')
            };
        } catch (e) {
            return;
        }

        function mark(rule, met) {
            var row = list.querySelector('[data-rule="' + rule + '"]');
            var icon = row.querySelector('i');

            row.classList.toggle('is-met', met);
            icon.classList.toggle('bi-check-circle-fill', met);
            icon.classList.toggle('bi-circle', !met);
        }

        function update() {
            var value = field.value;
            var length = Array.from(value).length;

            mark('length', length >= min && length <= max);
            Object.keys(rules).forEach(function (rule) {
                mark(rule, rules[rule].test(value));
            });
            mark('match', value !== '' && value === confirmField.value);
        }

        field.addEventListener('input', update);
        confirmField.addEventListener('input', update);
        update();
    })();
</script>
@endpush
