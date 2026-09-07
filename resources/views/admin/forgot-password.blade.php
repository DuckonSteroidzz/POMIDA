@extends('admin.partials.auth-layout')

@section('page-title', 'Forgot Password')
@section('form-title', 'Forgot Password')

@section('content')

    <p class="hint">
        Enter the email address on your admin or staff account and we will send
        you a 6-digit verification code.
    </p>

    <form action="{{ route('admin.forgot-password.post') }}" method="POST">

        @csrf

        <div class="form-group">

            <label for="email" class="form-label">
                Email Address
            </label>

            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                class="form-control @error('email') is-invalid @enderror"
                autocomplete="email"
                required
                autofocus
            >

        </div>

        <button type="submit" class="btn-login">
            Send Code
        </button>

    </form>

@endsection
