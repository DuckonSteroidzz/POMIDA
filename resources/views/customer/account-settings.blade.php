<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - Peachy</title>
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
            padding-bottom: 70px;
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
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: white;
            text-align: center;
            margin-bottom: 0.2rem;
        }

        .section-subtitle {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.85);
            text-align: center;
            margin-bottom: 0.75rem;
        }

        .settings-card {
            background-color: white;
            border-radius: 12px;
            padding: 0.85rem;
            margin-bottom: 0.6rem;
        }

        .form-group {
            margin-bottom: 0.65rem;
        }

        .form-group label {
            font-size: 0.75rem;
            font-weight: 500;
            color: #555;
            display: block;
            margin-bottom: 0.2rem;
        }

        .form-group input {
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 0.45rem 0.75rem;
            font-size: 0.8rem;
            background-color: #f9f9f9;
            color: #333;
            outline: none;
        }

        .form-group input:focus {
            border-color: #F4845F;
            background-color: #fff;
        }

        .discount-section {
            display: flex;
            gap: 0.6rem;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .discount-label-col {
            width: 60px;
            font-size: 0.72rem;
            font-weight: 600;
            color: #333;
            padding-top: 0.3rem;
            flex-shrink: 0;
        }

        .upload-box {
            width: 60px;
            height: 60px;
            border: 1px dashed #ccc;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background-color: #fafafa;
            flex-shrink: 0;
            overflow: hidden;
        }

        .upload-box i {
            font-size: 1.1rem;
            color: #aaa;
        }

        .upload-box span {
            font-size: 0.58rem;
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
            font-size: 0.73rem;
            background-color: #f9f9f9;
            color: #333;
            outline: none;
            margin-bottom: 0.35rem;
        }

        .discount-inputs .discount-id {
            font-size: 0.68rem;
            color: #aaa;
            text-align: right;
            display: block;
            margin-bottom: 0.2rem;
        }

        .action-row {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.3rem;
        }

        .btn-delete {
            background-color: #F44336;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.45rem 0.75rem;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            flex: 1;
        }

        .btn-save {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.45rem 0.75rem;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            flex: 1;
        }

        .btn-logout {
            background-color: white;
            color: #333;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 0.55rem;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 0.6rem;
            font-family: 'Poppins', sans-serif;
        }

        .btn-logout:hover {
            background-color: #f5f5f5;
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            width: 100%;
            max-width: 420px;
            background-color: #fff;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 0.5rem 0 0.4rem;
            z-index: 100;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            text-decoration: none;
            color: #F4845F;
            font-size: 0.65rem;
            font-weight: 500;
            cursor: pointer;
        }

        .nav-item i {
            font-size: 1.3rem;
        }

        .nav-item.active {
            color: #F4845F;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 0.5rem 1rem;
            margin: 0 0.75rem 0.5rem;
            border-radius: 8px;
            font-size: 0.8rem;
            text-align: center;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            padding: 0.5rem 1rem;
            margin: 0 0.75rem 0.5rem;
            border-radius: 8px;
            font-size: 0.8rem;
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

        @if(session('success'))
        <div class="alert-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
        <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        <div class="content-section">
            <p class="section-title">Account Settings</p>
            <p class="section-subtitle">Account Details</p>

            <form action="{{ route('customer.account.update') }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="settings-card">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" value="{{ auth()->user()->name ?? '' }}" placeholder="Jane Doe">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" value="{{ auth()->user()->address ?? '' }}" placeholder="123 st. brgy advg">
                    </div>
                    <div class="form-group">
                        <label>Contact</label>
                        <input type="text" name="contact_number" value="{{ auth()->user()->contact_number ?? '' }}" placeholder="09914048181">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" value="{{ auth()->user()->email ?? '' }}" placeholder="janedoe@gmail.com">
                    </div>
                    <div class="form-group">
                        <label>Password <span style="font-size:0.65rem;color:#aaa;">(leave blank to keep current)</span></label>
                        <input type="password" name="password" placeholder="••••••••••">
                    </div>

                    {{-- PWD --}}
                    <div class="discount-section">
                        <span class="discount-label-col">PWD</span>
                        <label for="pwdImage" class="upload-box">
                            @if(auth()->user()->pwd_image)
                            <img src="{{ asset(auth()->user()->pwd_image) }}" style="width:100%;height:100%;object-fit:cover;border-radius:8px;">
                            @else
                            <i class="bi bi-cloud-arrow-up"></i>
                            <span>upload</span>
                            @endif
                        </label>
                        <input type="file" name="pwd_image" id="pwdImage" style="display:none;" accept="image/*">
                        <div class="discount-inputs">
                            <span class="discount-id">Discount ID (Optional)</span>
                            <input type="text" name="pwd_card_number" value="{{ auth()->user()->pwd_card_number ?? '' }}" placeholder="Card number">
                            <input type="text" name="pwd_name" value="{{ auth()->user()->pwd_name ?? '' }}" placeholder="Name on card">
                        </div>
                    </div>

                    {{-- Senior --}}
                    <div class="discount-section">
                        <span class="discount-label-col">SENIOR</span>
                        <label for="seniorImage" class="upload-box">
                            @if(auth()->user()->senior_image)
                            <img src="{{ asset(auth()->user()->senior_image) }}" style="width:100%;height:100%;object-fit:cover;border-radius:8px;">
                            @else
                            <i class="bi bi-cloud-arrow-up"></i>
                            <span>upload</span>
                            @endif
                        </label>
                        <input type="file" name="senior_image" id="seniorImage" style="display:none;" accept="image/*">
                        <div class="discount-inputs">
                            <span class="discount-id">Discount ID (Optional)</span>
                            <input type="text" name="senior_card_number" value="{{ auth()->user()->senior_card_number ?? '' }}" placeholder="Card number">
                            <input type="text" name="senior_name" value="{{ auth()->user()->senior_name ?? '' }}" placeholder="Name on card">
                        </div>
                    </div>

                    <div class="action-row">
                        <button type="button" class="btn-delete" onclick="confirmDelete()">DELETE ACCOUNT</button>
                        <button type="submit" class="btn-save">SAVE</button>
                    </div>
                </div>

            </form>

            <form action="{{ route('customer.logout') }}" method="POST">
                @csrf
                <button type="submit" class="btn-logout">LOG OUT</button>
            </form>

            {{-- My Vouchers Link --}}
            <a href="{{ route('customer.vouchers') }}"
                style="display:flex;align-items:center;gap:0.75rem;padding:0.85rem 1rem;background:white;border-radius:12px;margin-bottom:0.6rem;text-decoration:none;color:#333;">
                <div style="width:36px;height:36px;background:linear-gradient(135deg,#F4845F,#C0392B);border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-ticket-perforated" style="color:white;font-size:1rem;"></i>
                </div>
                <div style="flex:1;">
                    <span style="font-size:0.85rem;font-weight:600;display:block;">My Vouchers</span>
                    <span style="font-size:0.65rem;color:#aaa;">View your earned vouchers</span>
                </div>
                <i class="bi bi-chevron-right" style="color:#aaa;"></i>
            </a>

        </div>

        <div class="bottom-nav">
            <a href="{{ route('customer.orders') }}" class="nav-item">
                <i class="bi bi-receipt"></i><span>Orders</span>
            </a>
            <a href="{{ route('customer.menu') }}" class="nav-item">
                <i class="bi bi-grid"></i><span>Menu</span>
            </a>
            <a href="{{ route('customer.cart') }}" class="nav-item" style="position:relative;">
                <i class="bi bi-cart"></i>
                @php $cartCount = count(session('cart', [])); @endphp
                @if($cartCount > 0)
                <span style="position:absolute;top:-4px;right:-4px;background:#C0392B;color:white;border-radius:50%;width:16px;height:16px;font-size:0.55rem;font-weight:700;display:flex;align-items:center;justify-content:center;">{{ $cartCount }}</span>
                @endif
                <span>Cart</span>
            </a>
            <a href="{{ route('customer.more') }}" class="nav-item active">
                <i class="bi bi-three-dots"></i><span>More</span>
            </a>
            <a href="#" class="nav-item">
                <i class="bi bi-question-circle"></i><span>Help</span>
            </a>
        </div>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDelete() {
            if (confirm('Are you sure you want to delete your account? This cannot be undone.')) {
                fetch('{{ route("customer.account.delete") }}', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                }).then(() => {
                    window.location.href = '{{ route("customer.login") }}';
                });
            }
        }

        document.getElementById('pwdImage').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    const label = document.querySelector('label[for="pwdImage"]');
                    label.innerHTML = '<img src="' + ev.target.result + '" style="width:100%;height:100%;object-fit:cover;border-radius:8px;">';
                };
                reader.readAsDataURL(e.target.files[0]);
            }
        });

        document.getElementById('seniorImage').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    const label = document.querySelector('label[for="seniorImage"]');
                    label.innerHTML = '<img src="' + ev.target.result + '" style="width:100%;height:100%;object-fit:cover;border-radius:8px;">';
                };
                reader.readAsDataURL(e.target.files[0]);
            }
        });
    </script>
</body>

</html>