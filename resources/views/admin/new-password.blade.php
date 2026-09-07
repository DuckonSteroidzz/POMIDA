@extends('admin.partials.auth-layout')

@section('page-title', 'New Password')
@section('form-title', 'New Password')

@section('content')

    <p class="hint">
        Choose a new password for
        <strong>{{ session('admin_password_reset.email') }}</strong>.
    </p>

    <form action="{{ route('admin.new-password.post') }}" method="POST">

        @csrf

        <div class="form-group">

            <label for="password" class="form-label">
                New Password
            </label>

            <div class="password-wrapper">

                <input
                    id="password"
                    type="password"
                    name="password"
                    class="form-control @error('password') is-invalid @enderror"
                    autocomplete="new-password"
                    required
                    autofocus
                >

                <button
                    type="button"
                    class="password-toggle"
                    data-password-toggle="password"
                    aria-label="Show password"
                    aria-pressed="false"
                >
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </button>

            </div>

        </div>

        <div class="form-group">

            <label for="password_confirmation" class="form-label">
                Confirm Password
            </label>

            <div class="password-wrapper">

                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    class="form-control"
                    autocomplete="new-password"
                    required
                >

                <button
                    type="button"
                    class="password-toggle"
                    data-password-toggle="password_confirmation"
                    aria-label="Show password"
                    aria-pressed="false"
                >
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </button>

            </div>

        </div>

        <button type="submit" class="btn-login">
            Update Password
        </button>

    </form>

@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {

        var field = document.getElementById(button.dataset.passwordToggle);
        var icon = button.querySelector('i');

        if (!field || !icon) {
            return;
        }

        button.addEventListener('click', function () {

            var revealed = field.type === 'text';

            field.type = revealed ? 'password' : 'text';

            icon.classList.toggle('bi-eye', revealed);
            icon.classList.toggle('bi-eye-slash', !revealed);

            button.setAttribute('aria-pressed', String(!revealed));
            button.setAttribute('aria-label', revealed ? 'Show password' : 'Hide password');

            field.focus();
        });
    });
</script>
@endpush
