@extends('customer.layout')
 
@section('title', 'Terms of Service')
@section('header-subtitle', 'Please Read Carefully')
 
@section('content')
 
    <p class="auth-title text-center">Terms of Service</p>
 
    <div style="font-size: 0.82rem; color: #444; line-height: 1.75; max-height: 55vh; overflow-y: auto; padding-right: 4px; margin-bottom: 1rem;">
 
        <p>By accessing and using this system, you agree to comply with the following terms and conditions. This web-based POS, order, and inventory management system is intended to facilitate transactions, manage product information, and improve operational efficiency for small businesses.</p>
 
        <p>Users are required to provide accurate and complete information when creating an account or placing orders. Any misuse of the system, including submitting false information or unauthorized access, may result in account restriction or termination.</p>
 
        <p>All orders placed through the system are subject to confirmation by the business. The system supports payment methods such as cash and GCash, and users are responsible for completing payments as required. The system records transaction data, including order details and user information, for operational and reporting purposes.</p>
 
        <p>Personal information collected, such as name, contact number, and transaction history, will be handled in accordance with applicable data privacy regulations. The system implements appropriate measures to protect user data and ensure secure access. By using the system, users consent to the collection and processing of their data for system functionality and service improvement.</p>
 
        <p class="mb-0">The system is provided for its intended use only, and the developers reserve the right to modify, suspend, or update system features and these policies at any time without prior notice. Continued use of the system constitutes acceptance of any changes made to these terms and conditions.</p>
 
    </div>
 
    {{-- Checkbox --}}
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="agreeTermsPage" required>
        <label class="form-check-label small-text" for="agreeTermsPage">
            I confirm that I have read and accept the terms and conditions and privacy policy
        </label>
    </div>
 
    {{-- Buttons --}}
    <div class="d-flex gap-2">
        <a href="{{ route('customer.register') }}"
            class="btn-dark-main text-center text-decoration-none"
            style="padding: 0.6rem; border-radius: 8px; font-size: 0.88rem; font-weight: 600; color: white; display: block; flex: 1;">
            Cancel
        </a>
        <button type="button" class="btn-main" style="flex: 1;" id="acceptBtn" disabled
            id="acceptBtn">
            Accept &amp; Continue
        </button>
    </div>
 
@endsection
 
@push('scripts')
<script>
    const registerUrl = "{{ route('customer.register') }}";
    const checkbox = document.getElementById('agreeTermsPage');
    const acceptBtn = document.getElementById('acceptBtn');
 
    acceptBtn.style.opacity = '0.5';
 
    checkbox.addEventListener('change', function () {
        acceptBtn.disabled = !this.checked;
        acceptBtn.style.opacity = this.checked ? '1' : '0.5';
    });
 
    acceptBtn.addEventListener('click', function () {
        window.location.href = registerUrl;
    });
</script>
@endpush