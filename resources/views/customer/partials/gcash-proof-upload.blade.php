{{--
    Attach your payment screenshot (optional). See App\Services\GcashProof.

    Choosing a file saves it straight away, before or after I Have Paid, so there
    is no second button to forget. The forms still work as plain forms: the
    <noscript> button is the only part that needs no script below.
--}}
@php
    $gcashProofUrl = $order->gcashProofUrl();
@endphp

<div id="gcashProof" class="mt-5 border-t border-peach-soft pt-5" data-testid="gcash-proof">

    <h3 class="text-sm font-bold text-peach-deep">
        Attach your payment screenshot
    </h3>

    <p class="mt-1 text-xs leading-relaxed text-peach-deep/55">
        Optional, but attaching it helps staff verify your payment faster.
    </p>

    <form
        id="gcashProofForm"
        action="{{ route('customer.gcash-payment.proof.store', $order->id) }}"
        method="POST"
        enctype="multipart/form-data"
        class="mt-3"
    >
        @csrf

        <label
            for="gcashProofInput"
            class="block cursor-pointer overflow-hidden rounded-2xl border-2 border-dashed border-peach-soft bg-peach-cream text-center transition hover:border-peach-red/50"
        >
            <img
                id="gcashProofPreview"
                @if($gcashProofUrl) src="{{ $gcashProofUrl }}" @endif
                alt="Your payment screenshot"
                data-testid="gcash-proof-preview"
                class="mx-auto max-h-72 w-full object-contain {{ $gcashProofUrl ? '' : 'hidden' }}"
            >

            <span class="block px-4 py-4">
                <i class="bi bi-cloud-arrow-up text-2xl text-peach-red"></i>
                <span id="gcashProofPrompt" class="mt-1 block text-sm font-bold text-peach-deep">
                    {{ $gcashProofUrl ? 'Tap to replace' : 'Tap to upload' }}
                </span>
                <span class="mt-0.5 block text-xs text-peach-deep/50">
                    JPG, PNG or WEBP, up to 5 MB
                </span>
            </span>
        </label>

        <input
            id="gcashProofInput"
            type="file"
            name="screenshot"
            accept="image/jpeg,image/png,image/webp"
            class="sr-only"
            data-testid="gcash-proof-input"
        >

        <noscript>
            <button type="submit" class="mt-3 w-full rounded-full bg-peach-red px-5 py-2.5 text-sm font-bold text-white">
                Upload screenshot
            </button>
        </noscript>
    </form>

    <p id="gcashProofStatus" role="status" aria-live="polite" data-testid="gcash-proof-status" class="mt-2 min-h-4 text-xs font-semibold"></p>

    <form
        id="gcashProofRemoveForm"
        action="{{ route('customer.gcash-payment.proof.destroy', $order->id) }}"
        method="POST"
        class="mt-1 text-center {{ $gcashProofUrl ? '' : 'hidden' }}"
    >
        @csrf
        @method('DELETE')

        <button type="submit" data-testid="gcash-proof-remove" class="cursor-pointer text-xs font-bold text-peach-red underline">
            Remove screenshot
        </button>
    </form>

</div>

<script>
    (function () {
        const box = document.getElementById('gcashProof');
        const form = document.getElementById('gcashProofForm');
        const input = document.getElementById('gcashProofInput');
        const preview = document.getElementById('gcashProofPreview');
        const prompt = document.getElementById('gcashProofPrompt');
        const statusLine = document.getElementById('gcashProofStatus');
        const removeForm = document.getElementById('gcashProofRemoveForm');

        const MAX_BYTES = {{ \App\Services\GcashProof::MAX_KILOBYTES }} * 1024;
        const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

        let savedUrl = preview.getAttribute('src') || '';
        let objectUrl = null;

        function say(message, isError) {
            statusLine.textContent = message;
            statusLine.classList.toggle('text-red-600', !!isError);
            statusLine.classList.toggle('text-green-700', !isError);
        }

        function showPicture(url) {
            preview.classList.toggle('hidden', !url);

            if (url) {
                preview.setAttribute('src', url);
            } else {
                preview.removeAttribute('src');
            }
        }

        // While a request is out, I Have Paid would navigate away and abort it.
        function setBusy(busy) {
            const paid = document.getElementById('gcashPaidButton');

            if (paid) {
                paid.disabled = busy;
                paid.style.opacity = busy ? '0.6' : '';
            }

            input.disabled = busy;
        }

        function showSaved(url) {
            savedUrl = url || '';
            showPicture(savedUrl);
            prompt.textContent = savedUrl ? 'Tap to replace' : 'Tap to upload';
            removeForm.classList.toggle('hidden', !savedUrl);
        }

        function releasePreview() {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
        }

        // Whatever the server said, in words, or a plain message when the answer
        // was not ours (a 413 from PHP's own limit, a dropped connection).
        async function refusal(response) {
            if (response.status === 413) {
                return 'That image is larger than 5 MB. Please attach a smaller one.';
            }

            const data = await response.json().catch(function () { return {}; });

            return (data.errors && data.errors.screenshot && data.errors.screenshot[0])
                || data.message
                || 'We could not save your screenshot. Please try again.';
        }

        // Send a form in the background; true when the server accepted it.
        async function send(sendForm, url) {
            // Before setBusy: a disabled input is left out of the form data.
            const body = new FormData(sendForm);

            setBusy(true);

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: body,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    say(await refusal(response), true);

                    // The window closed under this tab: nothing more can be sent.
                    if (response.status === 409) {
                        form.classList.add('hidden');
                        removeForm.classList.add('hidden');
                    }

                    return null;
                }

                return await response.json();
            } catch (error) {
                say('We could not save your screenshot. Check your connection and try again.', true);

                return null;
            } finally {
                setBusy(false);
            }
        }

        input.addEventListener('change', async function () {
            const file = input.files[0];

            if (!file) {
                return;
            }

            // A convenience only: the server decides again from the file itself.
            if (TYPES.indexOf(file.type) === -1) {
                say('Please attach a JPG, PNG or WEBP image.', true);
                input.value = '';
                return;
            }

            if (file.size > MAX_BYTES) {
                say('That image is larger than 5 MB. Please attach a smaller one.', true);
                input.value = '';
                return;
            }

            releasePreview();
            objectUrl = URL.createObjectURL(file);
            showPicture(objectUrl);
            say('Saving your screenshot…', false);

            const data = await send(form, form.action);

            // Cleared either way, so choosing the same file again fires change.
            input.value = '';

            if (data) {
                showSaved(data.url);
                say(data.message, false);
            } else {
                showPicture(savedUrl);
            }

            releasePreview();
        });

        removeForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            say('Removing your screenshot…', false);

            const data = await send(removeForm, removeForm.action);

            if (data) {
                showSaved(null);
                say(data.message, false);
            }
        });

        // A verdict arrived while the page was open: nothing more to attach.
        window.endGcashProofUpload = function () {
            box.remove();
        };
    })();
</script>
