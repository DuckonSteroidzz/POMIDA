@extends('customer.layout')
 
@section('title', 'Login')
@section('header-subtitle', 'Hurry Up and Explore!')
 
@section('content')
 
    {{-- Error / Success Alerts --}}
    @if ($errors->any())
        <div class="alert alert-danger mb-3">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
 
    @if (session('success'))
        <div class="alert alert-success mb-3">{{ session('success') }}</div>
    @endif
 
    <p class="auth-title">Login Account</p>
 
    <form action="{{ route('customer.login.post') }}" method="POST">
        @csrf
 
        {{-- Email --}}
        <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input
                type="email"
                name="email"
                class="form-control @error('email') is-invalid @enderror"
                placeholder="Enter your email address"
                value="{{ old('email') }}"
                required
                autofocus
            >
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
 
        {{-- Password --}}
        <div class="mb-2">
            <label class="form-label">Password</label>
            <input
                type="password"
                name="password"
                class="form-control @error('password') is-invalid @enderror"
                placeholder="Enter your password"
                required
            >
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
 
        {{-- Remember Me + Forgot Password --}}
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="remember" id="rememberMe">
                <label class="form-check-label small-text" for="rememberMe">Save Password</label>
            </div>
            <a href="#" class="auth-link small-text">Forgot Password?</a>
        </div>
 
        {{-- Submit --}}
        <button type="submit" class="btn-main mb-3">Login Account</button>
 
    </form>
 
    {{-- Sign Up Link --}}
    <p class="text-center small-text">
        Don't have an Account?
        <a href="{{ route('customer.register') }}" class="auth-link">Sign Up</a>
    </p>
 
@endsection