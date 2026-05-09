@extends('customer.layout')

@section('title', 'Register')
@section('header-subtitle', 'Come on and Join Us!')

@section('content')

{{-- Error Alerts --}}
@if ($errors->any())
<div class="alert alert-danger mb-3">
    <ul class="mb-0 ps-3">
        @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<p class="auth-title">New Account</p>

<form action="{{ route('customer.register.post') }}" method="POST">
    @csrf

    {{-- Name --}}
    <div class="mb-3">
        <label class="form-label">Name</label>
        <input
            type="text"
            name="name"
            class="form-control @error('name') is-invalid @enderror"
            placeholder="Enter your Name"
            value="{{ old('name') }}"
            required
            autofocus>
        @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- Email --}}
    <div class="mb-3">
        <label class="form-label">Email Address</label>
        <input
            type="email"
            name="email"
            class="form-control @error('email') is-invalid @enderror"
            placeholder="Enter your email address"
            value="{{ old('email') }}"
            required>
        @error('email')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- Password --}}
    <div class="mb-3">
        <label class="form-label">Password</label>
        <input
            type="password"
            name="password"
            class="form-control @error('password') is-invalid @enderror"
            placeholder="Enter your password"
            required>
        @error('password')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- Confirm Password --}}
    <div class="mb-3">
        <label class="form-label">Confirm Password</label>
        <input
            type="password"
            name="password_confirmation"
            class="form-control"
            placeholder="Enter your password"
            required>
    </div>

    {{-- Contact Number --}}
    <div class="mb-3">
        <label class="form-label">Contact Number</label>
        <input
            type="tel"
            name="contact_number"
            class="form-control @error('contact_number') is-invalid @enderror"
            placeholder="09XXXXXXXXX"
            value="{{ old('contact_number') }}"
            pattern="[0-9]{10,13}"
            maxlength="13"
            inputmode="numeric"
            required
            oninput="this.value = this.value.replace(/[^0-9]/g, '')">
        @error('contact_number')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <small class="text-muted">Numbers only (10-13 digits)</small>
    </div>

    {{-- Address --}}
    <div class="mb-3">
        <label class="form-label">Address</label>
        <input
            type="text"
            name="address"
            class="form-control @error('address') is-invalid @enderror"
            placeholder="Enter your address"
            value="{{ old('address') }}"
            required>
        @error('address')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    {{-- View Terms Link --}}
    <div class="mb-3 text-center">
        <a href="#" class="auth-link small-text" data-bs-toggle="modal" data-bs-target="#termsModal">
            View Terms of Service
        </a>
    </div>

    {{-- Terms Checkbox --}}
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="terms" id="agreeTerms" required>
        <label class="form-check-label small-text" for="agreeTerms">
            I confirm that I have read and accept the
            <a href="#" class="auth-link" data-bs-toggle="modal" data-bs-target="#termsModal">terms and conditions and privacy policy</a>
        </label>
    </div>

    {{-- Submit --}}
    <button type="submit" class="btn-main mb-3">Create Account</button>

</form>

{{-- Sign In Link --}}
<p class="text-center small-text">
    Have an Account?
    <a href="{{ route('customer.login') }}" class="auth-link">Sign In</a>
</p>

@endsection

@push('scripts')
{{-- Terms of Service Modal (inline for register page) --}}
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 12px; font-family: 'Poppins', sans-serif;">

            {{-- Modal Header with orange branding --}}
            <div class="modal-header" style="background-color: #F4845F; border-radius: 12px 12px 0 0; padding: 1rem 1.5rem;">
                <div class="text-center w-100">
                    <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 0.4rem; font-size: 1.5rem;">🍑</div>
                    <h5 class="modal-title text-white fw-bold mb-0" id="termsModalLabel">Terms of Service</h5>
                </div>
                <button type="button" class="btn-close btn-close-white position-absolute" style="top: 1rem; right: 1rem;" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            {{-- Modal Body --}}
            <div class="modal-body" style="padding: 1.5rem; font-size: 0.82rem; color: #444; line-height: 1.7;">
                <p>By accessing and using this system, you agree to comply with the following terms and conditions. This web-based POS, order, and inventory management system is intended to facilitate transactions, manage product information, and improve operational efficiency for small businesses.</p>

                <p>Users are required to provide accurate and complete information when creating an account or placing orders. Any misuse of the system, including submitting false information or unauthorized access, may result in account restriction or termination.</p>

                <p>All orders placed through the system are subject to confirmation by the business. The system supports payment methods such as cash and GCash, and users are responsible for completing payments as required. The system records transaction data, including order details and user information, for operational and reporting purposes.</p>

                <p>Personal information collected, such as name, contact number, and transaction history, will be handled in accordance with applicable data privacy regulations. The system implements appropriate measures to protect user data and ensure secure access.</p>

                <p>By using the system, users consent to the collection and processing of their data for system functionality and service improvement.</p>

                <p class="mb-0">The system is provided for its intended use only, and the developers reserve the right to modify, suspend, or update system features and these policies at any time without prior notice. Continued use of the system constitutes acceptance of any changes made to these terms and conditions.</p>
            </div>

            {{-- Modal Footer --}}
            <div class="modal-footer" style="border-top: 1px solid #eee; padding: 1rem 1.5rem; gap: 0.5rem;">
                <button type="button" class="btn-dark-main" style="width: auto; padding: 0.5rem 1.5rem; border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-main" style="width: auto; padding: 0.5rem 1.5rem; border-radius: 8px;"
                    onclick="document.getElementById('agreeTerms').checked = true; bootstrap.Modal.getInstance(document.getElementById('termsModal')).hide();">
                    Accept &amp; Continue
                </button>
            </div>

        </div>
    </div>
</div>
@endpush