@extends('customer.layout')
 
@section('title', 'New Password')
@section('header-subtitle', 'Hurry Up and Explore!')
 
@section('content')
 
    @if ($errors->any())
        <div class="alert alert-danger mb-3">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
 
    <p class="auth-title text-center">New Password</p>
 
    <form action="{{ route('customer.new-password.post') }}" method="POST">
        @csrf
 
        <input type="hidden" name="token" value="{{ $token ?? '' }}">
        <input type="hidden" name="email" value="{{ $email ?? '' }}">
 
        <div class="mb-3">
            <label class="form-label">New Password</label>
            <input
                type="password"
                name="password"
                class="form-control @error('password') is-invalid @enderror"
                placeholder="Enter your new password"
                required
                autofocus
            >
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
 
        <div class="mb-4">
            <label class="form-label">Confirm Password</label>
            <input
                type="password"
                name="password_confirmation"
                class="form-control"
                placeholder="Enter your new password"
                required
            >
        </div>
 
        <button type="submit" class="btn-main">Confirm</button>
 
    </form>
 
@endsection