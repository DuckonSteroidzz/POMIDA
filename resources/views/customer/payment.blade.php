<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - Peachy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: #e8e8e8;
            min-height: 100vh;
            display: flex;
            justify-content: center;
        }

        .app-wrapper {
            width: 100%;
            max-width: 420px;
            min-height: 100vh;
            background-color: #fff;
            display: flex;
            flex-direction: column;
        }

        .top-header {
            background-color: #fff;
            padding: 0.75rem 1rem 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background-color: rgba(244, 132, 95, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .business-info h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #F4845F;
            margin: 0;
        }

        .business-info p {
            font-size: 0.65rem;
            color: #aaa;
            margin: 0;
        }

        .search-bar {
            padding: 0.3rem 1rem 0.5rem;
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .input-wrapper {
            flex: 1;
            position: relative;
        }

        .input-wrapper input {
            width: 100%;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            padding: 0.4rem 0.85rem 0.4rem 2rem;
            font-size: 0.78rem;
            background-color: #f5f5f5;
            color: #333;
            outline: none;
        }

        .search-icon {
            position: absolute;
            left: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            color: #bbb;
            font-size: 0.8rem;
        }

        .btn-cancel-search {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 20px;
            padding: 0.38rem 0.9rem;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
        }

        .content-section {
            background-color: #F4845F;
            flex: 1;
            padding: 0.75rem;
            padding-bottom: 130px;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: white;
            text-align: center;
            margin-bottom: 0.75rem;
        }

        /* Payment Method Buttons */
        .payment-btn {
            background-color: white;
            border: none;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.6rem;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.88rem;
            font-weight: 600;
            color: #333;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .payment-btn:hover {
            opacity: 0.9;
        }

        .payment-btn.active {
            border: 2px solid #F4845F;
        }

        .payment-btn img {
            height: 24px;
        }

        /* Discount Section */
        .discount-row {
            background-color: white;
            border-radius: 10px;
            padding: 0.65rem 0.85rem;
            margin-bottom: 0.6rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .discount-label {
            font-size: 0.8rem;
            font-weight: 500;
            color: #333;
        }

        .add-more-btn {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.2rem 0.6rem;
            font-size: 0.7rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.2rem;
        }

        /* Discount Form */
        .discount-form {
            background-color: white;
            border-radius: 10px;
            padding: 0.75rem;
            margin-bottom: 0.6rem;
            display: none;
        }

        .discount-form.show {
            display: block;
        }

        .discount-form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.6rem;
        }

        .discount-type {
            font-size: 0.8rem;
            font-weight: 600;
            color: #333;
        }

        .discount-id-label {
            font-size: 0.72rem;
            color: #888;
        }

        .discount-body {
            display: flex;
            gap: 0.6rem;
            align-items: flex-start;
        }

        .upload-box {
            width: 70px;
            height: 70px;
            border: 1px dashed #ccc;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            background-color: #fafafa;
        }

        .upload-box i {
            font-size: 1.2rem;
            color: #aaa;
        }

        .upload-box span {
            font-size: 0.6rem;
            color: #aaa;
            margin-top: 2px;
        }

        .discount-inputs {
            flex: 1;
        }

        .discount-inputs input {
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 0.35rem 0.6rem;
            font-size: 0.75rem;
            background-color: #f9f9f9;
            color: #333;
            outline: none;
            margin-bottom: 0.4rem;
        }

        .discount-inputs input:focus {
            border-color: #F4845F;
        }

        .cancel-discount {
            background: none;
            border: none;
            color: #F44336;
            font-size: 0.72rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.2rem;
            justify-content: flex-end;
            width: 100%;
            margin-top: 0.3rem;
        }

        /* Bottom */
        .bottom-fixed {
            position: fixed;
            bottom: 0;
            width: 100%;
            max-width: 420px;
            background-color: white;
            border-top: 1px solid #eee;
            padding: 0.6rem 1rem;
            z-index: 100;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.6rem;
        }

        .total-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #333;
        }

        .total-amount {
            font-size: 0.95rem;
            font-weight: 700;
            color: #F4845F;
        }

        .action-btns {
            display: flex;
            gap: 0.5rem;
        }

        .btn-back {
            background-color: #2e2e2e;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.55rem;
            font-size: 0.82rem;
            font-weight: 600;
            flex: 1;
            cursor: pointer;
        }

        .btn-proceed {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.55rem;
            font-size: 0.82rem;
            font-weight: 600;
            flex: 1;
            cursor: pointer;
        }
    </style>
</head>

<body>
    <div class="app-wrapper">

        <div class="top-header">
            <div class="logo-circle">🍑</div>
            <div class="business-info">
                <h2>Peachy</h2>
                <p>Cakes and Deli Cafe</p>
            </div>
        </div>

        <div class="search-bar">
            <div class="input-wrapper">
                <i class="bi bi-search search-icon"></i>
                <input type="text" placeholder="Search..." id="searchInput">
            </div>
            <button class="btn-cancel-search" onclick="document.getElementById('searchInput').value=''">Cancel</button>
        </div>

        <div class="content-section">
            <p class="section-title">Payment</p>

            {{-- Payment Methods --}}
            <button class="payment-btn" id="cashBtn" onclick="selectPayment('cash')">
                <i class="bi bi-cash-coin" style="font-size:1.2rem;color:#4CAF50;"></i> Cash
            </button>
            <button class="payment-btn" id="gcashBtn" onclick="selectPayment('gcash')">
                <i class="bi bi-phone" style="font-size:1.2rem;color:#007AFF;"></i> GCash
            </button>

            {{-- Senior Citizen Discount --}}
            <div class="discount-row" onclick="toggleDiscount('senior')">
                <span class="discount-label">Apply a Senior Citizen Discount (<span id="seniorCount">0</span>)</span>
                <button class="add-more-btn" onclick="event.stopPropagation(); addDiscount('senior')">
                    Add more <i class="bi bi-plus-circle-fill"></i>
                </button>
            </div>
            <div id="seniorForms"></div>

            {{-- PWD Discount --}}
            <div class="discount-row" onclick="toggleDiscount('pwd')">
                <span class="discount-label">Apply a PWD Discount (<span id="pwdCount">0</span>)</span>
                <button class="add-more-btn" onclick="event.stopPropagation(); addDiscount('pwd')">
                    Add more <i class="bi bi-plus-circle-fill"></i>
                </button>
            </div>
            <div id="pwdForms"></div>

        </div>

        <form action="{{ route('customer.payment.post') }}" method="POST" id="paymentForm">
            @csrf
            <input type="hidden" name="payment_method" id="paymentMethod" value="">
            <input type="hidden" name="voucher_code_confirmed" value="{{ session('voucher_code') }}">
        </form>

        <div class="bottom-fixed">
            <div class="total-row">
                <span class="total-label">Total</span>
                <span class="total-amount">P{{ isset($total) ? number_format($total, 2) : '0.00' }}</span>
            </div>
            <div class="action-btns">
                <button class="btn-back" onclick="window.location.href=`{{ route('customer.cart') }}`">Back to Menu</button>
                <button class="btn-proceed" onclick="checkAndSubmit()">Proceed to Checkout</button>
            </div>
        </div>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedPayment = '';
        let seniorCount = 0;
        let pwdCount = 0;

        function selectPayment(type) {
            selectedPayment = type;
            document.getElementById('paymentMethod').value = type;
            document.getElementById('cashBtn').classList.toggle('active', type === 'cash');
            document.getElementById('gcashBtn').classList.toggle('active', type === 'gcash');
        }

        function addDiscount(type) {
            if (type === 'senior') {
                seniorCount++;
                document.getElementById('seniorCount').textContent = seniorCount;
                document.getElementById('seniorForms').insertAdjacentHTML('beforeend', discountFormHTML(type, seniorCount));
            } else {
                pwdCount++;
                document.getElementById('pwdCount').textContent = pwdCount;
                document.getElementById('pwdForms').insertAdjacentHTML('beforeend', discountFormHTML(type, pwdCount));
            }
        }

        function removeDiscount(type, id) {
            document.getElementById(type + '-form-' + id).remove();
            if (type === 'senior') {
                seniorCount--;
                document.getElementById('seniorCount').textContent = seniorCount;
            } else {
                pwdCount--;
                document.getElementById('pwdCount').textContent = pwdCount;
            }
        }

        function discountFormHTML(type, id) {
            const label = type === 'senior' ? 'Senior Citizen' : 'PWD';
            return `
        <div class="discount-form show" id="${type}-form-${id}">
            <div class="discount-form-header">
                <span class="discount-type">${label}</span>
                <span class="discount-id-label">Discount ID (Optional)</span>
            </div>
            <div class="discount-body">
                <div class="upload-box">
                    <i class="bi bi-cloud-arrow-up"></i>
                    <span>upload here</span>
                </div>
                <div class="discount-inputs">
                    <input type="text" name="${type}_card_number[]" placeholder="Card number">
                    <input type="text" name="${type}_name[]" placeholder="Name on card">
                </div>
            </div>
            <button type="button" class="cancel-discount" onclick="removeDiscount('${type}', ${id})">
                Cancel <i class="bi bi-x-circle-fill"></i>
            </button>
        </div>`;
        }

        function checkAndSubmit() {
            const method = document.getElementById('paymentMethod').value;
            if (!method) {
                alert("Please select a payment method (Cash or GCash) first!");
                return;
            }

            // Ilipat ang lahat ng Senior/PWD inputs sa loob ng form bago i-submit
            const form = document.getElementById('paymentForm');
            const seniorInputs = document.getElementById('seniorForms').querySelectorAll('input');
            const pwdInputs = document.getElementById('pwdForms').querySelectorAll('input');

            seniorInputs.forEach(input => form.appendChild(input.cloneNode(true)));
            pwdInputs.forEach(input => form.appendChild(input.cloneNode(true)));

            form.submit();
        }
    </script>
</body>

</html>