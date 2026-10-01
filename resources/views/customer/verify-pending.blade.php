@extends('customer.partials.auth-shell')

@section('page-title', 'Confirm your email')
@section('page-subtitle', 'One last step')
@section('form-title', 'Check your email to activate your account')

{{--
    Shown after sign-up, after a correct-password login on an account that
    has not confirmed its email yet, and after every Resend. No one is signed
    in here. See App\Http\Controllers\Concerns\HandlesEmailVerification.

    The address shown is the one THIS browser just typed. It is kept in the
    session by register()/login() and never looked up from the database, so
    the page shows nothing about whether an account exists. The Resend form
    gets the same response either way.
--}}
@section('content')

    @if ($email)
        <p class="hint">
            We sent a confirmation link to <strong>{{ $email }}</strong>.
            Open that email and tap <strong>Confirm Email Address</strong> to
            activate your account, then log in.
        </p>
    @else
        <p class="hint">
            Open the confirmation email we sent you and tap
            <strong>Confirm Email Address</strong> to activate your account,
            then log in.
        </p>
    @endif

    <p class="hint">
        The link works for 60 minutes. Can't find it? Check your spam or
        promotions folder, or send a new link.
    </p>

    <form action="{{ route('customer.email-verification.request') }}" method="POST">

        @csrf

        @if ($email)
            <input type="hidden" name="email" value="{{ $email }}">
        @else
            <div class="form-group">

                <label class="form-label" for="pendingEmail">
                    Email Address
                </label>

                <input
                    id="pendingEmail"
                    type="email"
                    name="email"
                    class="form-control @error('email') is-invalid @enderror"
                    value="{{ old('email') }}"
                    autocomplete="email"
                    placeholder="you@example.com"
                    required
                >

            </div>
        @endif

        <button type="submit" class="btn-main">
            Resend confirmation email
        </button>

    </form>

    <p class="hint">
        Already confirmed?
        <a href="{{ $loginUrl }}" class="auth-link">Log in</a>
    </p>

@endsection
