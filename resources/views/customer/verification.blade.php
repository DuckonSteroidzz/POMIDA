@extends('customer.layout')
 
@section('title', 'Verification')
@section('header-subtitle', 'Hurry Up and Explore!')
 
@section('content')
 
    @if (session('error'))
        <div class="alert alert-danger mb-3">{{ session('error') }}</div>
    @endif
 
    <p class="auth-title text-center">Verification</p>
    <p class="text-center small-text mb-3">Enter Verification Code</p>
 
    <form action="{{ route('customer.verification.post') }}" method="POST">
        @csrf
 
        {{-- 6-digit OTP Input --}}
        <div class="otp-wrapper mb-3">
            <input type="text" maxlength="1" class="otp-input" data-index="0" name="otp[]">
            <input type="text" maxlength="1" class="otp-input" data-index="1" name="otp[]">
            <input type="text" maxlength="1" class="otp-input" data-index="2" name="otp[]">
            <input type="text" maxlength="1" class="otp-input" data-index="3" name="otp[]">
            <input type="text" maxlength="1" class="otp-input" data-index="4" name="otp[]">
            <input type="text" maxlength="1" class="otp-input" data-index="5" name="otp[]">
        </div>
 
        <p class="text-center small-text mb-3">
            If you didn't receive a code,
            <a href="{{ route('customer.verification.resend') }}" class="auth-link">Resend</a>
        </p>
 
        <button type="submit" class="btn-main mb-3">Send</button>
 
    </form>
 
    <hr class="my-2" style="border-color: #eee;">
 
    <p class="text-center small-text mt-2">
        Back to Sign In? <a href="{{ route('customer.login') }}" class="auth-link">Sign In</a>
    </p>
 
@endsection
 
@push('styles')
<style>
    .otp-wrapper {
        display: flex;
        justify-content: center;
        gap: 0.5rem;
    }
 
    .otp-input {
        width: 44px;
        height: 50px;
        text-align: center;
        font-size: 1.2rem;
        font-weight: 600;
        border: 2px solid #ddd;
        border-radius: 10px;
        outline: none;
        background-color: #f9f9f9;
        color: #333;
        transition: border-color 0.2s;
    }
 
    .otp-input:focus {
        border-color: #F4845F;
        background-color: #fff;
    }
</style>
@endpush
 
@push('scripts')
<script>
    const inputs = document.querySelectorAll('.otp-input');
 
    inputs.forEach((input, index) => {
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
</script>
@endpush