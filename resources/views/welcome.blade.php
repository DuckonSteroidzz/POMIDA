<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peachy - Cakes and Deli Cafe</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
        body { background-color: #F4C2A8; min-height: 100vh; display: flex; justify-content: center; }
        .app-wrapper { width: 100%; max-width: 420px; min-height: 100vh; display: flex; flex-direction: column; align-items: center; position: relative; overflow: hidden; }

        /* Top Section */
        .top-section { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem 1rem 1rem; z-index: 2; }
        .logo-card { background: white; border-radius: 24px; padding: 2rem 3rem; text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,0.08); margin-bottom: 0.5rem; }
        .logo-emoji { font-size: 4rem; display: block; margin-bottom: 0.5rem; }
        .logo-text { font-size: 2rem; font-weight: 700; color: #333; font-style: italic; }
        .tagline { font-size: 0.85rem; color: #888; margin-top: 0.5rem; }

        /* Bottom Section - Envelope Style */
        .bottom-section { width: 100%; position: relative; padding: 0 1.5rem 3rem; z-index: 2; }

        /* Envelope flap */
        .envelope-flap { width: 0; height: 0; border-left: 210px solid transparent; border-right: 210px solid transparent; border-top: 80px solid #C0392B; margin: 0 auto; position: relative; z-index: 1; }

        /* Buttons Container */
        .buttons-container { background: #F4845F; border-radius: 0 0 20px 20px; padding: 1.5rem 1.5rem 2rem; margin-top: -2px; position: relative; z-index: 2; }

        .btn-dinein {
            display: block; width: 100%; padding: 0.85rem; border: none; border-radius: 50px;
            background: linear-gradient(135deg, #8B1A1A, #C0392B); color: white;
            font-size: 1rem; font-weight: 700; text-transform: uppercase; letter-spacing: 2px;
            cursor: pointer; font-family: 'Poppins', sans-serif; text-align: center; text-decoration: none;
            margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: transform 0.2s;
        }
        .btn-dinein:hover { transform: scale(1.02); }

        .btn-pickup {
            display: block; width: 100%; padding: 0.85rem; border: none; border-radius: 50px;
            background: linear-gradient(135deg, #e0e0e0, #f5f5f5); color: #333;
            font-size: 1rem; font-weight: 700; text-transform: uppercase; letter-spacing: 2px;
            cursor: pointer; font-family: 'Poppins', sans-serif; text-align: center; text-decoration: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .btn-pickup:hover { transform: scale(1.02); }

        /* Background decoration */
        .bg-circle {
            position: absolute; bottom: -50px; left: 50%; transform: translateX(-50%);
            width: 500px; height: 500px; border-radius: 50%;
            background: rgba(244,132,95,0.15); z-index: 0;
        }
    </style>
</head>

<body>
<div class="app-wrapper">
    <div class="bg-circle"></div>

    {{-- Top: Logo --}}
    <div class="top-section">
        <div class="logo-card">
            <span class="logo-emoji">🍑</span>
            <span class="logo-text">Peachy</span>
        </div>
        <p class="tagline">Cakes and Deli Cafe</p>
    </div>

    {{-- Bottom: Buttons --}}
    <div class="bottom-section">
        <div class="envelope-flap"></div>
        <div class="buttons-container">
            <a href="{{ route('customer.dineinqr') }}" class="btn-dinein">DINE IN</a>
            <a href="{{ route('customer.login') }}" class="btn-pickup">PICK UP</a>
        </div>
    </div>
</div>
</body>
</html>