@extends('customer.partials.auth-shell')

@section('page-title', 'Verification')
@section('page-subtitle', 'Reset your password')
@section('form-title', 'Verification')

@section('content')

    <p class="hint">
        Enter the 6-digit code we sent to your email address.
    </p>

    <form action="{{ route('customer.verification.post') }}" method="POST">

        @csrf

        {{-- 6-digit OTP Input --}}
        <div class="otp-wrapper">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" data-index="0" name="otp[]" autofocus>
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" data-index="1" name="otp[]">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" data-index="2" name="otp[]">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" data-index="3" name="otp[]">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" data-index="4" name="otp[]">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" data-index="5" name="otp[]">
        </div>

        <p class="hint">
            Didn't get a code?
            <a href="{{ route('customer.verification.resend') }}" class="auth-link">Resend</a>
        </p>

        <button type="submit" class="btn-main">
            Verify
        </button>

    </form>

@endsection

@push('scripts')
<script>
    (function () {
        var inputs = document.querySelectorAll('.otp-input');

        inputs.forEach(function (input, index) {

            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '');

                if (this.value && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !this.value && index > 0) {
                    inputs[index - 1].focus();
                }
            });
        });
    })();
</script>
@endpush
