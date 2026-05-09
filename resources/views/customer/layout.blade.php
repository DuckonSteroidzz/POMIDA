<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'POS System')</title>
 
    {{-- Bootstrap 5 --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    {{-- Bootstrap Icons --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    {{-- Google Fonts --}}
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
 
    <style>
        * {
            font-family: 'Poppins', sans-serif;
            box-sizing: border-box;
        }
 
        body {
            min-height: 100vh;
            background-color: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
 
        .auth-wrapper {
            width: 100%;
            max-width: 420px;
        }
 
        .auth-header {
            background-color: #F4845F;
            border-radius: 12px 12px 0 0;
            padding: 2rem 1.5rem 1.5rem;
            text-align: center;
            color: white;
        }
 
        .auth-header .logo-circle {
            width: 64px;
            height: 64px;
            background-color: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            font-size: 2rem;
        }
 
        .auth-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.1rem;
        }
 
        .auth-header p {
            font-size: 0.85rem;
            opacity: 0.85;
            margin-bottom: 0;
        }
 
        .auth-card {
            background: white;
            border-radius: 0 0 12px 12px;
            padding: 1.75rem 1.5rem 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
 
        .auth-title {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 1.25rem;
        }
 
        .form-label {
            font-size: 0.8rem;
            font-weight: 500;
            color: #555;
            margin-bottom: 0.3rem;
        }
 
        .form-control {
            font-size: 0.85rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 0.55rem 0.85rem;
            background-color: #f9f9f9;
            color: #333;
        }
 
        .form-control:focus {
            border-color: #F4845F;
            box-shadow: 0 0 0 3px rgba(244, 132, 95, 0.15);
            background-color: #fff;
        }
 
        .form-control::placeholder {
            color: #aaa;
            font-size: 0.82rem;
        }
 
        .btn-main {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.6rem;
            font-size: 0.88rem;
            font-weight: 600;
            width: 100%;
            transition: background-color 0.2s;
        }
 
        .btn-main:hover {
            background-color: #e06f4a;
            color: white;
        }
 
        .btn-dark-main {
            background-color: #2e2e2e;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.6rem;
            font-size: 0.88rem;
            font-weight: 600;
            width: 100%;
            transition: background-color 0.2s;
        }
 
        .btn-dark-main:hover {
            background-color: #1a1a1a;
            color: white;
        }
 
        .auth-link {
            color: #F4845F;
            text-decoration: none;
            font-weight: 500;
        }
 
        .auth-link:hover {
            color: #e06f4a;
            text-decoration: underline;
        }
 
        .small-text {
            font-size: 0.78rem;
            color: #888;
        }
 
        .divider-text {
            text-align: center;
            color: #aaa;
            font-size: 0.8rem;
            margin: 0.75rem 0;
            position: relative;
        }
 
        .divider-text::before,
        .divider-text::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 42%;
            height: 1px;
            background: #e0e0e0;
        }
 
        .divider-text::before { left: 0; }
        .divider-text::after { right: 0; }
 
        .alert {
            font-size: 0.82rem;
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
        }
 
        @media (max-width: 480px) {
            .auth-wrapper {
                max-width: 100%;
            }
            .auth-header {
                padding: 1.5rem 1.25rem 1.25rem;
            }
            .auth-card {
                padding: 1.5rem 1.25rem;
            }
        }
    </style>
 
    @stack('styles')
</head>
<body>
 
    <div class="auth-wrapper">
        {{-- Header --}}
        <div class="auth-header">
            <div class="logo-circle">🍑</div>
            <h1>Hello</h1>
            <p>@yield('header-subtitle', 'Hurry Up and Explore!')</p>
        </div>
 
        {{-- Card Content --}}
        <div class="auth-card">
            @yield('content')
        </div>
    </div>
 
    {{-- Bootstrap JS --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>