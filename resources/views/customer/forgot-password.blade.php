@extends('customer.partials.auth-shell')

@section('page-title', 'Forgot Password')
@section('page-subtitle', 'Reset your password')
@section('form-title', 'Forgot Password')

@section('content')

    @if (session('reset_expired'))

        <div class="alert alert-warning" role="alert" data-reset-expired>
            {{ session('reset_expired') }}
        </div>

    @else

        <p class="hint">
            Enter the email address on your account and we will send you a
            6-digit verification code.
        </p>

    @endif

    <form action="{{ route('customer.forgot-password.post') }}" method="POST">

        @csrf

        <div class="form-group">

            <label class="form-label">
                Email Address
            </label>

            <input
                type="email"
                name="email"
                class="form-control @error('email') is-invalid @enderror"
                value="{{ old('email') }}"
                required
                autofocus
            >

            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror

        </div>

        <button type="submit" class="btn-main">
            {{ session('reset_expired') ? 'Request a New Code' : 'Send Code' }}
        </button>

    </form>

@endsection
