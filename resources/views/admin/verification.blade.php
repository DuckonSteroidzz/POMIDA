@extends('admin.partials.auth-layout')

@section('page-title', 'Verification')
@section('form-title', 'Verification')

@section('content')

    <p class="hint">
        Enter the 6-digit code we sent to
        <strong>{{ session('admin_password_reset.email') }}</strong>.
    </p>

    <form action="{{ route('admin.verification.post') }}" method="POST">

        @csrf

        <div class="otp-wrapper">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" name="otp[]" autofocus>
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" name="otp[]">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" name="otp[]">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" name="otp[]">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" name="otp[]">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-input" name="otp[]">
        </div>

        <p class="hint">
            Didn't get a code?
            <a href="{{ route('admin.verification.resend') }}" class="auth-link">Resend</a>
        </p>

        <button type="submit" class="btn-login">
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
