<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - Peachy Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
 
        body {
            min-height: 100vh;
            background-color: #F4845F;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
 
        .auth-wrapper { width: 100%; max-width: 380px; }
 
        .logo-section { text-align: center; margin-bottom: 1.5rem; }
 
        .logo-box {
            width: 100px;
            height: 100px;
            background-color: white;
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            padding: 0.5rem;
        }
 
        .logo-box .logo-emoji { font-size: 2.5rem; }
        .logo-box h2 { font-size: 1rem; font-weight: 700; color: #F4845F; margin: 0; }
        .logo-box p { font-size: 0.55rem; color: #aaa; margin: 0; }
 
        .auth-card {
            background-color: #fde8de;
            border-radius: 16px;
            padding: 2rem 1.75rem;
        }
 
        .auth-card h3 { font-size: 1.3rem; font-weight: 700; color: #333; text-align: center; margin-bottom: 0.3rem; }
        .auth-card .subtitle { font-size: 0.75rem; color: #888; text-align: center; margin-bottom: 1.5rem; }
 
        .input-group-custom { position: relative; margin-bottom: 0.85rem; }
        .input-group-custom i { position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: #aaa; font-size: 0.9rem; }
        .input-group-custom input {
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 0.6rem 0.85rem 0.6rem 2.5rem;
            font-size: 0.85rem;
            background-color: white;
            color: #333;
            outline: none;
            font-family: 'Poppins', sans-serif;
        }
        .input-group-custom input:focus { border-color: #F4845F; }
        .input-group-custom input::placeholder { color: #bbb; font-size: 0.82rem; }
 
        .btn-signup {
            background-color: #C0392B;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.65rem;
            font-size: 0.88rem;
            font-weight: 600;
            width: 100%;
            cursor: pointer;
            margin-top: 0.5rem;
            font-family: 'Poppins', sans-serif;
            transition: filter 0.2s;
        }
        .btn-signup:hover { filter: brightness(0.9); }
 
        .signin-link { text-align: center; margin-top: 1rem; font-size: 0.75rem; color: #888; }
        .signin-link a { color: #C0392B; text-decoration: none; font-weight: 600; }
 
        .alert { font-size: 0.82rem; border-radius: 8px; padding: 0.6rem 0.85rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
 
<div class="auth-wrapper">
 
    <div class="logo-section">
        <div class="logo-box">
            <span class="logo-emoji">🍑</span>
            <h2>Peachy</h2>
            <p>Cakes and Deli Cafe</p>
        </div>
    </div>
 
    <div class="auth-card">
        <h3>Create Account</h3>
        <p class="subtitle">Please fill in your account details</p>
 
        @if ($errors->any())
            <div class="alert alert-danger">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif
 
        <form action="{{ route('admin.register.post') }}" method="POST">
            @csrf
 
            <div class="input-group-custom">
                <i class="bi bi-hash"></i>
                <input type="text" name="code" placeholder="Code" value="{{ old('code') }}" required>
            </div>
 
            <div class="input-group-custom">
                <i class="bi bi-person"></i>
                <input type="text" name="username" placeholder="Username" value="{{ old('username') }}" required autofocus>
            </div>
 
            <div class="input-group-custom">
                <i class="bi bi-envelope"></i>
                <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required>
            </div>
 
            <div class="input-group-custom">
                <i class="bi bi-lock"></i>
                <input type="password" name="password" placeholder="Password" required>
            </div>
 
            <div class="input-group-custom">
                <i class="bi bi-lock-fill"></i>
                <input type="password" name="password_confirmation" placeholder="Confirm Password" required>
            </div>
 
            <button type="submit" class="btn-signup">Sign Up</button>
        </form>
 
        <div class="signin-link">
            Have an Account? <a href="{{ route('admin.login') }}">Sign In</a>
        </div>
    </div>
 
</div>
 
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>