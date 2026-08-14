@extends('customer.layout')
 
@section('title', 'Forgot Password')
@section('header-subtitle', 'Hurry Up and Explore!')
 
@section('content')
 
    @if (session('success'))
        <div class="alert alert-success mb-3">{{ session('success') }}</div>
    @endif
 
    @if (session('error'))
        <div class="alert alert-danger mb-3">{{ session('error') }}</div>
    @endif
 
    <p class="auth-title text-center">Forgot Password</p>
 
    <form action="{{ route('customer.forgot-password.post') }}" method="POST">
        @csrf
 
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
 
        <button type="submit" class="btn-main mb-3">Send</button>
 
    </form>
 
    <div class="divider-text my-2">Or</div>
 
    {{-- Social Options --}}
    <div class="d-flex justify-content-center gap-3 mb-3">
        <a href="#" class="social-btn facebook">
            <i class="bi bi-facebook"></i>
        </a>
        <a href="#" class="social-btn gmail">
            <i class="bi bi-envelope-fill"></i>
        </a>
    </div>
 
    <p class="text-center small-text">
        Back to Sign In? <a href="{{ route('customer.login') }}" class="auth-link">Sign In</a>
    </p>
 
@endsection
 
@push('styles')
<style>
    .divider-text {
        text-align: center;
        color: #aaa;
        font-size: 0.8rem;
        position: relative;
    }
    .divider-text::before,
    .divider-text::after {
        content: '';
        position: absolute;
        top: 50%;
        width: 40%;
        height: 1px;
        background: #e0e0e0;
    }
    .divider-text::before { left: 0; }
    .divider-text::after { right: 0; }
 
    .social-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        text-decoration: none;
        transition: opacity 0.2s;
    }
    .social-btn:hover { opacity: 0.8; }
    .social-btn.facebook { background-color: #1877F2; color: white; }
    .social-btn.gmail { background-color: #EA4335; color: white; }
</style>
@endpush